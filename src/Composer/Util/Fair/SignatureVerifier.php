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

use Composer\IO\IOInterface;

/**
 * Verifies the integrity and authenticity of downloaded FAIR packages.
 *
 * Two checks are performed:
 *   1. SHA-256 checksum — ensures the file was not corrupted in transit.
 *   2. Ed25519 signature — proves the file was signed by the DID owner.
 *
 * The signature covers the SHA-384 binary hash of the file (matching the FAIR
 * reference implementation). It is Base64URL-encoded without padding in metadata.
 *
 * @author FAIR Contributors
 */
final class SignatureVerifier
{
    public function __construct(
        private readonly IOInterface $io,
    ) {
    }

    /**
     * Verify the SHA-256 checksum of a downloaded file.
     *
     * @param string $checksum Expected checksum in "sha256:{hex}" format
     */
    public function verifyChecksum(string $filePath, string $checksum): bool
    {
        if (!str_starts_with($checksum, 'sha256:')) {
            $this->io->writeError('<warning>FAIR: Unsupported checksum format: ' . $checksum . '</warning>');

            return false;
        }

        $expectedHash = substr($checksum, 7);
        $actualHash = hash_file('sha256', $filePath);

        if ($actualHash !== $expectedHash) {
            $this->io->writeError(sprintf(
                '<error>FAIR: Checksum mismatch for %s: expected %s, got %s</error>',
                basename($filePath),
                $expectedHash,
                $actualHash,
            ));

            return false;
        }

        $this->io->debug('FAIR: SHA-256 checksum verified');

        return true;
    }

    /**
     * Verify the Ed25519 signature of a downloaded file against the DID's signing keys.
     *
     * The signature is Base64URL-encoded (no padding). The signed payload is the
     * raw binary SHA-384 digest of the file, matching the FAIR WordPress reference impl.
     */
    public function verifySignature(string $filePath, string $signatureBase64Url, DidDocument $didDocument): bool
    {
        $signingKeys = $didDocument->getFairSigningKeys();
        if ($signingKeys === []) {
            $this->io->writeError('<error>FAIR: No signing keys found in DID document</error>');

            return false;
        }

        $signature = sodium_base642bin($signatureBase64Url, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $fileHash = hash_file('sha384', $filePath, true);

        foreach ($signingKeys as $key) {
            try {
                $publicKey = KeyDecoder::decodeEd25519PublicKey($key->publicKeyMultibase);
            } catch (\InvalidArgumentException $e) {
                $this->io->debug('FAIR: Skipping key ' . $key->id . ': ' . $e->getMessage());
                continue;
            }

            if (sodium_crypto_sign_verify_detached($signature, $fileHash, $publicKey)) {
                $this->io->debug('FAIR: Ed25519 signature verified with key ' . $key->id);

                return true;
            }
        }

        $this->io->writeError('<error>FAIR: Signature verification failed — no key could verify the signature</error>');

        return false;
    }
}
