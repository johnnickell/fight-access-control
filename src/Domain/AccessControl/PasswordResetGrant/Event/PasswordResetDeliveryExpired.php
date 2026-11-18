<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class PasswordResetDeliveryExpired
 *
 * Records terminal destruction of expired password-reset delivery ciphertext.
 */
final readonly class PasswordResetDeliveryExpired implements Event
{
    /**
     * Constructs PasswordResetDeliveryExpired
     *
     * Creates the secret-free terminal-expiry event.
     */
    public function __construct(
        private UserId|AgentId|SystemId|AnonymousId $actorId,
        private UserId $userId,
        private PasswordResetDeliveryId $passwordResetDeliveryId,
        private DateTimeImmutable $occurredAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_kind', 'actor_id', 'user_id', 'password_reset_delivery_id', 'occurred_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid type for "%s" in data array', $key));
            }
        }

        $kind = AuditActorKind::tryFrom($data['actor_kind']) ?? throw new DomainException('Unsupported actor kind.');

        return new static(
            $kind->idFromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            PasswordResetDeliveryId::fromString($data['password_reset_delivery_id']),
            CredentialDeliveryTimestamp::fromString($data['occurred_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical expiry event payload
     *
     * @phpstan-return array{
     *     actor_kind: string, actor_id: string, user_id: string,
     *     password_reset_delivery_id: string, occurred_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_kind'                 => AuditActorKind::forId($this->actorId)->value,
            'actor_id'                   => $this->actorId->toString(),
            'user_id'                    => $this->userId->toString(),
            'password_reset_delivery_id' => $this->passwordResetDeliveryId->toString(),
            'occurred_at'                => $this->occurredAt->format('Y-m-d\TH:i:s.uP')
        ];
    }

    /**
     * Returns the actor processing terminal expiry
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId
    {
        return $this->actorId;
    }

    /**
     * Returns the target user identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the expired delivery-generation identifier
     */
    public function getPasswordResetDeliveryId(): PasswordResetDeliveryId
    {
        return $this->passwordResetDeliveryId;
    }

    /**
     * Returns when terminal expiry was processed
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
