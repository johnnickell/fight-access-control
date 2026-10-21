<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Interface UserRepository
 *
 * All User writers share a per-identity complete-state fence and canonical-email/reservation
 * claim fences held through transaction completion, including activation, lifecycle, Role and
 * address writers. Recovery validation cannot be overtaken after a successful expected write.
 * Claim release/replacement and rollback must never clear another transaction's reservation.
 */
interface UserRepository
{
    /**
     * Determines whether any user currently references the role
     *
     * @throws Exception When an error occurs
     */
    public function hasRoleAssignment(RoleId $roleId): bool;

    /**
     * Retrieves a user by its canonical email address
     *
     * @throws Exception When an error occurs
     */
    public function getByEmail(EmailAddress $email): ?User;

    /**
     * Retrieves a user by its stable identifier
     *
     * @throws Exception When an error occurs
     */
    public function getById(UserId $id): ?User;

    /**
     * Validates an eligible recovery identity under the shared transaction-duration User fence
     *
     * Compare complete expected User state against authoritative storage, including canonical email and recovery
     * authority. Return false without mutation on stale or inactive state. Hold the same per-User fence as every
     * User and password-reset grant writer through the enclosing transaction completion, even without a User write.
     * Issuance, redemption and delivery couple this validation with their grant CAS and required audit. Consumers
     * use a consistent User-before-grant lock order on one actual connection; reads alone do not grant admission.
     *
     * @throws Exception When an error occurs
     */
    public function validateRecoveryAuthority(User $expected): bool;

    /**
     * Replaces authentication authority atomically while the expected predecessor remains current
     *
     * Implementations compare the stable identity, lifecycle state, password hash, authentication version, and
     * authentication-authority revision. The replacement must advance the authority revision by exactly one.
     * An authentication-version change couples outstanding password-reset cancellation in the same transaction.
     * A successful operation participates in the same per-user authentication-authority fence as
     * replaceAuthenticationAuthorityAndAddRefreshSession(), held through the enclosing Unit of Work completion.
     * Returns false when the expected predecessor has lost authority or the replacement is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceAuthenticationAuthority(User $expected, User $replacement): bool;

    /**
     * Replaces authentication authority atomically and inserts its refresh session
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
     * Validates one Role reference under the transaction-duration assignment fence without replacing a User
     *
     * Returns false when the Role is no longer authoritative.
     *
     * @throws Exception When an error occurs
     */
    public function validateRoleAssignmentReference(RoleId $roleId): bool;

    /**
     * Replaces role assignments atomically while the expected predecessor remains current
     *
     * Implementations compare all User state, reject replacement changes outside role assignments, and require the
     * authorization-assignment revision to advance by exactly one. Every expected or replacement RoleId must remain
     * authoritative through the enclosing Unit of Work. Validation and mutation occur under one adapter-owned
     * role-reference fence shared with RoleRepository::remove(). Returns false when the predecessor or a Role loses
     * authority, or when the replacement is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceRoleAssignments(User $expected, User $replacement): bool;

    /**
     * Replaces an email-change reservation atomically while the expected identity remains current
     *
     * Implementations compare the complete expected User state and permit only one valid reservation transition with
     * exactly one revision advancement. A new destination must not be claimed by any canonical email or live
     * reservation. Request/cancellation retain their active-state policy; expiry may release an existing reservation
     * in every reachable account state without changing lifecycle, canonical email, authentication/authorization,
     * password or unrelated fields. Only reservation revision and normal update metadata advance. Full email expiry
     * couples the exact grant-bound reservation revision and grant CAS in the same transaction.
     * Returns false without mutation when the predecessor changed or the transition is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceEmailChangeReservation(User $expected, User $replacement): bool;

    /**
     * Replaces an email-change reservation atomically and updates authentication authority
     *
     * Implementations compare complete expected User state and permit only promotion of the live destination to the
     * canonical email, reservation clearing, exact authentication-version, authentication-authority,
     * reservation-revision, and canonical-email-revision advancement. The operation participates in the same
     * transaction-duration authentication-authority fence as other authority replacements and couples outstanding
     * password-reset cancellation in the same transaction. Returns false without
     * mutation when the predecessor changed or the transition is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceEmailChangeConfirmation(User $expected, User $replacement): bool;

    /**
     * Updates a pending identity's canonical email atomically while the expected identity remains current
     *
     * Implementations compare complete expected User state, permit only a pending-activation canonical-email change
     * with exactly one revision advancement, and reject a destination claimed by any canonical email or live
     * email-change reservation. Returns false without mutation when the predecessor changed or destination is reserved.
     *
     * @throws Exception When an error occurs
     */
    public function replacePendingInvitationEmail(User $expected, User $replacement): bool;

