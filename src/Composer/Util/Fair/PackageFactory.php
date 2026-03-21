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

namespace Composer\Util\Fair;

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Version\VersionParser;

/**
 * Converts FAIR MetadataDocument + ReleaseDocument objects into Composer CompletePackage instances.
 *
 * FAIR packages are distributed as zip archives and use the 'fair-zip' dist type so
 * that Composer routes them to FairDownloader for checksum and signature verification
 * before extraction.
 *
 * @author FAIR Contributors
 */
final class PackageFactory
{
    /** @var array<string, string> Maps FAIR type slugs to Composer package types */
    private const TYPE_MAP = [
        'wp-plugin' => 'wordpress-plugin',
        'wp-theme'  => 'wordpress-theme',
    ];

    private  VersionParser $versionParser;

    public function __construct()
    {
        $this->versionParser = new VersionParser();
    }

    /**
     * Build CompletePackage instances for every release in the metadata document.
     * Releases with unparseable versions or missing dist URLs are silently skipped.
     *
     * @return list<CompletePackage>
     */
    public function createPackages(MetadataDocument $metadata, string $packageName, string $did): array
    {
        $packages = [];

        foreach ($metadata->releases as $release) {
            $package = $this->createPackage($metadata, $release, $packageName, $did);
            if ($package !== null) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    private function createPackage(
        MetadataDocument $metadata,
        ReleaseDocument $release,
        string $packageName,
        string $did,
    ): ?CompletePackage {
        $distUrl = $release->getPackageUrl();
        if ($distUrl === null) {
            return null;
        }

        try {
            $normalizedVersion = $this->versionParser->normalize($release->version);
        } catch (\UnexpectedValueException) {
            return null;
        }

        $package = new CompletePackage($packageName, $normalizedVersion, $release->version);

        $package->setDistUrl($distUrl);
        // Use 'fair-zip' so DownloadManager routes this package to FairDownloader,
        // which performs checksum + Ed25519 verification before extraction.
        $package->setDistType('fair-zip');

        $composerType = self::TYPE_MAP[$metadata->type] ?? 'library';
        $package->setType($composerType);

        $package->setLicense([$metadata->license]);

        if ($metadata->description !== null) {
            $package->setDescription($metadata->description);
        }

        if ($metadata->keywords !== []) {
            $package->setKeywords($metadata->keywords);
        }

        $authors = [];
        foreach ($metadata->authors as $author) {
            $entry = ['name' => $author->name];
            if (isset($author->url)) {
                $entry['homepage'] = $author->url;
            }
            $authors[] = $entry;
        }
        if ($authors !== []) {
            $package->setAuthors($authors);
        }

        $requires = $this->buildRequires($packageName, $release);
        if ($requires !== []) {
            $package->setRequires($requires);
        }

        // Embed FAIR-specific metadata in extras so FairDownloader can read them at install time.
        $package->setExtra([
            'fair' => [
                'did'       => $did,
                'checksum'  => $release->getPackageChecksum(),
                'signature' => $release->getPackageSignature(),
            ],
        ]);

        return $package;
    }

    /**
     * @return array<string, Link>
     */
    private function buildRequires(string $packageName, ReleaseDocument $release): array
    {
        $links = [];

        foreach ($release->requires as $key => $constraintString) {
            if ($key === 'env:php') {
                try {
                    $constraint = $this->versionParser->parseConstraints($constraintString);
                } catch (\UnexpectedValueException) {
                    continue;
                }
                $links['php'] = new Link($packageName, 'php', $constraint, Link::TYPE_REQUIRE, $constraintString);
            }
        }

        return $links;
    }
}
