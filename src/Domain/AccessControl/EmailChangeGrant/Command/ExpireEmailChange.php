<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ExpireEmailChange
 *
 * Processes terminal expiry for one exact email-change generation.
 */
final readonly class ExpireEmailChange implements Command
{
    /**
     * Constructs ExpireEmailChange
     *
     * Creates an invocation-neutral expiry command.
     */
    public function __construct(
        private string $actorId,
        private UserId $userId,
        private EmailChangeGrantId $emailChangeGrantId,
        private DateTimeImmutable $occurredAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email_change_grant_id', 'occurred_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            (string) $data['actor_id'],
            UserId::fromString((string) $data['user_id']),
            EmailChangeGrantId::fromString((string) $data['email_change_grant_id']),
            CredentialDeliveryTimestamp::fromString((string) $data['occurred_at'])->toDateTimeImmutable()
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id'              => $this->actorId,
            'user_id'               => $this->userId->toString(),
            'email_change_grant_id' => $this->emailChangeGrantId->toString(),
            'occurred_at'           => $this->occurredAt->format('Y-m-d\TH:i:s.uP')
        ];
    }

    /**
     * Returns the actor processing terminal expiry
     */
    public function getActorId(): string
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
     * Returns the exact grant-generation identifier
     */
    public function getEmailChangeGrantId(): EmailChangeGrantId
    {
        return $this->emailChangeGrantId;
    }

    /**
     * Returns when terminal expiry was processed
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
