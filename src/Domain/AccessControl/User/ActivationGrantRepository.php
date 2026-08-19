<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;

/**
 * Interface ActivationGrantRepository
 */
interface ActivationGrantRepository
{
    /**
     * Retrieves the most recently recorded activation grant for a user.
     *
     * @throws Exception When an error occurs
     */
    public function getByUserId(UserId $userId): ?ActivationGrant;

    /**
     * Adds an activation grant.
     *
     * @throws Exception When an error occurs
     */
    public function add(ActivationGrant $grant): void;

    /**
     * Replaces a predecessor activation grant with its revoked version and a newly issued grant.
     *
     * @throws Exception When an error occurs
     */
    public function replace(
        ActivationGrant $predecessor,
        ActivationGrant $revokedPredecessor,
        ActivationGrant $replacement
    ): void;
}
