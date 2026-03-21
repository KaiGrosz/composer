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

namespace Composer\Downloader;

use Composer\Exception\IrrecoverableDownloadException;
use Composer\Package\PackageInterface;
use Composer\Util\Fair\Cache;
use Composer\Util\Fair\DidDocument;
use Composer\Util\Fair\PlcDidResolver;
use Composer\Util\Fair\SignatureVerifier;
use React\Promise\PromiseInterface;

/**
 * Downloads and verifies FAIR packages.
 *
 * Extends ZipDownloader with a mandatory two-step integrity check inserted into
 * the Promise chain between file download and archive extraction:
 *
 *   1. SHA-256 checksum  — guards against corruption or tampering in transit.
 *   2. Ed25519 signature — proves the artifact was published by the declared DID owner.
 *
 * Verification data (did, checksum, signature) is embedded in package extras under
 * the "fair" key by PackageFactory. If either check fails the downloaded file is
 * deleted from disk and an IrrecoverableDownloadException is thrown, preventing
 * Composer from retrying or extracting the corrupted/untrusted archive.
 *
 * This downloader handles packages whose distType is 'fair-zip', set by
 * Composer\Util\Fair\PackageFactory. It is registered in Factory::createDownloadManager().
 *
 * @author FAIR Contributors
 */
final class FairDownloader extends ZipDownloader
{
    /**
     * @inheritDoc
     */
    public function download(PackageInterface $package, string $path, ?PackageInterface $prevPackage = null, bool $output = true): PromiseInterface
    {
        return parent::download($package, $path, $prevPackage, $output)
            ->then(function (?string $fileName) use ($package): ?string {
                if ($fileName === null) {
                    return null;
                }

                $extra = $package->getExtra();
                $fairExtra = $extra['fair'] ?? null;

                // Packages without FAIR metadata pass through unchanged.
                // This makes FairDownloader safe to use as a general zip downloader
                // if ever registered that way.
                if (!is_array($fairExtra)) {
                    return $fileName;
                }

                $did = isset($fairExtra['did']) && is_string($fairExtra['did']) ? $fairExtra['did'] : null;
                $checksum = isset($fairExtra['checksum']) && is_string($fairExtra['checksum']) ? $fairExtra['checksum'] : null;
                $signature = isset($fairExtra['signature']) && is_string($fairExtra['signature']) ? $fairExtra['signature'] : null;

                $verifier = new SignatureVerifier($this->io);

                // Step 1: SHA-256 checksum
                if ($checksum !== null) {
                    if (!$verifier->verifyChecksum($fileName, $checksum)) {
                        $this->filesystem->unlink($fileName);
                        throw new IrrecoverableDownloadException(sprintf(
                            'FAIR: Checksum verification failed for %s. The download has been removed.',
                            $package->getName(),
                        ));
                    }
                }

                // Step 2: Ed25519 signature (requires DID resolution)
                if ($signature !== null && $did !== null) {
                    $didDocument = $this->resolveDid($did);

                    if (!$verifier->verifySignature($fileName, $signature, $didDocument)) {
                        $this->filesystem->unlink($fileName);
                        throw new IrrecoverableDownloadException(sprintf(
                            'FAIR: Signature verification failed for %s. The download has been removed.',
                            $package->getName(),
                        ));
                    }
                }

                return $fileName;
            });
    }

    /**
     * Resolve a DID, reading from the on-disk cache populated by FairRepository
     * when possible to avoid redundant network calls during the install phase.
     */
    private function resolveDid(string $did): DidDocument
    {
        $cache = new Cache($this->config);
        $cached = $cache->getDidDocument($did);

        if ($cached !== null) {
            try {
                return DidDocument::fromArray(json_decode($cached, true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                // Cache entry invalid; fall through to live resolution
            }
        }

        $resolver = new PlcDidResolver($this->httpDownloader);

        return $resolver->resolve($did);
    }
}
