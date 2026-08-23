<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records terminal expiry of an email-change reservation and grant.
 */
final readonly class EmailChangeExpired implements Event
{
    /**
     * Creates a secret-free expiry event.
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
            new DateTimeImmutable((string) $data['occurred_at'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId,
            'user_id' => $this->userId->toString(),
            'email_change_grant_id' => $this->emailChangeGrantId->toString(),
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the actor processing terminal expiry.
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the target user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the expired grant-generation identifier.
     */
    public function getEmailChangeGrantId(): EmailChangeGrantId
    {
        return $this->emailChangeGrantId;
    }

    /**
     * Returns when terminal expiry completed.
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
