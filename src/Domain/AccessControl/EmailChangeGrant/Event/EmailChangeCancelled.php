<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records that an email-change reservation and its authority were cancelled.
 */
final readonly class EmailChangeCancelled implements Event
{
    /**
     * Creates a secret-free cancellation event.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private EmailChangeGrantId $emailChangeGrantId,
        private DateTimeImmutable $cancelledAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email_change_grant_id', 'cancelled_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            EmailChangeGrantId::fromString((string) $data['email_change_grant_id']),
            new DateTimeImmutable((string) $data['cancelled_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'user_id' => $this->userId->toString(),
            'email_change_grant_id' => $this->emailChangeGrantId->toString(),
            'cancelled_at' => $this->cancelledAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the requesting actor identifier.
     */
    public function getActorId(): UserId
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
     * Returns the terminalized grant identifier.
     */
    public function getEmailChangeGrantId(): EmailChangeGrantId
    {
        return $this->emailChangeGrantId;
    }

    /**
     * Returns when cancellation completed.
     */
    public function getCancelledAt(): DateTimeImmutable
    {
        return $this->cancelledAt;
    }
}
