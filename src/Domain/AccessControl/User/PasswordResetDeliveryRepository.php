<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;

/**
 * Persists encrypted password-reset delivery work.
 */
interface PasswordResetDeliveryRepository
{
    /**
     * Retrieves one exact generation of password-reset delivery work.
     *
     * @throws Exception When an error occurs.
     */
    public function getById(PasswordResetDeliveryId $passwordResetDeliveryId): ?PasswordResetDelivery;

    /**
     * Retrieves the latest authoritative delivery generation for a user.
     *
     * @throws Exception When an error occurs.
     */
    public function getByUserId(UserId $userId): ?PasswordResetDelivery;

    /**
     * Atomically adds first-generation delivery work only while no delivery history exists for the user.
     *
     * Returns false when another transaction has already established delivery work for the user.
     *
     * @throws Exception When an error occurs.
     */
    public function add(PasswordResetDelivery $passwordResetDelivery): bool;

    /**
     * Atomically appends fresh work only while the exact terminal predecessor remains current.
     *
     * Returns false when another transaction has replaced the expected terminal generation or the supplied
     * replacement is not fresh recoverable work for the same user and destination.
     *
     * @throws Exception When an error occurs.
     */
    public function appendAfterTerminal(
        PasswordResetDelivery $terminalPredecessor,
        PasswordResetDelivery $replacement
    ): bool;

    /**
     * Atomically invalidates delivery work only while the exact generation remains current.
     *
     * Returns false when another transaction has already replaced the expected generation or the supplied
     * successor is not its matching ciphertext-free terminal version.
     *
     * @throws Exception When an error occurs.
     */
    public function replaceInvalidated(
        PasswordResetDelivery $predecessor,
        PasswordResetDelivery $invalidatedPasswordResetDelivery
    ): bool;

    /**
     * Atomically reissues delivery work only while the expected generation remains current.
     *
     * Returns false when another transaction has already replaced the expected generation or the supplied
     * successors are not its matching ciphertext-free version and a fresh latest generation.
     *
     * @throws Exception When an error occurs.
     */
    public function replace(
        PasswordResetDelivery $predecessor,
        PasswordResetDelivery $invalidatedPredecessor,
        PasswordResetDelivery $replacement
    ): bool;
}
