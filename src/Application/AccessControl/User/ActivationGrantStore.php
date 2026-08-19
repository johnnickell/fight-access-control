<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User;

use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;

/**
 * Persists activation grants.
 */
interface ActivationGrantStore
{
    /**
     * Stages an activation grant for durable persistence.
     */
    public function save(ActivationGrant $grant): void;
}
