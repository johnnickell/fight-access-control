<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotActiveException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotPendingActivationException;
use Fight\Common\Domain\Collection\HashSet;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Represents a stable user identity.
 */
class User
{
    /** @var HashSet<RoleId> */
    private HashSet $roleIds;

    /**
     * Creates a user identity.
     *
     * @phpstan-param list<RoleId> $roleIds
     */
    protected function __construct(
        private readonly UserId $id,
        private readonly EmailAddress $email,
        private UserState $state,
        private ?PasswordHash $passwordHash = null,
        private int $authenticationVersion = 1,
        private int $authenticationAuthorityRevision = 0,
        array $roleIds = [],
        private int $authorizationAssignmentRevision = 0
    ) {
        $this->roleIds = HashSet::of(RoleId::class);

        foreach ($roleIds as $roleId) {
            $this->roleIds->add($roleId);
        }
    }

    /**
     * Creates a pending user from an email address.
     */
    public static function invite(UserId $id, EmailAddress $email): self
    {
        return new self($id, $email, UserState::PENDING_ACTIVATION);
    }

    /**
     * Returns the stable user identifier.
     */
    public function getId(): UserId
    {
        return $this->id;
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
     * Returns an isolated snapshot of assigned role identifiers.
     *
     * @return list<RoleId>
     */
    public function getRoleIds(): array
    {
        return $this->roleIds->toArray();
    }

    /**
     * Determines whether the user has a role assignment.
     */
    public function hasRole(RoleId $roleId): bool
    {
        return $this->roleIds->contains($roleId);
    }

    /**
     * Replaces the complete role-assignment set and advances its authority once when changed.
     *
     * @phpstan-param iterable<RoleId> $roleIds
     */
    public function replaceRoleAssignments(iterable $roleIds): void
    {
        $replacementRoleIds = HashSet::of(RoleId::class);
        foreach ($roleIds as $roleId) {
            $replacementRoleIds->add($roleId);
        }

        if ($this->roleIds->difference($replacementRoleIds)->isEmpty()) {
            return;
        }

        $this->roleIds = $replacementRoleIds;
        ++$this->authorizationAssignmentRevision;
    }

    /**
     * Returns the monotonic authorization-assignment persistence revision.
     */
    public function getAuthorizationAssignmentRevision(): int
    {
        return $this->authorizationAssignmentRevision;
    }

    /**
     * Activates the pending identity with its initial password hash.
     *
     * @throws UserNotPendingActivationException When the identity is not pending activation.
     */
    public function activate(PasswordHash $passwordHash): void
    {
        if ($this->state !== UserState::PENDING_ACTIVATION) {
            throw new UserNotPendingActivationException('Only a pending user can be activated.');
        }

        $this->passwordHash = $passwordHash;
        $this->state = UserState::ACTIVE;
    }

    /**
     * Returns the established password hash.
     */
    public function getPasswordHash(): ?PasswordHash
    {
        return $this->passwordHash;
    }

    /**
     * Replaces an active identity's password hash after successful verification.
     */
    public function rehashPassword(PasswordHash $passwordHash): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->passwordHash instanceof PasswordHash) {
            throw new UserNotActiveException('Only an active user can rehash an established password.');
        }

        $this->passwordHash = $passwordHash;
    }

    /**
     * Replaces a verified active identity's password and invalidates prior authentication authority.
     */
    public function changePassword(PasswordHash $passwordHash): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->passwordHash instanceof PasswordHash) {
            throw new UserNotActiveException('Only an active user can change an established password.');
        }

        $this->passwordHash = $passwordHash;
        ++$this->authenticationVersion;
    }

    /**
     * Replaces an active identity's password and invalidates prior authentication authority.
     */
    public function resetPassword(PasswordHash $passwordHash): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->passwordHash instanceof PasswordHash) {
            throw new UserNotActiveException('Only an active user can reset an established password.');
        }

        $this->passwordHash = $passwordHash;
        ++$this->authenticationVersion;
    }

    /**
     * Returns the authoritative authentication version.
     */
    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /**
     * Advances the monotonic revision used to serialize credential-authority persistence.
     */
    public function advanceAuthenticationAuthorityRevision(): void
    {
        ++$this->authenticationAuthorityRevision;
    }

    /**
     * Returns the monotonic credential-authority persistence revision.
     */
    public function getAuthenticationAuthorityRevision(): int
    {
        return $this->authenticationAuthorityRevision;
    }

    /**
     * Gives a cloned identity an independent mutable assignment set.
     */
    public function __clone(): void
    {
        $roleIds = $this->roleIds->toArray();
        $this->roleIds = HashSet::of(RoleId::class);

        foreach ($roleIds as $roleId) {
            $this->roleIds->add($roleId);
        }
    }
}
