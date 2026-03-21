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

use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use React\Promise\PromiseInterface;

/**
 * Fetches FAIR package metadata from a service endpoint declared in a DID Document.
 *
 * @author FAIR Contributors
 */
final class MetadataFetcher
{
    private const ACCEPT_HEADER = 'application/json+fair;q=1.0, application/json;q=0.8';

    public function __construct(
        private  HttpDownloader $httpDownloader,
    ) {
    }

    /**
     * Fetch metadata synchronously (used during install-time verification).
     */
    public function fetch(string $serviceEndpoint): MetadataDocument
    {
        $response = $this->httpDownloader->get($serviceEndpoint, [
            'http' => [
                'header' => ['Accept: ' . self::ACCEPT_HEADER],
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf(
                'Failed to fetch FAIR metadata from %s: HTTP %d',
                $serviceEndpoint,
                $statusCode,
            ));
        }

        return MetadataDocument::fromArray($response->decodeJson());
    }

    /**
     * Queue an async metadata fetch request.
     *
     * Returns a Promise that resolves to a MetadataDocument. Use this inside
     * FairRepository::initialize() together with Loop::wait() for concurrent fetching.
     *
     * @return PromiseInterface<MetadataDocument>
     */
    public function addRequest(string $serviceEndpoint): PromiseInterface
    {
        return $this->httpDownloader->add($serviceEndpoint, [
            'http' => [
                'header' => ['Accept: ' . self::ACCEPT_HEADER],
            ],
        ])->then(static function (Response $response) use ($serviceEndpoint): MetadataDocument {
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException(sprintf(
                    'Failed to fetch FAIR metadata from %s: HTTP %d',
                    $serviceEndpoint,
                    $statusCode,
                ));
            }

            return MetadataDocument::fromArray($response->decodeJson());
        });
    }
}
