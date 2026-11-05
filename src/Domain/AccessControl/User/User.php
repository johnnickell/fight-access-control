<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeCancellationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeConfirmationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeExpirationException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeRequestException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PendingInvitationCorrectionException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\RegistrationRecoveryException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotActiveException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotPendingActivationException;
use Fight\Common\Domain\Collection\HashSet;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class User
 *
 * Represents a stable user identity.
 */
class User
{
    /** @var HashSet<RoleId> */
    private HashSet $roleIds;

    /**
     * Constructs User
     *
     * Creates a user identity.
     *
     * Validates the persisted Role list before constructing the typed assignment set.
     *
     * @phpstan-param list<RoleId> $roleIds
     */
    protected function __construct(
        private readonly UserId $id,
        private EmailAddress $email,
        private UserState $state,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?PasswordHash $passwordHash = null,
        private int $authenticationVersion = 1,
        private int $authenticationAuthorityRevision = 0,
        array $roleIds = [],
        private int $authorizationAssignmentRevision = 0,
        private ?EmailAddress $pendingEmailChange = null,
        private int $emailChangeReservationRevision = 0,
        private int $canonicalEmailRevision = 0
    ) {
        new RecoveryAuthority($authenticationVersion, $canonicalEmailRevision);
        if (
            $authenticationAuthorityRevision < 0
            || $authorizationAssignmentRevision < 0
            || $emailChangeReservationRevision < 0
        ) {
            throw new DomainException('User persistence revisions must be nonnegative.');
        }

        $this->roleIds = self::roleIdsFromList($roleIds);
    }

    /**
     * Creates a pending user from an email address
     */
    public static function invite(UserId $id, EmailAddress $email, DateTimeImmutable $createdAt): self
    {
        return new self($id, $email, UserState::PENDING_ACTIVATION, $createdAt, $createdAt);
    }

    /**
     * Returns the stable user identifier
     */
    public function getId(): UserId
    {
        return $this->id;
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
     * Returns the canonical email address
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Stores a destination email while retaining the canonical identity
     *
     * @throws EmailChangeRequestException When the identity cannot begin an email change.
     */
    public function requestEmailChange(EmailAddress $email, DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::ACTIVE) {
            throw new EmailChangeRequestException('Only an active user can request an email change.');
        }

        if ($this->pendingEmailChange instanceof EmailAddress) {
            throw new EmailChangeRequestException('The user already has a pending email change.');
        }

        if ($this->email->canonical() === $email->canonical()) {
            throw new EmailChangeRequestException('The email-change destination must differ from the canonical email.');
        }

        $this->assertRevisionsCanAdvance($this->emailChangeReservationRevision);
        $this->pendingEmailChange = $email;
        ++$this->emailChangeReservationRevision;
        $this->updatedAt = $now;
    }

    /**
     * Returns the pending email-change destination
     */
    public function getPendingEmailChange(): ?EmailAddress
    {
        return $this->pendingEmailChange;
    }

    /**
     * Returns the monotonic email-change reservation revision
     */
    public function getEmailChangeReservationRevision(): int
    {
        return $this->emailChangeReservationRevision;
    }

    /**
     * Updates the canonical email of an identity still pending activation
     *
     * @throws PendingInvitationCorrectionException When the identity cannot be corrected.
     */
    public function correctPendingInvitationEmail(EmailAddress $email, DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::PENDING_ACTIVATION) {
            throw new PendingInvitationCorrectionException('Only a pending invitation can be corrected.');
        }

        if ($this->email->canonical() === $email->canonical()) {
            throw new PendingInvitationCorrectionException('The corrected email must differ from the current email.');
        }

