<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\MappedAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Exception as PHPUnitException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[CoversClass(AuditEvidence::class)]
final class InMemoryAuditStorageConformanceTest extends AuditStorageConformance
{
    /** @return iterable<string, array{string}> */
    public static function mappingLosses(): iterable
    {
        foreach (['failure', 'user-relationships', 'retained-predecessor', 'grant-kind'] as $loss) {
            yield $loss => [$loss];
        }
    }

    #[DataProvider('mappingLosses')]
    public function test_storage_oracle_rejects_loss_of_populated_supported_fields(string $loss): void
    {
        $test = 'test_all_supported_fact_shapes_and_actor_kinds_survive_storage_reopening';
        $binding = new LossyAuditStorageBinding($test);
        $binding->loss = $loss;
        try {
            $binding->test_all_supported_fact_shapes_and_actor_kinds_survive_storage_reopening();
        } catch (PHPUnitException | DomainException) {
            // Equality failure or explicit hydration rejection both disqualify the broken binding.
            self::assertTrue($binding->changed, 'Rejection must follow actual corruption, not invalid corpus setup.');

            return;
        }

        self::fail('The reusable storage oracle qualified a deliberately lossy mapping: '.$loss);
    }

    public function test_unsupported_mapping_fails_the_enabled_write_without_silent_discard(): void
    {
        $binding = new InMemoryAuditTransactionBinding();
        $binding->execute();

        $unsupported = UnsupportedAuditEvidence::fromArray($binding->reopenEvidence()[0]->toArray());
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new MappedAuditEvidenceRepository($unitOfWork);
        try {
            $unitOfWork->commitTransactional(fn () => $repository->add($unsupported));
            self::fail('An unsupported write became a successful no-op.');
        } catch (DomainException) {
            self::assertSame([], $repository->all());
        }
    }

    /** @param list<AuditEvidence> $records
     * @return list<AuditEvidence>
     */
    protected function persistAndReopen(array $records): array
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new MappedAuditEvidenceRepository($unitOfWork);
        $unitOfWork->commitTransactional(function () use ($repository, $records): void {
            foreach ($records as $record) {
                $repository->add($record);
            }
        });

        // Each read hydrates from stored JSON bytes; no original evidence object is retained by the adapter.
        return $repository->all();
    }

    /** @param array<array-key, mixed> $payload */
    protected function hydrateUnsupported(array $payload): void
    {
        MappedAuditEvidenceRepository::hydrate(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_duplicate_identity_is_rejected_and_the_original_is_preserved(): void
    {
        $binding = new InMemoryAuditTransactionBinding();
        $binding->execute();

        $record = $binding->reopenEvidence()[0];
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new MappedAuditEvidenceRepository($unitOfWork);
        $unitOfWork->commitTransactional(fn () => $repository->add($record));
        try {
            $unitOfWork->commitTransactional(fn () => $repository->add($record));
            self::fail('Duplicate evidence identity was admitted.');
        } catch (RuntimeException) {
            self::assertCount(1, $repository->all());
            self::assertSame($record->toArray(), $repository->all()[0]->toArray());
        }
    }
}
