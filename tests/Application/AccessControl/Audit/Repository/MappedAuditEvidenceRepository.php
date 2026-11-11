<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Repository;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use RuntimeException;

/** Test-only current JSON mapping; does not establish SQL, physical restart or independent-reader isolation */
final class MappedAuditEvidenceRepository implements AuditEvidenceRepository
{
    /** @var array<string, string> */
    private array $rows = [];

    private ?RuntimeException $failure = null;

    public function __construct(
        private readonly InMemoryUnitOfWork $unitOfWork,
        private readonly bool $failAfterSave = false,
        private readonly bool $failBeforeSave = false
    ) {
    }

    public static function hydrate(string $bytes): AuditEvidence
    {
        /** @var array<array-key, mixed> $payload */
        $payload = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        return AuditEvidence::fromArray($payload);
    }

    public function add(AuditEvidence $evidence): void
    {
        if (!$this->unitOfWork->transactionActive) {
            throw new RuntimeException('The mapped repository requires its owning transaction.');
        }

        if ($this->failBeforeSave) {
            $this->fail();
        }

        $id = $evidence->getId()->toString();
        if (isset($this->rows[$id])) {
            throw new RuntimeException('The evidence identity is already stored.');
        }

        $bytes = json_encode($evidence->toArray(), JSON_THROW_ON_ERROR);
        // Hydrate before insertion as well: no unsupported subtype or payload can silently become no-op storage.
        self::hydrate($bytes);
        $this->rows[$id] = $bytes;
        $this->unitOfWork->onRollback(function () use ($id): void {
            unset($this->rows[$id]);
        });
        if ($this->failAfterSave) {
            $this->fail();
        }
    }

    /** @return list<AuditEvidence> */
    public function all(): array
    {
        return array_map(self::hydrate(...), array_values($this->rows));
    }

    public function transactionIdentity(): InMemoryUnitOfWork
    {
        return $this->unitOfWork;
    }

    public function failure(): ?RuntimeException
    {
        return $this->failure;
    }

    private function fail(): never
    {
        $this->failure = new RuntimeException('Synthetic audit persistence failure.');

        throw $this->failure;
    }
}
