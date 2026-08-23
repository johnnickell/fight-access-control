<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use Exception;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Persists complete password-reset aggregate generations under one atomic boundary.
 *
 * A user's latest generation is authoritative. Implementations compare the predecessor's complete security-relevant
 * state with that stored generation, never PHP object identity or identifier and revision alone, and validate the next
 * transition from stored state. Every write returns false without mutation when its latest-generation precondition or
 * state comparison loses. Same-generation replacement is allowed only for a valid next revision of the same grant,
 * credential digest, expiry, user, and owned delivery generation. Credential digests remain unique across the user's
 * complete generation history.
 *
 * Implementations participate in the caller's UnitOfWork: writes are staged until commit and are fully rolled back with
 * the surrounding transaction. Successor operations atomically preserve or terminalize the predecessor as specified
 * and insert the successor. Stale delivery callbacks must not mutate or invalidate newer delivery generations.
 */
interface PasswordResetGrantRepository
{
    /**
     * Returns a generation by stable identifier, including historical generations.
     *
     * @throws Exception When an error occurs.
     */
    public function getById(PasswordResetGrantId $passwordResetGrantId): ?PasswordResetGrant;

    /**
     * Returns the generation owning a stable delivery identifier, including terminal history.
     *
     * @throws Exception When an error occurs.
     */
    public function getByDeliveryId(PasswordResetDeliveryId $passwordResetDeliveryId): ?PasswordResetGrant;

    /**
     * Returns the newest aggregate generation for a user.
     *
     * @throws Exception When an error occurs.
     */
    public function getLatestByUserId(UserId $userId): ?PasswordResetGrant;

    /**
     * Adds only a pristine first generation with revision zero, issued authority, non-empty recoverable ciphertext,
     * matching aggregate ownership, globally fresh grant and delivery identifiers, and a historically unused digest.
     *
     * Returns false without mutation when the generation is not pristine, history already exists, or an identifier or
     * digest was used.
     *
     * @throws Exception When an error occurs.
     */
    public function add(PasswordResetGrant $passwordResetGrant): bool;

    /**
     * Appends authority after the latest generation is already terminal and ciphertext-free.
     *
     * The supplied predecessor's complete security-relevant state must equal the latest generation. The successor must
     * be a pristine initial generation, belong to the same user, have fresh grant and delivery identifiers, and use a
     * digest absent from all history. Returns false without insertion when any precondition is stale or invalid.
     *
     * @throws Exception When an error occurs.
     */
    public function appendAfterTerminal(
        PasswordResetGrant $terminalPredecessor,
        PasswordResetGrant $successor
    ): bool;

    /**
     * Compare-saves one allowed same-generation next revision.
     *
     * The predecessor must equal the latest generation's complete security-relevant state. Returns false without
     * mutation for a stale or fabricated predecessor, skipped revision, changed generation identity, or invalid
     * same-generation replacement.
     *
     * @throws Exception When an error occurs.
     */
    public function replace(PasswordResetGrant $predecessor, PasswordResetGrant $replacement): bool;

    /**
     * Atomically terminalizes the latest predecessor and inserts one valid successor generation.
     *
     * The terminal predecessor must be the predecessor's next revision with no issued authority or recoverable
     * ciphertext. The successor must be a pristine initial generation satisfying the ownership, fresh identity, and
     * historical digest rules. Terminalization must be a valid aggregate transition from the authoritative stored
     * predecessor. Returns false without either write when any precondition or predecessor state is stale or
     * fabricated.
     *
     * @throws Exception When an error occurs.
     */
    public function replaceWithSuccessor(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $terminalPredecessor,
        PasswordResetGrant $successor
    ): bool;
}
