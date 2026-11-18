<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class DeliverPasswordReset
 *
 * Invokes one exact password-reset delivery generation.
 */
final readonly class DeliverPasswordReset implements Command
{
    /**
     * Constructs DeliverPasswordReset
     */
    public function __construct(
        private UserId|AgentId|SystemId|AnonymousId $actorId,
        private UserId $userId,
        private PasswordResetDeliveryId $passwordResetDeliveryId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_kind', 'actor_id', 'user_id', 'password_reset_delivery_id'] as $key) {
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
            PasswordResetDeliveryId::fromString($data['password_reset_delivery_id'])
        );
    }

    /**
     * Returns the canonical delivery command payload
     *
     * @phpstan-return array{actor_kind: string, actor_id: string, user_id: string, password_reset_delivery_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_kind'                 => AuditActorKind::forId($this->actorId)->value,
            'actor_id'                   => $this->actorId->toString(),
            'user_id'                    => $this->userId->toString(),
            'password_reset_delivery_id' => $this->passwordResetDeliveryId->toString()
        ];
    }

    /**
     * Returns the actor that caused delivery invocation
     */
    public function getActorId(): UserId|AgentId|SystemId|AnonymousId
    {
        return $this->actorId;
    }

    /**
     * Returns the owning User identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the exact delivery-generation identifier
     */
    public function getPasswordResetDeliveryId(): PasswordResetDeliveryId
    {
        return $this->passwordResetDeliveryId;
    }
}
