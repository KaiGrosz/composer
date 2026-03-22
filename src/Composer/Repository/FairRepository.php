<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Util\Fair\Cache;
use Composer\Util\Fair\DidDocument;
use Composer\Util\Fair\MetadataDocument;
use Composer\Util\Fair\MetadataFetcher;
use Composer\Util\Fair\PackageFactory;
use Composer\Util\Fair\DidResolver;
use Composer\Util\Fair\ReleaseDocument;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;

/**
 * Repository type that discovers packages via W3C Decentralized Identifiers (DIDs).
 *
 * Configuration accepted in composer.json:
 *
 *   { "type": "fair", "packages": { "vendor/name": "did:plc:..." } }
 *   { "type": "fair", "dids": ["did:plc:..."], "vendor": "acme" }
 *
 * DID documents and package metadata are cached on disk (cache-dir/fair/) to avoid
 * redundant network round-trips across Composer invocations.
 *
 * All HTTP requests issued during initialize() are batched and dispatched concurrently
 * via HttpDownloader's async queue + Loop::wait().
 *
 * @author FAIR Contributors
 */
final class FairRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $repoConfig;
    private IOInterface $io;
    private HttpDownloader $httpDownloader;
    private Config $composerConfig;

    /**
     * Constructor signature matches RepositoryManager::createRepository().
     *
     * @param array<string, mixed> $repoConfig
     */
    public function __construct(
        array            $repoConfig,
        IOInterface      $io,
        Config           $composerConfig,
        HttpDownloader   $httpDownloader,
        ?EventDispatcher $eventDispatcher = null,
        ?ProcessExecutor $process = null,
    )
    {
        parent::__construct();
        $this->repoConfig = $repoConfig;
        $this->io = $io;
        $this->httpDownloader = $httpDownloader;
        $this->composerConfig = $composerConfig;
    }

    public function getRepoName(): string
    {
        return 'fair (' . $this->count() . ' package' . ($this->count() !== 1 ? 's' : '') . ')';
    }

    /**
     * @return array<string, mixed>
     */
    public function getRepoConfig(): array
    {
        return $this->repoConfig;
    }

    /**
     * Lazy-initializes the package list.
     *
     * Executes in three phases:
     *   1. Concurrent DID resolution (all DIDs batched into a single Loop::wait())
     *   2. Concurrent metadata fetching  (all service endpoints batched similarly)
     *   3. Synchronous package object construction from resolved metadata
     */
    protected function initialize(): void
    {
        parent::initialize();

        $loop = new Loop($this->httpDownloader);
        $resolver = new DidResolver($this->httpDownloader);
        $fetcher = new MetadataFetcher($this->httpDownloader);
        $factory = new PackageFactory();
        $cache = new Cache($this->composerConfig);

        $didMap = $this->buildDidMap();
        if ($didMap === []) {
            return;
        }

        // ------------------------------------------------------------------
        // Phase 1: Resolve all DIDs concurrently
        // ------------------------------------------------------------------
        /** @var array<string, DidDocument> $didDocuments keyed by DID string */
        $didDocuments = [];
        /** @var array<int, \React\Promise\PromiseInterface<void>> $didPromises */
        $didPromises = [];

        foreach (array_unique(array_values($didMap)) as $did) {
            $cached = $cache->getDidDocument($did);
            if ($cached !== null) {
                try {
                    $didDocuments[$did] = DidDocument::fromArray(
                        json_decode($cached, true, 512, JSON_THROW_ON_ERROR),
                    );
                    $this->io->debug('FAIR: DID document cache hit for ' . $did);
                    continue;
                } catch (\Throwable) {
                    // Fall through and re-fetch below
                }
            }

            $this->io->debug('FAIR: Queuing DID resolution for ' . $did);
            $didPromises[] = $resolver->addRequest($did)
                ->then(
                    function (DidDocument $doc) use ($did, $cache, &$didDocuments): void {
                        $cache->setDidDocument($did, json_encode([
                            'id' => $doc->id,
                            'service' => array_map(static fn(object $s): array => (array)$s, $doc->service),
                            'verificationMethod' => array_map(static fn(object $v): array => (array)$v, $doc->verificationMethod),
                            'alsoKnownAs' => $doc->alsoKnownAs,
                        ], JSON_THROW_ON_ERROR));
                        $didDocuments[$did] = $doc;
                    },
                    function (\Throwable $e) use ($did): void {
                        $this->io->writeError(sprintf(
                            '<warning>FAIR: Failed to resolve DID %s: %s</warning>',
                            $did,
                            $e->getMessage(),
                        ));
                    },
                );
        }

        if ($didPromises !== []) {
            $this->io->debug(sprintf('FAIR: Resolving %d DID(s) concurrently', count($didPromises)));
            $loop->wait($didPromises);
        }

        // ------------------------------------------------------------------
        // Phase 2: Fetch metadata for each resolved DID concurrently
        // ------------------------------------------------------------------
        /** @var array<string, MetadataDocument> $metadataByEndpoint keyed by service endpoint URL */
        $metadataByEndpoint = [];
        /** @var array<int, \React\Promise\PromiseInterface<void>> $metadataPromises */
        $metadataPromises = [];

        foreach (array_unique(array_values($didMap)) as $did) {
            if (!isset($didDocuments[$did])) {
                continue;
            }
            $endpoint = $didDocuments[$did]->getServiceEndpoint();
            if ($endpoint === null) {
                $this->io->writeError(sprintf(
                    '<warning>FAIR: DID %s has no FairPackageManagementRepo service endpoint</warning>',
                    $did,
                ));
                continue;
            }
            if (isset($metadataByEndpoint[$endpoint])) {
                continue; // already queued or resolved
            }

            $cached = $cache->getMetadata($endpoint);
            if ($cached !== null) {
                try {
                    $metadataByEndpoint[$endpoint] = MetadataDocument::fromArray(
                        json_decode($cached, true, 512, JSON_THROW_ON_ERROR),
                    );
                    $this->io->debug('FAIR: Metadata cache hit for ' . $did);
                    continue;
                } catch (\Throwable) {
                    // Fall through and re-fetch
                }
            }

            $this->io->debug('FAIR: Queuing metadata fetch for ' . $did);
            $metadataPromises[] = $fetcher->addRequest($endpoint)
                ->then(
                    function (MetadataDocument $doc) use ($endpoint, $cache, &$metadataByEndpoint): void {
                        $cache->setMetadata($endpoint, json_encode([
                            'id' => $doc->id,
                            'type' => $doc->type,
                            'name' => $doc->name,
                            'slug' => $doc->slug,
                            'license' => $doc->license,
                            'description' => $doc->description,
                            'authors' => array_map(static fn(object $a): array => (array)$a, $doc->authors),
                            'keywords' => $doc->keywords,
                            'filename' => $doc->filename,
                            'last_updated' => $doc->lastUpdated,
                            'releases' => array_map(static fn(ReleaseDocument $r): array => [
                                'version' => $r->version,
                                'artifacts' => $r->artifacts,
                                'requires' => $r->requires,
                                'suggests' => $r->suggests,
                                'provides' => $r->provides,
                            ], $doc->releases),
                        ], JSON_THROW_ON_ERROR));
                        $metadataByEndpoint[$endpoint] = $doc;
                    },
                    function (\Throwable $e) use ($endpoint): void {
                        $this->io->writeError(sprintf(
                            '<warning>FAIR: Failed to fetch metadata from %s: %s</warning>',
                            $endpoint,
                            $e->getMessage(),
                        ));
                    },
                );
        }

        if ($metadataPromises !== []) {
            $this->io->debug(sprintf('FAIR: Fetching metadata from %d endpoint(s) concurrently', count($metadataPromises)));
            $loop->wait($metadataPromises);
        }

        // ------------------------------------------------------------------
        // Phase 3: Build Composer package objects
        // ------------------------------------------------------------------
        foreach ($didMap as $packageName => $did) {
            if (!isset($didDocuments[$did])) {
                continue;
            }
            $endpoint = $didDocuments[$did]->getServiceEndpoint();
            if ($endpoint === null || !isset($metadataByEndpoint[$endpoint])) {
                continue;
            }

            $metadata = $metadataByEndpoint[$endpoint];

            // When using DID-list config, the package name is auto-derived from the metadata slug
            if ($packageName === $did) {
                $vendor = is_string($this->repoConfig['vendor'] ?? null)
                    ? $this->repoConfig['vendor']
                    : 'fair';
                $packageName = $vendor . '/' . $metadata->slug;
            }

            try {
                $packages = $factory->createPackages($metadata, $packageName, $did);
                foreach ($packages as $package) {
                    $this->addPackage($package);
                }
                $this->io->debug(sprintf(
                    'FAIR: Loaded %d version(s) for %s from %s',
                    count($packages),
                    $packageName,
                    $did,
                ));
            } catch (\Throwable $e) {
                $this->io->writeError(sprintf(
                    '<warning>FAIR: Failed to build packages for %s (%s): %s</warning>',
                    $packageName,
                    $did,
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Build a map of package name => DID from the repository configuration.
     *
     * Supports two config styles:
     *   "packages": { "vendor/name": "did:plc:..." }   — explicit name mapping
     *   "dids":     ["did:plc:..."],  "vendor": "..."   — name derived from metadata slug
     *
     * @return array<string, string>
     */
    private function buildDidMap(): array
    {
        $map = [];

        if (isset($this->repoConfig['packages']) && is_array($this->repoConfig['packages'])) {
            foreach ($this->repoConfig['packages'] as $name => $did) {
                if (is_string($name) && is_string($did)) {
                    $map[$name] = $did;
                }
            }
        }

        if (isset($this->repoConfig['dids']) && is_array($this->repoConfig['dids'])) {
            foreach ($this->repoConfig['dids'] as $did) {
                if (is_string($did)) {
                    $map[$did] = $did; // temporary key, replaced with slug after metadata fetch
                }
            }
        }

        return $map;
    }
}
