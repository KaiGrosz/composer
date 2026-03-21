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
 * Represents a W3C Decentralized Identifier (DID) Document for FAIR package management.
 *
 * @author FAIR Contributors
 *
 * @phpstan-type ServiceObject object{id: string, type: string, serviceEndpoint: string}
 * @phpstan-type VerificationMethodObject object{id: string, type: string, controller: string, publicKeyMultibase: string}
 */
final class DidDocument
{
    private const SERVICE_TYPE = 'FairPackageManagementRepo';

    /**
     * @param list<object{id: string, type: string, serviceEndpoint: string}> $service
     * @param list<object{id: string, type: string, controller: string, publicKeyMultibase: string}> $verificationMethod
     * @param list<string> $alsoKnownAs
     */
    public function __construct(
        public  string $id,
        public  array $service,
        public  array $verificationMethod,
        public  array $alsoKnownAs = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !is_string($data['id'])) {
            throw new \InvalidArgumentException('DID Document must contain an "id" string');
        }

        $service = [];
        foreach ($data['service'] ?? [] as $svc) {
            $service[] = (object) $svc;
        }

        $verificationMethod = [];
        foreach ($data['verificationMethod'] ?? [] as $vm) {
            $verificationMethod[] = (object) $vm;
        }

        return new self(
            id: $data['id'],
            service: $service,
            verificationMethod: $verificationMethod,
            alsoKnownAs: $data['alsoKnownAs'] ?? [],
        );
    }

    /**
     * Returns the FAIR package management service endpoint URL, or null if not present.
     */
    public function getServiceEndpoint(): ?string
    {
        foreach ($this->service as $svc) {
            if ($svc->type === self::SERVICE_TYPE) {
                return $svc->serviceEndpoint;
            }
        }

        return null;
    }

    /**
     * Returns verification methods whose fragment IDs begin with "fair".
     * These are the keys used for Ed25519 package signature verification.
     *
     * @return list<object{id: string, type: string, publicKeyMultibase: string}>
     */
    public function getFairSigningKeys(): array
    {
        return array_values(array_filter(
            $this->verificationMethod,
            static function (object $key): bool {
                if ($key->type !== 'Multikey') {
                    return false;
                }

                $parsed = parse_url($key->id);

                return isset($parsed['fragment']) && str_starts_with($parsed['fragment'], 'fair');
            }
        ));
    }
}
