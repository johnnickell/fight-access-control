<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class CancelEmailChange
 *
 * Requests cancellation of one identified email change for the target User.
 */
final readonly class CancelEmailChange implements Command
{
    /**
     * Constructs CancelEmailChange
     *
     * Creates an email-change cancellation.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private EmailChangeGrantId $emailChangeGrantId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email_change_grant_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid type for "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            EmailChangeGrantId::fromString($data['email_change_grant_id'])
        );
    }

    /**
     * Returns the canonical cancellation command payload
     *
     * @phpstan-return array{actor_id: string, user_id: string, email_change_grant_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'              => $this->actorId->toString(),
            'user_id'               => $this->userId->toString(),
            'email_change_grant_id' => $this->emailChangeGrantId->toString()
        ];
    }

    /**
     * Returns the requesting actor identifier
     */
    public function getActorId(): UserId
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
     * Returns the exact request identifier
     */
    public function getEmailChangeGrantId(): EmailChangeGrantId
    {
        return $this->emailChangeGrantId;
    }
}
