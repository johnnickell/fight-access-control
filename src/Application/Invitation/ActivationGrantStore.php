<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\Invitation;

use Fight\AccessControl\Domain\Identity\ActivationGrant;

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
