<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Admission\FeatureAdmission;
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

final readonly class FeatureEnvironment
{
    public InMemoryFeatureRepository $features;

    public InMemoryPermissionRepository $permissions;

    public PermissionId $permissionId;

    public FeatureAdmission $admission;

    public function __construct()
    {
        $unit = new InMemoryUnitOfWork();
        $this->features = new InMemoryFeatureRepository($unit);
        $this->permissions = new InMemoryPermissionRepository($unit);
        $this->permissionId = PermissionId::generate();
        $this->permissions->add(Permission::define(
            $this->permissionId,
            PermissionName::fromString('AGENT_PROFILE_READ'),
            new DateTimeImmutable()
        ));
        $this->admission = new FeatureAdmission(new FeatureAvailability($this->features, $this->permissions));
        $this->seed(FeatureStatus::OFF);
    }

    public function seed(FeatureStatus $status, string $name = 'rollout', ?PermissionId $permissionId = null): void
    {
        $this->features->records[$name] = Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString($name),
            $permissionId ?? $this->permissionId,
            $status,
            1
        );
    }
}
