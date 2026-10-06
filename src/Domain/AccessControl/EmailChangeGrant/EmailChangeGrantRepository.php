<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use DateTimeImmutable;
use Exception;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Interface EmailChangeGrantRepository
 *
 * Persists email-change authority generations under the caller's atomic boundary.
 */
interface EmailChangeGrantRepository
{
    /**
     * Returns deterministic secret-free due work ordered by eligibility time then delivery identifier
     *
     * Pending and due-retry work is eligible at its due time. Claimed work is eligible at lease expiry. Only the
     * authoritative latest generation for each User participates. The positive limit is applied after ordering.
     *
     * @return list<DueCredentialDelivery>
     *
     * @throws Exception When an error occurs.
     */
    public function findDue(DateTimeImmutable $at, int $limit): array;

    /**
     * Returns bounded secret-free expired work from latest issued authority
     *
     * Select authority at expiry <= at regardless of delivery status or material, including delivered,
     * permanent-failure and delivery-expired work. Exclude consumed, revoked, fully expired or obsolete authority.
     * Filter before ordering by expiry instant, delivery ID and purpose, then apply the limit (1–100).
     * Reads never mutate or publish. Results include the exact grant ID needed by full authority expiry.
     * Commands revalidate advisory identities and the bound reservation inside their transaction.
     *
     * @return list<ExpiredCredentialDelivery>
     *
     * @throws Exception When an error occurs.
     */
    public function findExpired(DateTimeImmutable $at, int $limit): array;

    /**
     * Returns the generation owning an exact delivery identifier, including terminal history
     *
     * @throws Exception When an error occurs.
     */
    public function getByDeliveryId(EmailChangeDeliveryId $emailChangeDeliveryId): ?EmailChangeGrant;

    /**
     * Returns the latest generation for a user
     *
     * @throws Exception When an error occurs.
     */
    public function getLatestByUserId(UserId $userId): ?EmailChangeGrant;

    /**
     * Adds a pristine first generation with fresh identifiers and an unused credential digest
     *
     * @throws Exception When an error occurs.
     */
    public function add(EmailChangeGrant $emailChangeGrant): bool;

    /**
     * Appends an unrelated generation after the authoritative predecessor is already terminal
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
     * Stores one valid same-generation next revision
     *
     * Compare complete expected authority/delivery state against the latest stored generation, not object identity
     * or identifier/revision alone. Preserve grant/User/delivery identity, digest, expiry and the immutable bound User
     * reservation revision. Accept only the aggregate's exact next delivery or consumed/revoked/expired authority
     * transition. Direct delivery expiry preserves attempt/outcome/failure evidence, distinct from a new retry failure.
     * Returns false without mutation for stale/fabricated predecessors, skipped revisions or invalid transitions.
     * Writes share the caller's transaction; either grant or matching reservation CAS loss rolls back both.
     *
     * @throws Exception When an error occurs.
     */
    public function replace(EmailChangeGrant $predecessor, EmailChangeGrant $replacement): bool;
}
