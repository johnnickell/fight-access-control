<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\Common\Domain\Value\Internet\EmailAddress;

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
        private string $credential
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
}
