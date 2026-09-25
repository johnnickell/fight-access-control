<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use SensitiveParameter;

/**
 * Class CredentialDeliveryInvocation
 *
 * Carries sensitive credential material only for one provider invocation.
 */
final readonly class CredentialDeliveryInvocation
{
    /**
     * Constructs CredentialDeliveryInvocation
     */
    public function __construct(
        private string $purpose,
        private string $idempotencyId,
        private EmailAddress $email,
        #[SensitiveParameter] private string $credential
    ) {
    }

    /**
     * Returns the credential purpose
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * Returns the stable delivery-generation idempotency identity
     */
    public function getIdempotencyId(): string
    {
        return $this->idempotencyId;
    }

    /**
     * Returns the canonical delivery destination
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns short-lived raw credential material
     */
    public function getCredential(): string
    {
        return $this->credential;
    }

    /**
     * Returns a diagnostic representation without raw credential material
     *
     * @return array{
     *     purpose: string,
     *     idempotencyId: string,
     *     email: EmailAddress,
     *     credential: string
     * }
     */
    public function __debugInfo(): array
    {
        return [
            'purpose'       => $this->purpose,
            'idempotencyId' => $this->idempotencyId,
            'email'         => $this->email,
            'credential'    => '[REDACTED]'
        ];
    }

    /**
     * Prevents raw credential material from being serialized into durable work
     */
    public function __serialize(): array
    {
        throw new LogicException('Credential-delivery invocations cannot be serialized.');
    }
}
