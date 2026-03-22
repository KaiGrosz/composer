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

use Composer\Util\HttpDownloader;
use React\Promise\PromiseInterface;

/**
 * Dispatches DID resolution to the correct method-specific resolver.
 *
 * Supported DID methods:
 *   did:plc:  — resolved via the PLC Directory (https://plc.directory/)
 *   did:web:  — resolved via HTTPS did.json documents (W3C did:web spec)
 *
 * This is the single entry point for all DID resolution in FAIR. Use this
 * class everywhere instead of PlcDidResolver or WebDidResolver directly.
 *
 * @author FAIR Contributors
 */
final class DidResolver
{
    public function __construct(
        private readonly HttpDownloader $httpDownloader,
    ) {
    }

    /**
     * Resolve any supported DID synchronously.
     *
     * Used during installation-time verification where async is not available.
     */
    public function resolve(string $did): DidDocument
    {
        return $this->getResolver($did)->resolve($did);
    }

    /**
     * Queue an async DID resolution request.
     *
     * Returns a Promise that resolves to a DidDocument. Use together with
     * Loop::wait() in FairRepository::initialize() for concurrent resolution.
     *
     * @return PromiseInterface<DidDocument>
     */
    public function addRequest(string $did): PromiseInterface
    {
        return $this->getResolver($did)->addRequest($did);
    }

    /**
     * Returns true if the given string looks like a supported DID.
     */
    public static function isSupported(string $did): bool
    {
        return str_starts_with($did, 'did:plc:') || str_starts_with($did, 'did:web:');
    }

    private function getResolver(string $did): PlcDidResolver|WebDidResolver
    {
        if (str_starts_with($did, 'did:plc:')) {
            return new PlcDidResolver($this->httpDownloader);
        }

        if (str_starts_with($did, 'did:web:')) {
            return new WebDidResolver($this->httpDownloader);
        }

        throw new \InvalidArgumentException(sprintf(
            'Unsupported DID method: %s. Supported methods: did:plc:, did:web:',
            $did,
        ));
    }
}
