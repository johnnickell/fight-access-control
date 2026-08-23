<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use Exception;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Persists email-change authority generations under the caller's atomic boundary.
 */
interface EmailChangeGrantRepository
{
    /**
     * Returns the generation owning an exact delivery identifier, including terminal history.
     *
     * @throws Exception When an error occurs.
     */
    public function getByDeliveryId(EmailChangeDeliveryId $emailChangeDeliveryId): ?EmailChangeGrant;

    /**
     * Returns the latest generation for a user.
     *
     * @throws Exception When an error occurs.
     */
    public function getLatestByUserId(UserId $userId): ?EmailChangeGrant;

    /**
     * Adds a pristine first generation with fresh identifiers and an unused credential digest.
     *
     * @throws Exception When an error occurs.
     */
    public function add(EmailChangeGrant $emailChangeGrant): bool;

    /**
     * Appends an unrelated generation after the authoritative predecessor is already terminal.
     *
     * The predecessor's complete security-relevant state must equal the latest generation. The successor must be a
     * pristine issued initial generation for the same User, with fresh grant and delivery identifiers, recoverable
     * delivery for its destination, and a credential digest absent from complete history. Returns false without
     * insertion when the predecessor is stale, fabricated, issued, recoverable, or otherwise invalid.
     *
     * @throws Exception When an error occurs.
     */
    public function appendAfterTerminal(
        EmailChangeGrant $terminalPredecessor,
        EmailChangeGrant $successor
    ): bool;

    /**
     * Compare-saves one valid same-generation terminal next revision.
     *
     * Implementations compare the predecessor's complete security-relevant state and return false without mutation
     * for a stale predecessor, skipped revision, changed generation identity, or invalid consumed, revoked, or expired
     * transition.
     *
     * @throws Exception When an error occurs.
     */
    public function replace(EmailChangeGrant $predecessor, EmailChangeGrant $replacement): bool;
}
