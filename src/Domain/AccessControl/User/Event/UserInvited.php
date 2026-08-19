<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class UserInvited
 */
final readonly class UserInvited implements Event
{
    /**
     * Constructs UserInvited
     */
    public function __construct(
        private string $actorId,
        private UserId $userId,
        private EmailAddress $email,
        private DateTimeImmutable $issuedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email', 'issued_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            (string) $data['actor_id'],
            UserId::fromString((string) $data['user_id']),
            EmailAddress::fromString((string) $data['email']),
            new DateTimeImmutable((string) $data['issued_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id'  => $this->actorId,
            'user_id'   => $this->userId->toString(),
            'email'     => $this->email->toString(),
            'issued_at' => $this->issuedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Retrieves the inviting actor ID
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Retrieves the invited user ID
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Retrieves the invited email address
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Retrieves the invitation issuance time
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
