<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FeatureAvailability::class)]
#[CoversClass(FeatureStatus::class)]
final class InMemoryFeatureAvailabilityConformanceTest extends FeatureAvailabilityConformance
{
    protected function environment(): FeatureAvailabilityEnvironment
    {
        $unit = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($unit);
        $permissions = new InMemoryPermissionRepository($unit);

        return new readonly class ($features, $permissions) implements FeatureAvailabilityEnvironment {
            public function __construct(
                private InMemoryFeatureRepository $features,
                private InMemoryPermissionRepository $permissions
            ) {
            }

            public function permission(string $name): PermissionId
            {
                $id = PermissionId::generate();
                $this->permissions->add(Permission::define(
                    $id,
                    PermissionName::fromString($name),
                    new DateTimeImmutable()
                ));

                return $id;
            }

            public function setFeature(string $name, FeatureStatus $status, PermissionId $binding): void
            {
                $featureName = FeatureName::fromString($name);
                foreach ($this->features->records as $key => $existing) {
                    if ($existing->getName()->equals($featureName)) {
                        $this->features->records[$key] = Feature::reconstitute(
                            $existing->getId(),
                            $featureName,
                            $binding,
                            $status,
                            $existing->getRevision() + 1
                        );

                        return;
                    }
                }

                $this->features->seed(Feature::reconstitute(
                    FeatureId::generate(),
                    $featureName,
                    $binding,
                    $status,
                    1
                ));
            }

            public function evaluator(): FeatureAvailability
            {
                return new FeatureAvailability($this->features, $this->permissions);
            }
        };
    }
}
