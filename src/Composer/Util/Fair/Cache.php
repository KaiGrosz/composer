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

use Composer\Config;

/**
 * File-based TTL cache for FAIR DID documents and package metadata.
 *
 * Cache entries are stored as JSON files under <cache-dir>/fair/{type}/{sha256(key)}.json.
 * DID documents are cached for 1 hour; metadata for 5 minutes.
 *
 * @author FAIR Contributors
 */
final class Cache
{
    private const DID_DOCUMENT_TTL = 3600;
    private const METADATA_TTL = 300;

    private readonly string $cacheDir;

    public function __construct(Config $config)
    {
        $this->cacheDir = rtrim((string) $config->get('cache-dir'), '/') . '/fair';
    }

    public function getDidDocument(string $did): ?string
    {
        return $this->get('did', $did, self::DID_DOCUMENT_TTL);
    }

    public function setDidDocument(string $did, string $json): void
    {
        $this->set('did', $did, $json);
    }

    public function getMetadata(string $endpoint): ?string
    {
        return $this->get('metadata', $endpoint, self::METADATA_TTL);
    }

    public function setMetadata(string $endpoint, string $json): void
    {
        $this->set('metadata', $endpoint, $json);
    }

    private function get(string $type, string $key, int $ttl): ?string
    {
        $file = $this->getPath($type, $key);
        if (!file_exists($file)) {
            return null;
        }

        $mtime = filemtime($file);
        if ($mtime === false || (time() - $mtime) > $ttl) {
            @unlink($file);

            return null;
        }

        $content = file_get_contents($file);

        return $content !== false ? $content : null;
    }

    private function set(string $type, string $key, string $data): void
    {
        $file = $this->getPath($type, $key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($file, $data);
    }

    private function getPath(string $type, string $key): string
    {
        return $this->cacheDir . '/' . $type . '/' . hash('sha256', $key) . '.json';
    }
}
