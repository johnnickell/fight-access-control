<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class InvitePendingUser
 */
final readonly class InvitePendingUser implements Command
{
    /**
     * Constructs InvitePendingUser
     */
    public function __construct(
        private string $actorId,
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
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            (string) $data['actor_id'],
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
            'actor_id' => $this->actorId,
            'user_id'  => $this->userId->toString(),
            'email'    => $this->email->toString(),
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
     * Retrieves the pending user ID
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
}
