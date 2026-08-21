<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;

/**
 * Persists password-reset grant authority.
 */
interface PasswordResetGrantRepository
{
    /**
     * Retrieves the latest password-reset grant for a user.
     *
     * @throws Exception When an error occurs.
     */
    public function getByUserId(UserId $userId): ?PasswordResetGrant;

    /**
     * Atomically adds first-generation authority only while no grant history exists for the user.
     *
     * Returns false when another transaction has already established authority for the user.
     *
     * @throws Exception When an error occurs.
     */
    public function add(PasswordResetGrant $passwordResetGrant): bool;

    /**
     * Atomically appends newly issued authority after an unchanged terminal predecessor.
     *
     * Returns false when the predecessor is no longer the exact latest terminal grant or the replacement is
     * not issued authority for the same user with a digest absent from that user's complete grant history.
     *
     * @throws Exception When an error occurs.
     */
    public function appendAfterTerminal(
        PasswordResetGrant $terminalPredecessor,
        PasswordResetGrant $replacement
    ): bool;

    /**
     * Atomically consumes the grant only while the expected issued predecessor remains current.
     *
     * Returns false when another transaction has already replaced the expected predecessor or the
     * supplied successor is not its consumed immutable version.
     *
     * @throws Exception When an error occurs.
     */
    public function replaceConsumed(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $consumedPasswordResetGrant
    ): bool;

    /**
     * Atomically reissues authority only while the expected issued predecessor remains current.
     *
     * Returns false when another transaction has already replaced the expected predecessor or either
     * supplied successor is not the matching revoked predecessor and newly issued authority with a credential
     * digest absent from that user's complete grant history.
     *
     * @throws Exception When an error occurs.
     */
    public function replace(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $revokedPredecessor,
        PasswordResetGrant $replacement
    ): bool;
}
