<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class UserView
 *
 * Provides a safe immutable view of a user identity.
 */
final readonly class UserView implements Arrayable
{
    /** @var list<RoleId> */
    private array $roleIds;

    /**
     * Constructs UserView
     *
     * Constructs the safe user view.
     *
     * Validates the supplied Role list before retaining its typed immutable snapshot.
     *
     * @phpstan-param list<RoleId> $roleIds
     */
    public function __construct(
        private UserId $userId,
        private EmailAddress $email,
        private UserState $state,
        array $roleIds,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
        $this->roleIds = self::validatedRoleIds($roleIds);
    }

    /**
     * Creates a safe view without exposing credential or persistence authority
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
     * Returns the stable user identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the canonical email address
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the lifecycle state
     */
    public function getState(): UserState
    {
        return $this->state;
    }

    /**
     * Returns an immutable snapshot of assigned role identifiers
     *
     * @return list<RoleId>
     */
    public function getRoleIds(): array
    {
        return $this->roleIds;
    }

    /**
     * Returns the creation timestamp
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last-update timestamp
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the canonical safe array representation
     *
     * @return array{
     *     user_id: string,
     *     email: string,
     *     state: value-of<UserState>,
     *     role_ids: list<string>,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id'    => $this->userId->toString(),
            'email'      => $this->email->toString(),
            'state'      => $this->state->value,
            'role_ids'   => array_map(
                static fn(RoleId $roleId): string => $roleId->toString(),
                $this->roleIds
            ),
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Validates native array contents before retaining the typed immutable Role list
     *
     * @phpstan-param array<array-key, mixed> $roleIds
     * @phpstan-return list<RoleId>
     */
    private static function validatedRoleIds(array $roleIds): array
    {
        if (!array_is_list($roleIds)) {
            throw new DomainException('User view Role identities must form a list.');
        }

        $validatedRoleIds = [];
        foreach ($roleIds as $roleId) {
            if (!$roleId instanceof RoleId) {
                throw new DomainException('User views require Role identities.');
            }

            $validatedRoleIds[] = $roleId;
        }

        return $validatedRoleIds;
    }
}
