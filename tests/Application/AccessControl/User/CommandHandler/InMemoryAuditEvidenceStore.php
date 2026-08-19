<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\AuditEvidence;
use Fight\AccessControl\Application\AccessControl\User\AuditEvidenceStore;
use RuntimeException;

final class InMemoryAuditEvidenceStore implements AuditEvidenceStore
{
    /** @var list<AuditEvidence> */
    private array $evidence = [];

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $failAfterSave = false
    ) {
    }

    public function save(AuditEvidence $evidence): void
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
