<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\MappedAuditEvidenceRepository;

/** Deliberately broken test-only mapping used to challenge the reusable storage oracle */
final class LossyAuditStorageBinding extends AuditStorageConformance
{
    public string $loss = 'failure';

    public bool $changed = false;

    protected function persistAndReopen(array $records): array
    {
        return array_map(function (AuditEvidence $record): AuditEvidence {
            $payload = $record->toArray();
            $facts = $record->getFacts();
            if ($this->loss === 'failure' && $facts instanceof AuditGrantOutcome) {
                $payload['facts']['failure'] = null;
            } elseif ($this->loss === 'user-relationships' && $facts instanceof AuditUserOutcome) {
                $payload['facts']['grant_before'] = null;
                $payload['facts']['grant_after'] = null;
                $payload['facts']['replaced_predecessor'] = null;
            } elseif ($this->loss === 'retained-predecessor' && $facts instanceof AuditGrantOutcome) {
                $payload['facts']['replaced_predecessor'] = null;
            } elseif ($this->loss === 'grant-kind' && $facts instanceof AuditGrantOutcome) {
                $payload['facts']['grant_after']['kind'] = 'activation';
            }

            $this->changed = $this->changed || $payload !== $record->toArray();

            return MappedAuditEvidenceRepository::hydrate(json_encode($payload, JSON_THROW_ON_ERROR));
        }, $records);
    }

    protected function hydrateUnsupported(array $payload): void
    {
        MappedAuditEvidenceRepository::hydrate(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