        $this->assertRevisionsCanAdvance($this->canonicalEmailRevision);
        $this->email = $email;
        ++$this->canonicalEmailRevision;
        $this->updatedAt = $now;
    }

    /**
     * Updates a pending registration destination without changing identity or assigned authority
     *
     * Same-canonical-email reissue deliberately leaves all User state unchanged.
     */
    public function recoverPendingRegistrationEmail(EmailAddress $email, DateTimeImmutable $now): void
    {
        if (
            $this->state !== UserState::PENDING_ACTIVATION
            || $this->passwordHash instanceof PasswordHash
            || $this->pendingEmailChange instanceof EmailAddress
        ) {
            throw new RegistrationRecoveryException('The pending registration cannot be recovered.');
        }

        if ($this->email->canonical() === $email->canonical()) {
            return;
        }

        $this->assertRevisionsCanAdvance($this->canonicalEmailRevision);
        $this->email = $email;
        ++$this->canonicalEmailRevision;
        $this->updatedAt = $now;
    }

    /**
     * Returns the monotonic canonical-email persistence revision
     */
    public function getCanonicalEmailRevision(): int
    {
        return $this->canonicalEmailRevision;
    }

    /**
     * Updates a live destination and invalidates prior authentication authority
     *
     * @throws EmailChangeConfirmationException When no destination can be promoted.
     */
    public function confirmEmailChange(DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->pendingEmailChange instanceof EmailAddress) {
            throw new EmailChangeConfirmationException('The user has no confirmable email change.');
        }

        $this->assertRevisionsCanAdvance(
            $this->authenticationVersion,
            $this->authenticationAuthorityRevision,
            $this->emailChangeReservationRevision,
            $this->canonicalEmailRevision
        );
        $this->email = $this->pendingEmailChange;
        $this->pendingEmailChange = null;
        ++$this->authenticationVersion;
        ++$this->authenticationAuthorityRevision;
        ++$this->emailChangeReservationRevision;
        ++$this->canonicalEmailRevision;
        $this->updatedAt = $now;
    }

    /**
     * Clears the active identity's pending email-change reservation
     *
     * @throws EmailChangeCancellationException When no reservation can be cancelled.
     */
    public function cancelEmailChange(DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->pendingEmailChange instanceof EmailAddress) {
            throw new EmailChangeCancellationException('The user has no cancellable email change.');
        }

        $this->assertRevisionsCanAdvance($this->emailChangeReservationRevision);
        $this->pendingEmailChange = null;
        ++$this->emailChangeReservationRevision;
        $this->updatedAt = $now;
    }

    /**
     * Clears an expired email-change reservation without changing account state
     *
     * @throws EmailChangeExpirationException When no reservation can expire.
     */
    public function expireEmailChange(DateTimeImmutable $now): void
    {
        if (!$this->pendingEmailChange instanceof EmailAddress) {
            throw new EmailChangeExpirationException('The user has no expirable email change.');
        }

        $this->assertRevisionsCanAdvance($this->emailChangeReservationRevision);
        $this->pendingEmailChange = null;
        ++$this->emailChangeReservationRevision;
        $this->updatedAt = $now;
    }

    /**
     * Returns the lifecycle state
     */
    public function getState(): UserState
    {
        return $this->state;
    }

    /**
     * Disables an active identity without deleting it
     *
     * @throws UserLifecycleException When the identity is not active.
     */
    public function disable(DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::ACTIVE) {
            throw new UserLifecycleException('Only an active user can be disabled.');
        }

        $this->assertRevisionsCanAdvance($this->authenticationVersion);
        ++$this->authenticationVersion;
        $this->state = UserState::DISABLED;
        $this->updatedAt = $now;
    }

    /**
     * Restores a disabled identity to active without returning prior sessions
     *
     * @throws UserLifecycleException When the identity is not disabled.
     */
    public function enable(DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::DISABLED) {
            throw new UserLifecycleException('Only a disabled user can be enabled.');
        }

        $this->state = UserState::ACTIVE;
        $this->updatedAt = $now;
    }

    /**
     * Deletes an active or disabled identity while retaining its stable identity
     *
     * @throws UserLifecycleException When the identity cannot be deleted.
     */
    public function delete(DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::ACTIVE && $this->state !== UserState::DISABLED) {
            throw new UserLifecycleException('Only an active or disabled user can be deleted.');
        }

        $this->assertRevisionsCanAdvance($this->authenticationVersion);
        ++$this->authenticationVersion;
        $this->state = UserState::DELETED;
        $this->updatedAt = $now;
    }

    /**
     * Restores a deleted identity to an active or pending-activation state
     *
     * @throws UserLifecycleException When the identity is not deleted or the target is unsupported.
     */
    public function restore(UserState $target, DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::DELETED) {
            throw new UserLifecycleException('Only a deleted user can be restored.');
        }

        if ($target !== UserState::ACTIVE && $target !== UserState::PENDING_ACTIVATION) {
            throw new UserLifecycleException(
                'A restored user must target active or pending activation.'
            );
        }

        $this->state = $target;

        if ($target === UserState::PENDING_ACTIVATION) {
            $this->passwordHash = null;
        }

        $this->updatedAt = $now;
    }

    /**
     * Returns an isolated snapshot of assigned role identifiers
     *
     * @return list<RoleId>
     */
    public function getRoleIds(): array
    {
        return $this->roleIds->toArray();
    }

    /**
     * Determines whether the user has a role assignment
     */
    public function hasRole(RoleId $roleId): bool
    {
        return $this->roleIds->contains($roleId);
    }

    /**
     * Assigns one role and advances assignment authority exactly once when needed
     *
     * Returns whether the User state changed.
     */
    public function assignRole(RoleId $roleId, DateTimeImmutable $now): bool
    {
        if ($this->hasRole($roleId)) {
            return false;
        }

        $this->assertRevisionsCanAdvance($this->authorizationAssignmentRevision);
        $this->roleIds->add($roleId);
        ++$this->authorizationAssignmentRevision;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * Removes one role and advances assignment authority exactly once when needed
     *
     * Returns whether the User state changed.
     */
    public function removeRole(RoleId $roleId, DateTimeImmutable $now): bool
    {
        if (!$this->hasRole($roleId)) {
            return false;
        }

        $this->assertRevisionsCanAdvance($this->authorizationAssignmentRevision);
        $this->roleIds->remove($roleId);
        ++$this->authorizationAssignmentRevision;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * Replaces the complete role-assignment set and advances its authority once when changed
     *
     * Validates each supplied identity before replacing any current assignment.
     *
     * @phpstan-param iterable<RoleId> $roleIds
     */
    public function replaceRoleAssignments(iterable $roleIds, DateTimeImmutable $now): void
    {
        $replacementRoleIds = self::roleIdsFromIterable($roleIds);

        if ($this->roleIds->difference($replacementRoleIds)->isEmpty()) {
            return;
        }

        $this->assertRevisionsCanAdvance($this->authorizationAssignmentRevision);
        $this->roleIds = $replacementRoleIds;
        ++$this->authorizationAssignmentRevision;
        $this->updatedAt = $now;
    }

    /**
     * Returns the monotonic authorization-assignment persistence revision
     */
    public function getAuthorizationAssignmentRevision(): int
    {
        return $this->authorizationAssignmentRevision;
    }

    /**
     * Enables the pending identity with its initial password hash
     *
     * @throws UserNotPendingActivationException When the identity is not pending activation.
     */
    public function activate(PasswordHash $passwordHash, DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::PENDING_ACTIVATION) {
            throw new UserNotPendingActivationException('Only a pending user can be activated.');
        }

        $this->assertRevisionsCanAdvance($this->authenticationAuthorityRevision);
        $this->passwordHash = $passwordHash;
        ++$this->authenticationAuthorityRevision;
        $this->state = UserState::ACTIVE;
        $this->updatedAt = $now;
    }

    /**
     * Returns the established password hash
     */
    public function getPasswordHash(): ?PasswordHash
    {
        return $this->passwordHash;
    }

    /**
     * Records a successful login with an optional verified password rehash
     *
     * Credential verification remains the caller's responsibility. Every login serializes authority without
     * invalidating existing sessions or recovery. Without rehash, existing password-update metadata is preserved.
     */
    public function recordSuccessfulLogin(DateTimeImmutable $now, ?PasswordHash $rehashedPassword = null): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->passwordHash instanceof PasswordHash) {
            throw new UserNotActiveException('Only an active user with an established password can log in.');
        }

        $this->assertRevisionsCanAdvance($this->authenticationAuthorityRevision);
        if ($rehashedPassword instanceof PasswordHash) {
            $this->passwordHash = $rehashedPassword;
            $this->updatedAt = $now;
        }

        ++$this->authenticationAuthorityRevision;
    }

    /**
     * Replaces a verified active identity's password and invalidates prior authentication authority
     */
    public function changePassword(PasswordHash $passwordHash, DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->passwordHash instanceof PasswordHash) {
            throw new UserNotActiveException('Only an active user can change an established password.');
        }

        $this->assertRevisionsCanAdvance($this->authenticationVersion, $this->authenticationAuthorityRevision);
        $this->passwordHash = $passwordHash;
        ++$this->authenticationVersion;
        ++$this->authenticationAuthorityRevision;
        $this->updatedAt = $now;
    }

    /**
     * Replaces an active identity's password and invalidates prior authentication authority
     */
    public function resetPassword(PasswordHash $passwordHash, DateTimeImmutable $now): void
    {
        if ($this->state !== UserState::ACTIVE || !$this->passwordHash instanceof PasswordHash) {
            throw new UserNotActiveException('Only an active user can reset an established password.');
        }

        $this->assertRevisionsCanAdvance($this->authenticationVersion, $this->authenticationAuthorityRevision);
        $this->passwordHash = $passwordHash;
        ++$this->authenticationVersion;
        ++$this->authenticationAuthorityRevision;
        $this->updatedAt = $now;
    }

    /**
     * Returns the authoritative authentication version
     */
    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /**
     * Returns the current security authority for recovery
     */
    public function getRecoveryAuthority(): RecoveryAuthority
    {
        return new RecoveryAuthority($this->authenticationVersion, $this->canonicalEmailRevision);
    }

    /**
     * Returns the monotonic credential-authority persistence revision
     */
    public function getAuthenticationAuthorityRevision(): int
    {
        return $this->authenticationAuthorityRevision;
    }

    /**
     * Validates a persisted Role list before building its typed assignment set
     *
     * @phpstan-param array<array-key, mixed> $roleIds
     * @phpstan-return HashSet<RoleId>
     */
    private static function roleIdsFromList(array $roleIds): HashSet
    {
        if (!array_is_list($roleIds)) {
            throw new DomainException('User Role identities must form a list.');
        }

        return self::roleIdsFromIterable($roleIds);
    }

    /**
     * Validates native iterable elements without relying on runtime assertions
     *
     * @phpstan-param iterable<mixed> $roleIds
     * @phpstan-return HashSet<RoleId>
     */
    private static function roleIdsFromIterable(iterable $roleIds): HashSet
    {
        $validatedRoleIds = HashSet::of(RoleId::class);
        foreach ($roleIds as $roleId) {
            if (!$roleId instanceof RoleId) {
                throw new DomainException('User Role assignments require Role identities.');
            }

            $validatedRoleIds->add($roleId);
        }

        return $validatedRoleIds;
    }

    /**
     * Rejects exhausted counters before a transition can change any state
     */
    private function assertRevisionsCanAdvance(int ...$revisions): void
    {
        foreach ($revisions as $revision) {
            if ($revision === PHP_INT_MAX) {
                throw new DomainException('User authority revisions are exhausted.');
            }
        }
    }

    /**
     * Assigns a cloned identity an independent mutable assignment set
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
