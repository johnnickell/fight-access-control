<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Repository;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use RuntimeException;

final class InMemoryAuditEvidenceRepository implements AuditEvidenceRepository
{
    /** @var list<AuditEvidence> */
    private array $evidence = [];

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $failAfterSave = false
    ) {
    }

    public function add(AuditEvidence $evidence): void
    {
        $this->evidence[] = $evidence;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->evidence);
        });

        if ($this->failAfterSave) {
            throw new RuntimeException('The audit persistence write failed.');
        }
    }

    /** @return list<AuditEvidence> */
    public function all(): array
    {
        return $this->evidence;
    }
}
