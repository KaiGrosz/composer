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
 * Represents the top-level FAIR package metadata document returned by a service endpoint.
 *
 * @author FAIR Contributors
 */
final class MetadataDocument
{
    /**
     * @param list<ReleaseDocument>                        $releases
     * @param list<object{name: string, url?: string}>     $authors
     * @param list<string>                                 $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $license,
        public readonly array $releases,
        public readonly array $authors = [],
        public readonly array $keywords = [],
        public readonly ?string $description = null,
        public readonly ?string $filename = null,
        public readonly ?string $lastUpdated = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'type', 'name', 'slug', 'license'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new \InvalidArgumentException(sprintf('Missing mandatory field: %s', $field));
            }
        }

        $releases = [];
        foreach ($data['releases'] ?? [] as $releaseData) {
            $releases[] = ReleaseDocument::fromArray((array) $releaseData);
        }

        if ($releases === []) {
            throw new \InvalidArgumentException('Metadata document must contain at least one release');
        }

        $authors = [];
        foreach ($data['authors'] ?? [] as $author) {
            $authors[] = (object) $author;
        }

        return new self(
            id: $data['id'],
            type: $data['type'],
            name: $data['name'],
            slug: $data['slug'],
            license: $data['license'],
            releases: $releases,
            authors: $authors,
            keywords: $data['keywords'] ?? [],
            description: $data['description'] ?? null,
            filename: $data['filename'] ?? null,
            lastUpdated: $data['last_updated'] ?? null,
        );
    }
}
