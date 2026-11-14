<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\MappedAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

/** Models shared transaction state and current evidence mapping, not a real persistence adapter */
final readonly class FeatureLifecycleAuditFixture
{
    public InMemoryUnitOfWork $unit;

    public InMemoryFeatureRepository $features;

    public InMemoryPermissionRepository $permissions;

    public MappedAuditEvidenceRepository $audit;

    public InMemoryEventDispatcher $events;

    public RoutingCommandBus $bus;

    public CreateFeature|RemoveFeature $command;

    public Feature $original;

    public Permission $permission;

    public DateTimeImmutable $time;

    public function __construct(
        string $operation,
        bool $enabled = true,
        bool $omit = false,
        bool $actor = true,
        bool $failAudit = false,
        bool $failBeforeAudit = false,
        ?Clock $clock = null,
        ?InMemoryEventDispatcher $events = null,
        ?FeatureReferenceDiscovery $discovery = null
    ) {
        $this->unit = new InMemoryUnitOfWork();
        $this->features = new InMemoryFeatureRepository($this->unit);
        $this->permissions = new InMemoryPermissionRepository($this->unit);
        $this->audit = new MappedAuditEvidenceRepository($this->unit, $failAudit, $failBeforeAudit);
        $this->events = $events ?? new InMemoryEventDispatcher();
        $this->time = new DateTimeImmutable('2030-01-08T12:34:56.123456Z');
        $this->permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('TEST_FEATURES'),
            new DateTimeImmutable('2030-01-01')
        );
        $this->permissions->add($this->permission);
        $this->original = Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('dashboard'),
            $this->permission->getId(),
            FeatureStatus::PREVIEW,
            7
        );
        if ($operation === 'remove') {
            $this->features->seed($this->original);
        }

        $actorId = $actor ? UserId::generate() : null;
        $command = new RemoveFeature($this->original->getId(), 7, $actorId);
        if ($operation === 'create') {
            $command = new CreateFeature($this->original->getName(), $this->permission->getId(), $actorId);
        }

        $this->command = $command;
        $clock ??= new FixedClock($this->time);
        $arguments = $omit ? [] : ['auditEvidenceRepository' => $enabled ? $this->audit : null];
        $handler = new RemoveFeatureHandler(
            $discovery ?? new FixtureFeatureDiscovery(
                FeatureReferenceScope::CURRENT,
                new class {
                },
                [],
                static fn(): bool => true
            ),
            $this->features,
            $clock,
            $this->unit,
            $this->events,
            ...$arguments
        );
        if ($operation === 'create') {
            $handler = new CreateFeatureHandler(
                $this->features,
                $this->permissions,
                $clock,
                $this->unit,
                $this->events,
                ...$arguments
            );
        }

        $router = new InMemoryCommandRouter();
        $router->registerHandler($handler::commandRegistration(), $handler);

        $this->bus = new RoutingCommandBus($router);
    }
}
