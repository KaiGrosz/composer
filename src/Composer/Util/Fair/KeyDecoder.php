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

/**
 * Decodes Base58BTC multibase-encoded Ed25519 public keys from FAIR DID Documents.
 *
 * Keys are encoded as Multikey values with a 'z' multibase prefix followed by
 * Base58BTC-encoded bytes that begin with the Ed25519 multicodec prefix (0xed 0x01).
 *
 * @author FAIR Contributors
 */
final class KeyDecoder
{
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const MULTIBASE_PREFIX = 'z';
    private const ED25519_MULTICODEC_PREFIX = "\xed\x01";

    /**
     * Decode a Base58BTC multibase-encoded Ed25519 public key to 32 raw bytes.
     *
     * @throws \InvalidArgumentException if the key is malformed or not an Ed25519 key
     */
    public static function decodeEd25519PublicKey(string $multibase): string
    {
        $decoded = self::decodeBase58Btc($multibase);

        if (!str_starts_with($decoded, self::ED25519_MULTICODEC_PREFIX)) {
            throw new \InvalidArgumentException('Key is not an Ed25519 multicodec key');
        }

        $raw = substr($decoded, 2);
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Ed25519 key length: expected %d bytes, got %d',
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                strlen($raw),
            ));
        }

        return $raw;
    }

    /**
     * Decode a Base58BTC multibase string (strips leading 'z' multibase marker if present).
     */
    private static function decodeBase58Btc(string $data): string
    {
        if (str_starts_with($data, self::MULTIBASE_PREFIX)) {
            $data = substr($data, 1);
        }

        $bytes = array_map(
            static fn (string $char): int => strpos(self::BASE58_ALPHABET, $char),
            str_split($data),
        );

        foreach ($bytes as $pos => $byte) {
            if ($byte === false) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid Base58BTC character at position %d',
                    $pos,
                ));
            }
        }

        $leadingZeroes = 0;
        while ($bytes !== [] && $bytes[0] === 0) {
            $leadingZeroes++;
            array_shift($bytes);
        }

        $converted = self::convertBase($bytes, 58, 256);

        if ($leadingZeroes > 0) {
            $converted = array_merge(array_fill(0, $leadingZeroes, 0), $converted);
        }

        return implode('', array_map('chr', $converted));
    }

    /**
     * @param array<int, int> $source
     *
     * @return array<int, int>
     */
    private static function convertBase(array $source, int $sourceBase, int $targetBase): array
    {
        $result = [];
        while (($count = count($source)) > 0) {
            $quotient = [];
            $remainder = 0;
            for ($i = 0; $i < $count; $i++) {
                $accumulator = $source[$i] + $remainder * $sourceBase;
                $digit = intdiv($accumulator, $targetBase);
                $remainder = $accumulator % $targetBase;
                if ($quotient !== [] || $digit > 0) {
                    $quotient[] = $digit;
                }
            }
            array_unshift($result, $remainder);
            $source = $quotient;
        }

        return $result;
    }
}
