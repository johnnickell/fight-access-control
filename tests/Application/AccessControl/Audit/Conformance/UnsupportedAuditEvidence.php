<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;

/** Injects an unsupported current storage shape through the actual enabled repository write */
final class UnsupportedAuditEvidence extends AuditEvidence
{
    public function toArray(): array
    {
        $payload = parent::toArray();
        $payload['action'] = 'unsupported.action';

        return $payload;
    }
}
