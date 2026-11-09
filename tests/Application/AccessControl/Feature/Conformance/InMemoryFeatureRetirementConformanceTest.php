<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryFeatureRetirementConformanceTest extends FeatureRetirementConformance
{
    protected function environment(): FeatureRetirementEnvironment
    {
        $unit = new InMemoryUnitOfWork();
        $featureRepository = new InMemoryFeatureRepository($unit);
        $permissionRepository = new InMemoryPermissionRepository($unit);
        $eventDispatcher = new InMemoryEventDispatcher();

        return new readonly class (
            $unit,
            $featureRepository,
            $permissionRepository,
            $eventDispatcher
        ) implements FeatureRetirementEnvironment {
            public function __construct(
                private InMemoryUnitOfWork $unit,
                private InMemoryFeatureRepository $featureRepository,
                private InMemoryPermissionRepository $permissionRepository,
                private InMemoryEventDispatcher $eventDispatcher
            ) {
            }

            public function features(): FeatureRepository
            {
                return $this->featureRepository;
            }

            public function permissions(): PermissionRepository
            {
                return $this->permissionRepository;
            }

            public function unitOfWork(): TransactionalUnitOfWork
            {
                return $this->unit;
            }

            public function events(): EventDispatcher
            {
                return $this->eventDispatcher;
            }

            public function storePermission(Permission $permission): void
            {
                $this->permissionRepository->add($permission);
            }

            public function removePermission(Permission $permission): bool
            {
                return $this->unit->commitTransactional(fn(): bool => $this->permissionRepository->remove($permission));
            }

            /** @param list<string> $registrations */
            public function discovery(array $registrations, bool $complete = true): FeatureReferenceDiscovery
            {
                return new FixtureFeatureDiscovery(FeatureReferenceScope::CURRENT, new class {
                }, $registrations, static fn(): bool => $complete);
            }
        };
    }
}
