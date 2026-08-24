<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Provides a safe immutable view of a user identity.
 */
final readonly class UserView implements Arrayable
{
    /**
     * Constructs the safe user view.
     *
     * @phpstan-param list<RoleId> $roleIds
     */
    public function __construct(
        private UserId $userId,
        private EmailAddress $email,
        private UserState $state,
        /** @var list<RoleId> */
        private array $roleIds,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * Creates a safe view without exposing credential or persistence authority.
     */
    public static function fromUser(User $user): self
    {
        return new self(
            $user->getId(),
            $user->getEmail(),
            $user->getState(),
            $user->getRoleIds(),
            $user->getCreatedAt(),
            $user->getUpdatedAt()
        );
    }

    /**
     * Returns the stable user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the canonical email address.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the lifecycle state.
     */
    public function getState(): UserState
    {
        return $this->state;
    }

    /**
     * Returns an immutable snapshot of assigned role identifiers.
     *
     * @return list<RoleId>
     */
    public function getRoleIds(): array
    {
        return $this->roleIds;
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last-update timestamp.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the canonical safe array representation.
     *
     * @return array{
     *     user_id: string,
     *     email: string,
     *     state: string,
     *     role_ids: list<string>,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'email' => $this->email->toString(),
            'state' => $this->state->value,
            'role_ids' => array_map(
                static fn(RoleId $roleId): string => $roleId->toString(),
                $this->roleIds
            ),
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
