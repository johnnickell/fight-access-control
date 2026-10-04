<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Service\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryFeatureRetirementConformanceTest extends FeatureRetirementConformance
{
    protected function environment(): FeatureRetirementEnvironment
    {
        $unit = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($unit);
        $permissions = new InMemoryPermissionRepository($unit);
        $events = new InMemoryEventDispatcher();

        return new readonly class ($unit, $features, $permissions, $events) implements FeatureRetirementEnvironment {
            public function __construct(
                private InMemoryUnitOfWork $unit,
                private InMemoryFeatureRepository $features,
                private InMemoryPermissionRepository $permissions,
                private InMemoryEventDispatcher $events
            ) {
            }

            public function features(): FeatureRepository
            {
                return $this->features;
            }

            public function permissions(): PermissionRepository
            {
                return $this->permissions;
            }

            public function unitOfWork(): TransactionalUnitOfWork
            {
                return $this->unit;
            }

            public function events(): EventDispatcher
            {
                return $this->events;
            }

            public function storePermission(Permission $permission): void
            {
                $this->permissions->add($permission);
            }

            public function removePermission(Permission $permission): bool
            {
                return $this->unit->commitTransactional(fn(): bool => $this->permissions->remove($permission));
            }

            /** @param list<string> $registrations */
            public function discovery(array $registrations, bool $complete = true): FeatureReferenceDiscovery
            {
                return new FixtureFeatureDiscovery(new class {
                }, $registrations, static fn(): bool => $complete);
            }
        };
    }
}
