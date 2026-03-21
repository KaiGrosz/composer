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
 * Resolves did:plc DIDs via the PLC Directory (https://plc.directory/).
 *
 * @author FAIR Contributors
 */
final class PlcDidResolver
{
    private const PLC_DIRECTORY_URL = 'https://plc.directory/';

    public function __construct(
        private readonly HttpDownloader $httpDownloader,
    ) {
    }

    /**
     * Resolve a DID synchronously. Used during installation-time verification
     * when the async loop is not available.
     */
    public function resolve(string $did): DidDocument
    {
        $this->validateDid($did);

        $response = $this->httpDownloader->get(self::PLC_DIRECTORY_URL . $did);
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('Failed to resolve DID %s: HTTP %d', $did, $statusCode));
        }

        return DidDocument::fromArray($response->decodeJson());
    }

    /**
     * Queue an async DID resolution request.
     *
     * Returns a Promise that resolves to a DidDocument. Use this inside
     * FairRepository::initialize() together with Loop::wait() to resolve
     * multiple DIDs concurrently.
     *
     * @return PromiseInterface<DidDocument>
     */
    public function addRequest(string $did): PromiseInterface
    {
        $this->validateDid($did);

        return $this->httpDownloader->add(self::PLC_DIRECTORY_URL . $did)
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

    private function validateDid(string $did): void
    {
        if (!str_starts_with($did, 'did:plc:')) {
            throw new \InvalidArgumentException(sprintf('Unsupported DID method: %s', $did));
        }
    }
}
