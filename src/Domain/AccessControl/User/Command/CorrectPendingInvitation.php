<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class CorrectPendingInvitation
 *
 * Requests an authorized correction of a pending invitation.
 */
final readonly class CorrectPendingInvitation implements Command
{
    /**
     * Constructs CorrectPendingInvitation
     *
     * Creates a pending-invitation correction command.
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
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            EmailAddress::fromString($data['email'])
        );
    }

    /**
     * Returns the canonical invitation-correction payload
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
     * Returns the administrative actor
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the pending user
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the corrected destination
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
