<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Interface UserRepository
 */
interface UserRepository
{
    /**
     * Determines whether any user currently references the role.
     *
     * @throws Exception When an error occurs
     */
    public function hasRoleAssignment(RoleId $roleId): bool;

    /**
     * Retrieves a user by its canonical email address.
     *
     * @throws Exception When an error occurs
     */
    public function getByEmail(EmailAddress $email): ?User;

    /**
     * Retrieves a user by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(UserId $id): ?User;

    /**
     * Atomically replaces authentication authority while the expected predecessor remains current.
     *
     * Implementations compare the stable identity, lifecycle state, password hash, authentication version, and
     * authentication-authority revision. The replacement must advance the authority revision by exactly one.
     * A successful operation participates in the same per-user authentication-authority fence as
     * replaceAuthenticationAuthorityAndAddRefreshSession(), held through the enclosing Unit of Work completion.
     * Returns false when the expected predecessor has lost authority or the replacement is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceAuthenticationAuthority(User $expected, User $replacement): bool;

    /**
     * Atomically replaces authentication authority and inserts its refresh session.
     *
     * Implementations must perform the same expected-authority comparison and exact revision advancement as
     * replaceAuthenticationAuthority(), then persist both replacement and session as one indivisible operation.
     * The session must belong to the replacement User and carry its authentication version. There may be no
     * observable state in which authority replacement succeeds without session insertion or vice versa.
     * The operation participates in the same per-user authentication-authority fence as
     * replaceAuthenticationAuthority(), held through the enclosing Unit of Work completion. Therefore a coupled
     * insert cannot win between a successful reset authority replacement and its complete active-session scan.
     * Returns false without either write when the expected authority has lost or the replacement/session is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceAuthenticationAuthorityAndAddRefreshSession(
        User $expected,
        User $replacement,
        RefreshSession $refreshSession
    ): bool;

    /**
     * Atomically replaces role assignments while the expected predecessor remains current.
     *
     * Implementations compare all User state, reject replacement changes outside role assignments, and require the
     * authorization-assignment revision to advance by exactly one. Every replacement RoleId must remain authoritative
     * through the enclosing Unit of Work. Validation and mutation occur under one adapter-owned role-reference fence
     * shared with RoleRepository::remove(). Returns false when the predecessor or a Role loses authority, or when the
     * replacement is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceRoleAssignments(User $expected, User $replacement): bool;

    /**
     * Atomically replaces an email-change reservation while the expected identity remains current.
     *
     * Implementations compare the complete expected User state and permit only one valid reservation transition with
     * exactly one revision advancement. A new destination must not be claimed by any canonical email or live
     * reservation. Returns false without mutation when the predecessor changed or the transition is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceEmailChangeReservation(User $expected, User $replacement): bool;

    /**
     * Atomically promotes an email-change reservation and replaces authentication authority.
     *
     * Implementations compare complete expected User state and permit only promotion of the live destination to the
     * canonical email, reservation clearing, exact authentication-version, authentication-authority,
     * reservation-revision, and canonical-email-revision advancement. The operation participates in the same
     * transaction-duration authentication-authority fence as other authority replacements. Returns false without
     * mutation when the predecessor changed or the transition is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceEmailChangeConfirmation(User $expected, User $replacement): bool;

    /**
     * Atomically corrects a pending identity's canonical email while the expected identity remains current.
     *
     * Implementations compare complete expected User state, permit only a pending-activation canonical-email change
     * with exactly one revision advancement, and reject a destination claimed by any canonical email or live
     * email-change reservation. Returns false without mutation when the predecessor changed or destination is reserved.
     *
     * @throws Exception When an error occurs
     */
    public function replacePendingInvitationEmail(User $expected, User $replacement): bool;

    /**
     * Atomically replaces the lifecycle state while the expected identity remains current.
     *
     * Implementations compare the complete expected User state and permit only one valid lifecycle transition:
     * active to disabled, disabled to active, active or disabled to deleted, or deleted to active or pending
     * activation. A restoration to pending activation clears the password hash; every other transition preserves it.
     * No other field may change. Returns false without mutation when the predecessor changed or the transition is
     * invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceLifecycleState(User $expected, User $replacement): bool;

    /**
     * Retrieves one page of user identities.
     *
     * @throws Exception When an error occurs
     */
    public function getAll(Pagination $pagination): ResultSet;

    /**
     * Adds a User.
     *
     * Implementations must reject a canonical email claimed by another canonical email or live reservation atomically.
     *
     * @throws Exception When an error occurs
     */
    public function add(User $user): void;
}