    /**
     * Replaces or validates a pending registration identity under the transaction-duration fence
     *
     * Compare the complete expected User state with authoritative storage. Permit only the exact
     * recoverPendingRegistrationEmail() result: unchanged state for same canonical email, or one
     * canonical-email revision/update advancing for a correction. Preserve ID, lifecycle, Roles,
     * password, authentication/authorization and reservation state. Reject inconsistent pending
     * state, claimed canonical destination or live reservation. Even the no-op case must acquire
     * the shared identity/claim fence through completion, not merely read an earlier snapshot.
     * Returns false without mutation on stale/invalid state or a destination conflict. Recovery
     * couples this operation to complete-state activation successor CAS and audit in one transaction.
     *
     * @throws Exception When an error occurs
     */
    public function replacePendingRegistration(User $expected, User $replacement): bool;

    /**
     * Replaces the lifecycle state atomically while the expected identity remains current
     *
     * Implementations compare the complete expected User state and permit only one valid lifecycle transition:
     * active to disabled, disabled to active, active or disabled to deleted, or deleted to active or pending
     * activation. A restoration to pending activation clears the password hash; every other transition preserves it.
     * Disable and delete advance authenticationVersion exactly once; enable and restore preserve it. Outstanding
     * password-reset authority and recoverable material are cancelled in the same transaction. No other field may
     * change. Returns false without mutation when the predecessor changed or the transition is
     * invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replaceLifecycleState(User $expected, User $replacement): bool;

    /**
     * Retrieves one page of user identities
     *
     * @return ResultSet<User>
     *
     * @throws Exception When an error occurs
     */
    public function getAll(Pagination $pagination): ResultSet;

    /**
     * Adds a User
     *
     * Implementations atomically reject duplicate User IDs or a canonical email claimed by another canonical email
     * or live reservation. Every initial Role ID must remain authoritative under the shared Role-reference fence
     * through transaction completion, including Role removal/replacement. Name-resolved creation additionally uses
     * addWithRoleAssignments() to bind the complete expected definitions; add() alone cannot substitute for it.
     *
     * @throws Exception When an error occurs
     */
    public function add(User $user): void;

    /**
     * Adds a pending User with its complete name-resolved initial Role assignments atomically
     *
     * Applies add() identity/email/reservation uniqueness under the same transaction. The nonempty expected Roles
     * must match exactly the User's initial assignment set. Validate each complete current Role snapshot (identity,
     * unique canonical name, ownership, membership and timestamps), rejecting conflicting IDs/names or a changed,
     * removed or replaced definition. Supplied snapshots are expectations, never authority. Hold the shared
     * Role-reference/name fence through transaction completion, including every RoleRepository add/replace/remove
     * path. No insertion or reference may survive rollback. Returns false without insertion on a reference mismatch;
     * duplicate identity/email or storage failures throw. Consumers must qualify this on the actual shared connection.
     *
     * @phpstan-param list<Role> $expectedRoles
     *
     * @throws Exception When an error occurs
     */
    public function addWithRoleAssignments(User $user, array $expectedRoles): bool;
}
