<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class RequestEmailChange
 *
 * Requests mailbox-confirmed replacement of an active owner's canonical email.
 */
final readonly class RequestEmailChange implements Command
{
    /**
     * Constructs RequestEmailChange
     *
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

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid type for "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            EmailAddress::fromString($data['email'])
        );
    }

    /**
     * Returns the canonical email request payload
     *
     * @phpstan-return array{actor_id: string, user_id: string, email: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'user_id'  => $this->userId->toString(),
            'email'    => $this->email->toString()
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
     * Returns the active owner's stable identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the requested destination email
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
