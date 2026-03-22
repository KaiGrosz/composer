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
 * Resolves did:web DIDs by fetching a did.json document over HTTPS.
 *
 * Resolution rules per the W3C did:web spec:
 *   did:web:example.com              → https://example.com/.well-known/did.json
 *   did:web:example.com:path:to:pkg  → https://example.com/path/to/pkg/did.json
 *
 * AspireCloud uses this format:
 *   did:web:api.aspiredev.org:packages:typo3-extension:my-extension
 *   → https://api.aspiredev.org/packages/typo3-extension/my-extension/did.json
 *
 * @author FAIR Contributors
 */
final class WebDidResolver
{
    public function __construct(
        private readonly HttpDownloader $httpDownloader,
    ) {
    }

    /**
     * Resolve a did:web DID synchronously.
     */
    public function resolve(string $did): DidDocument
    {
        $this->validateDid($did);

        $url = $this->toUrl($did);
        $response = $this->httpDownloader->get($url);
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('Failed to resolve DID %s: HTTP %d', $did, $statusCode));
        }

        return DidDocument::fromArray($response->decodeJson());
    }

    /**
     * Queue an async did:web DID resolution request.
     *
     * @return PromiseInterface<DidDocument>
     */
    public function addRequest(string $did): PromiseInterface
    {
        $this->validateDid($did);

        $url = $this->toUrl($did);

        return $this->httpDownloader->add($url)
            ->then(static function (Response $response) use ($did): DidDocument {
                $statusCode = $response->getStatusCode();
                if ($statusCode !== 200) {
                    throw new \RuntimeException(sprintf(
                        'Failed to resolve DID %s: HTTP %d',
                        $did,
                        $statusCode,
                    ));
                }

                return DidDocument::fromArray($response->decodeJson());
            });
    }

    /**
     * Convert a did:web DID to its HTTPS document URL.
     *
     * did:web:example.com              → https://example.com/.well-known/did.json
     * did:web:example.com:path:to:pkg  → https://example.com/path/to/pkg/did.json
     */
    public function toUrl(string $did): string
    {
        $identifier = substr($did, strlen('did:web:'));
        $parts = explode(':', $identifier);
        $domain = array_shift($parts);

        if ($parts === []) {
            return 'https://' . $domain . '/.well-known/did.json';
        }

        return 'https://' . $domain . '/' . implode('/', array_map('rawurldecode', $parts)) . '/did.json';
    }

    private function validateDid(string $did): void
    {
        if (!str_starts_with($did, 'did:web:')) {
            throw new \InvalidArgumentException(sprintf('Unsupported DID method for WebDidResolver: %s', $did));
        }
    }
}
