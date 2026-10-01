<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManagedPolicyPlanner::class)]
#[CoversClass(ReconcileManagedPolicyHandler::class)]
final class ManagedFeatureRemovalTest extends TestCase
{
    public function test_real_preview_and_apply_reject_referenced_removal_for_every_status(): void
    {
        foreach (FeatureStatus::cases() as $status) {
            $unit = new InMemoryUnitOfWork();
            $permissions = new InMemoryPermissionRepository($unit);
            $features = new InMemoryFeatureRepository($unit);
            $obsolete = $this->permission('OBSOLETE');
            $permissions->add($obsolete);
            $feature = $this->feature($obsolete, $status);
            $features->seed($feature);
            $planner = $this->planner($unit, $permissions);
            $command = new ReconcileManagedPolicy(new ManagedPolicy([], [], []));
            try {
                $planner->plan($command->getPolicy());
                self::fail('The preview must reject a referenced removal.');
            } catch (ManagedPolicyDefinitionException $failure) {
                self::assertStringContainsString('Feature references', $failure->getMessage());
            }

            $events = new InMemoryEventDispatcher();
            try {
                $this->handler($unit, $permissions, $events)->handle(CommandMessage::create($command));
                self::fail('Reconciliation must also reject the referenced removal.');
            } catch (ManagedPolicyDefinitionException) {
                self::assertSame($obsolete, $permissions->getById($obsolete->getId()));
                self::assertSame($feature, $features->getById($feature->getId()));
                self::assertCount(1, $events->events());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_feature_binding_does_not_block_protected_tier_promotion(): void
    {
        $unit = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unit);
        $features = new InMemoryFeatureRepository($unit);
        $permission = $this->permission('TEST_PERMISSION');
        $permissions->add($permission);
        $features->seed($this->feature($permission, FeatureStatus::PREVIEW));
        $policy = new ManagedPolicy([
            new ManagedPermissionDefinition(
                $permission->getId(),
                $permission->getName(),
                PermissionTier::SUPER_ADMIN_ONLY
            )
        ], [], []);
        $events = new InMemoryEventDispatcher();

        $this->handler($unit, $permissions, $events)->handle(
            CommandMessage::create(new ReconcileManagedPolicy($policy))
        );

        self::assertSame(PermissionTier::SUPER_ADMIN_ONLY, $permissions->getById($permission->getId())?->getTier());
        self::assertCount(1, $events->events());
    }

    public function test_late_reference_rejects_final_removal_and_rolls_back_prior_definition_creation(): void
    {
        $unit = new InMemoryUnitOfWork();
        $features = new InMemoryFeatureRepository($unit);
        $obsolete = $this->permission('OBSOLETE');
        $late = $this->feature($obsolete, FeatureStatus::OFF);
        $permissions = new InMemoryPermissionRepository(
            $unit,
            beforeRemove: static function () use ($unit, $features, $late): void {
                self::assertTrue($unit->authorizationReferenceState()->isReferenceFenceHeld());
                // Model a reference becoming visible after planning, at the final fenced decision.
                $features->seed($late);
            }
        );
        $permissions->add($obsolete);

        $addedId = PermissionId::generate();
        $command = new ReconcileManagedPolicy(new ManagedPolicy([
            new ManagedPermissionDefinition(
                $addedId,
                PermissionName::fromString('NEW_POLICY'),
                PermissionTier::ADMIN_SAFE
            )
        ], [], []));
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($unit, $permissions, $events)->handle(CommandMessage::create($command));
            self::fail('Final removal must reject the late reference.');
        } catch (LogicException $logicException) {
            self::assertStringContainsString('became referenced', $logicException->getMessage());
        }

        self::assertSame($obsolete, $permissions->getById($obsolete->getId()));
        self::assertNull($permissions->getById($addedId));
        self::assertSame($late, $features->getById($late->getId()));
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
    }

    private function planner(InMemoryUnitOfWork $unit, InMemoryPermissionRepository $permissions): ManagedPolicyPlanner
    {
        return new ManagedPolicyPlanner(
            $permissions,
            new InMemoryRoleRepository($unit),
            new InMemoryUserRepository($unit),
            new InMemoryAgentRepository($unit)
        );
    }

    private function handler(
        InMemoryUnitOfWork $unit,
        InMemoryPermissionRepository $permissions,
        InMemoryEventDispatcher $events
    ): ReconcileManagedPolicyHandler {
        return new ReconcileManagedPolicyHandler(
            $permissions,
            new InMemoryRoleRepository($unit),
            $this->planner($unit, $permissions),
            $unit,
            $events,
            new FixedClock(new DateTimeImmutable('2026-01-02T00:00:00+00:00'))
        );
    }

    private function permission(string $name): Permission
    {
        return Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString($name),
            PermissionTier::ADMIN_SAFE,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }

    private function feature(Permission $permission, FeatureStatus $status): Feature
    {
        return Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString('test-flag'),
            $permission->getId(),
            $status,
            1
        );
    }
}
