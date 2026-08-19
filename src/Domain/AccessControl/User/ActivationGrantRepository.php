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
     * Adds an activation grant.
     *
     * @throws Exception When an error occurs
     */
    public function add(ActivationGrant $grant): void;
}
