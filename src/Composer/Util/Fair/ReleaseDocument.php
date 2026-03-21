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
 * Represents a single versioned release entry within a FAIR MetadataDocument.
 *
 * Artifacts structure expected:
 *   { "package": [{ "url": "...", "signature": "...", "checksum": "sha256:...", "content-type": "..." }] }
 *
 * @author FAIR Contributors
 *
 * @phpstan-type ArtifactObject object{package?: list<object{url: string, signature?: string, checksum?: string, 'content-type'?: string}>}
 */
final class ReleaseDocument
{
    /**
     * @param object{package?: list<object{url: string, signature?: string, checksum?: string, 'content-type'?: string}>} $artifacts
     * @param array<string, string> $requires
     * @param array<string, string> $suggests
     * @param list<mixed>           $provides
     */
    public function __construct(
        public readonly string $version,
        public readonly object $artifacts,
        public readonly array $requires = [],
        public readonly array $suggests = [],
        public readonly array $provides = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['version']) || !is_string($data['version'])) {
            throw new \InvalidArgumentException('Missing mandatory field: version');
        }

        if (!isset($data['artifacts'])) {
            throw new \InvalidArgumentException('Missing mandatory field: artifacts');
        }

        $artifacts = is_array($data['artifacts']) ? (object) $data['artifacts'] : $data['artifacts'];

        if (isset($artifacts->package) && is_array($artifacts->package)) {
            $artifacts->package = array_map(
                static fn (mixed $pkg): object => is_array($pkg) ? (object) $pkg : $pkg,
                $artifacts->package,
            );
        }

        return new self(
            version: $data['version'],
            artifacts: $artifacts,
            requires: (array) ($data['requires'] ?? []),
            suggests: (array) ($data['suggests'] ?? []),
            provides: (array) ($data['provides'] ?? []),
        );
    }

    public function getPackageUrl(): ?string
    {
        if (!isset($this->artifacts->package) || !is_array($this->artifacts->package)) {
            return null;
        }

        $first = $this->artifacts->package[0] ?? null;

        return $first?->url ?? null;
    }

    public function getPackageChecksum(): ?string
    {
        if (!isset($this->artifacts->package) || !is_array($this->artifacts->package)) {
            return null;
        }

        $first = $this->artifacts->package[0] ?? null;

        return $first?->checksum ?? null;
    }

    public function getPackageSignature(): ?string
    {
        if (!isset($this->artifacts->package) || !is_array($this->artifacts->package)) {
            return null;
        }

        $first = $this->artifacts->package[0] ?? null;

        return $first?->signature ?? null;
    }
}
