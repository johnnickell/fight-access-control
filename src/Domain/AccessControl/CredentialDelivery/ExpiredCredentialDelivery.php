<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Identity\UniqueId;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class ExpiredCredentialDelivery
 *
 * Identifies exact cleanup work without credentials, destinations or claim evidence.
 */
final readonly class ExpiredCredentialDelivery implements Arrayable
{
    /**
     * Constructs ExpiredCredentialDelivery
     */
    public function __construct(
        private string $purpose,
        private UniqueId $deliveryId,
        private UserId $userId,
        private ?EmailChangeGrantId $emailChangeGrantId,
        private DateTimeImmutable $expiresAt,
        private int $revision,
        private CredentialDeliveryStatus $status
    ) {
        $validIdentity = match ($purpose) {
            'activation' => $deliveryId instanceof ActivationDeliveryId && $emailChangeGrantId === null,
            'password_reset' => $deliveryId instanceof PasswordResetDeliveryId && $emailChangeGrantId === null,
            'email_change' => $deliveryId instanceof EmailChangeDeliveryId
                && $emailChangeGrantId instanceof EmailChangeGrantId,
            default => false,
        };
        if (!$validIdentity || $revision < 0) {
            throw new DomainException('Invalid expired credential-work identity.');
        }
    }

    /**
     * Creates exact work from its canonical secret-free representation
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (
            ['purpose', 'delivery_id', 'user_id', 'email_change_grant_id', 'expires_at', 'revision', 'status'] as $key
        ) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        foreach (['purpose', 'delivery_id', 'user_id', 'expires_at', 'status'] as $key) {
            if (!is_string($data[$key]) || $data[$key] === '') {
                throw new DomainException('Invalid expired credential-work value.');
            }
        }

        if (
            !is_int($data['revision'])
            || ($data['email_change_grant_id'] !== null && !is_string($data['email_change_grant_id']))
        ) {
            throw new DomainException('Invalid expired credential-work revision or grant identity.');
        }

        $deliveryId = match ($data['purpose']) {
            'activation' => ActivationDeliveryId::fromString((string) $data['delivery_id']),
            'password_reset' => PasswordResetDeliveryId::fromString((string) $data['delivery_id']),
            'email_change' => EmailChangeDeliveryId::fromString((string) $data['delivery_id']),
            default => throw new DomainException('Invalid expired credential-work purpose.'),
        };

        return new self(
            (string) $data['purpose'],
            $deliveryId,
            UserId::fromString((string) $data['user_id']),
            $data['email_change_grant_id'] === null ? null : EmailChangeGrantId::fromString(
                (string) $data['email_change_grant_id']
            ),
            CredentialDeliveryTimestamp::fromString((string) $data['expires_at'])->toDateTimeImmutable(),
            (int) $data['revision'],
            CredentialDeliveryStatus::from((string) $data['status'])
        );
    }

    /**
     * Returns the credential purpose selecting the exact cleanup command
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * Returns the exact delivery-generation identifier
     */
    public function getDeliveryId(): UniqueId
    {
        return $this->deliveryId;
    }

    /**
     * Returns the owning User identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the email authority identity needed by full expiry
     */
    public function getEmailChangeGrantId(): ?EmailChangeGrantId
    {
        return $this->emailChangeGrantId;
    }

    /**
     * Returns the inclusive delivery or authority expiry boundary
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the advisory observed aggregate revision
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Returns the safe delivery status independently of email authority
     */
    public function getStatus(): CredentialDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Returns the canonical secret-free representation
     */
    public function toArray(): array
    {
        return [
            'purpose'               => $this->purpose,
            'delivery_id'           => $this->deliveryId->toString(),
            'user_id'               => $this->userId->toString(),
            'email_change_grant_id' => $this->emailChangeGrantId?->toString(),
            'expires_at'            => $this->expiresAt->format('Y-m-d\TH:i:s.uP'),
            'revision'              => $this->revision,
            'status'                => $this->status->value
        ];
    }
}
