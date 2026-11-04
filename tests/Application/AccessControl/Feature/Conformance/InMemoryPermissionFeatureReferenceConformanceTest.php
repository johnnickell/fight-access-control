<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryPermissionFeatureReferenceConformanceTest extends PermissionFeatureReferenceConformance
{
    public function test_losing_multi_insert_transaction_rolls_back_unrelated_feature_after_removal_wins(): void
    {
        $unit = new InMemoryUnitOfWork();
        $permissionRepository = new InMemoryPermissionRepository($unit);
        $featureRepository = new InMemoryFeatureRepository($unit);
        $retained = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('RETAINED'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $removed = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('REMOVED'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $permissionRepository->add($retained);
        $permissionRepository->add($removed);
        self::assertTrue($unit->commitTransactional(fn(): bool => $permissionRepository->remove($removed)));
        $first = Feature::define(FeatureId::generate(), FeatureName::fromString('first'), $retained->getId());
        $second = Feature::define(FeatureId::generate(), FeatureName::fromString('second'), $removed->getId());

        try {
            $unit->commitTransactional(static function () use ($featureRepository, $first, $second): void {
                $featureRepository->add($first);
                $featureRepository->add($second);
            });
            self::fail('The second binding must reject the removed Permission.');
        } catch (FeatureReferenceException) {
            self::assertNull($featureRepository->getById($first->getId()));
            self::assertNull($featureRepository->getById($second->getId()));
            self::assertNull($permissionRepository->getById($removed->getId()));
            self::assertSame($retained, $permissionRepository->getById($retained->getId()));
        }
    }

    protected function environment(): PermissionFeatureReferenceEnvironment
    {
        $unit = new InMemoryUnitOfWork();
        $permissionRepository = new InMemoryPermissionRepository($unit);
        $featureRepository = new InMemoryFeatureRepository($unit);

        return new readonly class (
            $unit,
            $permissionRepository,
            $featureRepository
        ) implements PermissionFeatureReferenceEnvironment {
            public function __construct(
                private InMemoryUnitOfWork $unit,
                private InMemoryPermissionRepository $permissionRepository,
                private InMemoryFeatureRepository $featureRepository
            ) {
            }

            public function storePermission(Permission $permission): void
            {
                $this->permissionRepository->add($permission);
            }

            public function storeFeature(Feature $feature): void
            {
                $this->unit->commitTransactional(function () use ($feature): void {
                    $this->featureRepository->add($feature);
                });
            }

            public function removePermission(Permission $permission): bool
            {
                return $this->unit->commitTransactional(fn(): bool => $this->permissionRepository->remove($permission));
            }

            public function permission(Permission $permission): ?Permission
            {
                return $this->permissionRepository->getById($permission->getId());
            }

            public function feature(Feature $feature): ?Feature
            {
                return $this->featureRepository->getById($feature->getId());
            }
        };
    }
}
