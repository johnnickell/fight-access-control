<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Interface UserRepository
 */
interface UserRepository
{
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
     * Adds a User.
     *
     * Implementations must reject a duplicate canonical email atomically.
     *
     * @throws Exception When an error occurs
     */
    public function add(User $user): void;
}
