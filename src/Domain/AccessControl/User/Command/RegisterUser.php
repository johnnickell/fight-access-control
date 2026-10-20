<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class RegisterUser
 *
 * Represents consumer-admitted registration, not an authenticated actor's instruction.
 */
final readonly class RegisterUser implements Command
{
    /**
     * Constructs RegisterUser
     */
    public function __construct(private UserId $userId, private EmailAddress $email)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (
            count($data) !== 2
            || !isset($data['user_id'], $data['email'])
            || !is_string($data['user_id'])
            || !is_string($data['email'])
        ) {
            throw new DomainException('Registration requires only a User ID and email address.');
        }

        return new static(UserId::fromString($data['user_id']), EmailAddress::fromString($data['email']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['user_id' => $this->userId->toString(), 'email' => $this->email->toString()];
    }

    /**
     * Returns the new pending User identity
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the requested email address
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
