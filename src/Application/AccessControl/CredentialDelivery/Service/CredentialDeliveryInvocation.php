<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Class CredentialDeliveryInvocation
 *
 * Carries sensitive credential material only for one provider invocation.
 */
final readonly class CredentialDeliveryInvocation
{
    private SensitiveParameterValue $credential;

    /** @var 'activation'|'password_reset'|'email_change' */
    private string $purpose;

    /**
     * Constructs CredentialDeliveryInvocation
     */
    public function __construct(
        string $purpose,
        private string $idempotencyId,
        private EmailAddress $email,
        #[SensitiveParameter] string $credential,
        private ?ActivationOrigin $activationOrigin
    ) {
        if (!in_array($purpose, ['activation', 'password_reset', 'email_change'], true)) {
            throw new InvalidArgumentException('The credential delivery purpose is unsupported.');
        }

        if (($purpose === 'activation') !== ($activationOrigin instanceof ActivationOrigin)) {
            throw new InvalidArgumentException('Only activation delivery requires an activation origin.');
        }

        $this->purpose = $purpose;
        $this->credential = new SensitiveParameterValue($credential);
    }

    /**
     * Returns the credential purpose
     *
     * @return 'activation'|'password_reset'|'email_change'
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * Returns the stored activation journey or null for other credential purposes
     */
    public function getActivationOrigin(): ?ActivationOrigin
    {
        return $this->activationOrigin;
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
        /** @var string $credential */
        $credential = $this->credential->getValue();

        return $credential;
    }

    /**
     * Returns a diagnostic representation without raw credential material
     *
     * @return array{
     *     purpose: 'activation'|'password_reset'|'email_change',
     *     activationOrigin: ?ActivationOrigin,
     *     idempotencyId: string,
     *     email: EmailAddress,
     *     credential: string
     * }
     */
    public function __debugInfo(): array
    {
        return [
            'purpose'          => $this->purpose,
            'activationOrigin' => $this->activationOrigin,
            'idempotencyId'    => $this->idempotencyId,
            'email'            => $this->email,
            'credential'       => '[REDACTED]'
        ];
    }

    /**
     * Prevents raw credential material from being serialized into durable work
     */
    public function __serialize(): never
    {
        throw new LogicException('Credential-delivery invocations cannot be serialized.');
    }
}
