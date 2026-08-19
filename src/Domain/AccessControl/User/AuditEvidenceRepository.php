<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Exception;

/**
 * Interface AuditEvidenceRepository
 */
interface AuditEvidenceRepository
{
    /**
     * Adds secret-free audit evidence.
     *
     * @throws Exception When an error occurs
     */
    public function add(AuditEvidence $evidence): void;
}
