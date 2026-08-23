<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Requests mailbox-confirmed replacement of an active owner's canonical email.
 */
final readonly class RequestEmailChange implements Command
{
    /**
     * Creates an email-change request.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private EmailAddress $email
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            EmailAddress::fromString((string) $data['email'])
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
            'email' => $this->email->toString(),
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
     * Returns the active owner's stable identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the requested destination email.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
