<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\Invitation;

/**
 * Persists required audit evidence.
 */
interface AuditEvidenceStore
{
    /**
     * Stages secret-free audit evidence for durable persistence.
     */
    public function save(AuditEvidence $evidence): void;
}
