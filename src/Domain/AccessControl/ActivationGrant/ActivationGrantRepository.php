<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

use Exception;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Persists complete activation aggregate generations under one atomic boundary.
 *
 * A user's latest generation is authoritative. Implementations compare the predecessor's complete security-relevant
 * state with that stored generation, never PHP object identity or identifier and revision alone, and validate the next
 * transition from stored state. Every write returns false without mutation when its latest-generation precondition or
 * state comparison loses. Same-generation replacement is allowed only for a valid next revision of the same grant,
 * credential digest, expiry, user, and owned delivery generation. Credential digests remain unique across the user's
 * complete generation history.
 *
 * Implementations participate in the caller's UnitOfWork: writes are staged until commit and are fully rolled back with
 * the surrounding transaction. Replacing a predecessor with a successor atomically terminalizes the predecessor and
 * inserts the successor. Stale delivery callbacks and claims must not mutate, invalidate, or invoke ciphertext from a
 * newer generation. The contract fences current work; it does not promise exactly-once transport delivery.
 */
interface ActivationGrantRepository
{
    /**
     * Returns a generation by stable identifier, including historical generations.
     *
     * @throws Exception When an error occurs.
     */
    public function getById(ActivationGrantId $activationGrantId): ?ActivationGrant;

    /**
     * Returns the generation owning a stable delivery identifier, including terminal history.
     *
     * @throws Exception When an error occurs.
     */
    public function getByDeliveryId(ActivationDeliveryId $activationDeliveryId): ?ActivationGrant;

    /**
     * Returns the newest aggregate generation for a user.
     *
     * @throws Exception When an error occurs.
     */
    public function getLatestByUserId(UserId $userId): ?ActivationGrant;

    /**
     * Adds only a pristine first generation with revision zero, issued authority, pending non-empty recoverable
     * ciphertext, matching aggregate ownership, globally fresh grant and delivery identifiers, and a historically
     * unused digest.
     *
     * Returns false without mutation when the generation is not pristine, history already exists, or an identifier or
     * digest was used.
     *
     * @throws Exception When an error occurs.
     */
    public function add(ActivationGrant $activationGrant): bool;

    /**
     * Compare-saves one allowed same-generation next revision.
     *
     * The predecessor must equal the latest generation's complete security-relevant state. Returns false without
     * mutation for a stale or fabricated predecessor, skipped revision, changed generation identity, or invalid
     * same-generation replacement.
     *
     * @throws Exception When an error occurs.
     */
    public function replace(ActivationGrant $predecessor, ActivationGrant $replacement): bool;

    /**
     * Atomically terminalizes the latest predecessor and inserts one valid successor generation.
     *
     * The terminal predecessor must be the predecessor's next revision with no issued authority or retryable delivery.
     * The successor must be a pristine initial generation, belong to the same user, have fresh grant and delivery
     * identifiers, and use a digest absent from all history. Terminalization must be a valid aggregate transition from
     * the authoritative stored predecessor. Returns false without either write when any precondition or predecessor
     * state is stale or fabricated.
     *
     * @throws Exception When an error occurs.
     */
    public function replaceWithSuccessor(
        ActivationGrant $predecessor,
        ActivationGrant $terminalPredecessor,
        ActivationGrant $successor
    ): bool;

    /**
     * Adds a pristine successor generation once the user's latest generation is terminal.
     *
     * Used when a user with prior activation history must receive fresh activation authority, for example restoring a
     * deleted identity to pending activation. The latest generation must be terminal with no issued authority or
     * retryable delivery. The successor must be a pristine initial generation, belong to the same user, and use fresh
     * grant and delivery identifiers plus a digest absent from all history. Returns false without mutation when the
     * latest generation is not terminal or the successor is invalid.
     *
     * @throws Exception When an error occurs.
     */
    public function addSuccessor(ActivationGrant $successor): bool;
}
