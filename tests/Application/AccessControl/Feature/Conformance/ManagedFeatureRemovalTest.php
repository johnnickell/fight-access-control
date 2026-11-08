<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Discovery\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
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
            $permissionRepository = new InMemoryPermissionRepository($unit);
            $featureRepository = new InMemoryFeatureRepository($unit);
            $obsolete = $this->permission('OBSOLETE');
            $permissionRepository->add($obsolete);
            $feature = $this->feature($obsolete, $status);
            $featureRepository->seed($feature);
            $planner = $this->planner($unit, $permissionRepository);
            $command = new ReconcileManagedPolicy(new ManagedPolicy([], [], []));
            try {
                $planner->plan($command->getPolicy());
                self::fail('The preview must reject a referenced removal.');
            } catch (RuleViolation $failure) {
                self::assertStringContainsString('Feature references', $failure->getMessage());
            }

            $eventDispatcher = new InMemoryEventDispatcher();
            try {
                $this->handler($unit, $permissionRepository, $eventDispatcher)->handle(
                    CommandMessage::create($command)
                );
                self::fail('Reconciliation must also reject the referenced removal.');
            } catch (RuleViolation) {
                self::assertSame($obsolete, $permissionRepository->getById($obsolete->getId()));
                self::assertSame($feature, $featureRepository->getById($feature->getId()));
                self::assertCount(1, $eventDispatcher->events());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_feature_binding_does_not_block_protected_tier_promotion(): void
    {
        $unit = new InMemoryUnitOfWork();
        $permissionRepository = new InMemoryPermissionRepository($unit);
        $featureRepository = new InMemoryFeatureRepository($unit);
        $permission = $this->permission('TEST_PERMISSION');
        $permissionRepository->add($permission);
        $featureRepository->seed($this->feature($permission, FeatureStatus::PREVIEW));
        $policy = new ManagedPolicy([
            new ManagedPermissionDefinition(
                $permission->getId(),
                $permission->getName(),
                PermissionTier::SUPER_ADMIN_ONLY
            )
        ], [], []);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->handler($unit, $permissionRepository, $eventDispatcher)->handle(
            CommandMessage::create(new ReconcileManagedPolicy($policy))
        );

        self::assertSame(
            PermissionTier::SUPER_ADMIN_ONLY,
            $permissionRepository->getById($permission->getId())?->getTier()
        );
        self::assertCount(1, $eventDispatcher->events());
    }

    public function test_late_reference_rejects_final_removal_and_rolls_back_prior_definition_creation(): void
    {
        $unit = new InMemoryUnitOfWork();
        $featureRepository = new InMemoryFeatureRepository($unit);
        $obsolete = $this->permission('OBSOLETE');
        $late = $this->feature($obsolete, FeatureStatus::OFF);
        $permissionRepository = new InMemoryPermissionRepository(
            $unit,
            beforeRemove: static function () use ($unit, $featureRepository, $late): void {
                self::assertTrue($unit->authorizationReferenceState()->isReferenceFenceHeld());
                // Model a reference becoming visible after planning, at the final fenced decision.
                $featureRepository->seed($late);
            }
        );
        $permissionRepository->add($obsolete);

        $addedId = PermissionId::generate();
        $command = new ReconcileManagedPolicy(new ManagedPolicy([
            new ManagedPermissionDefinition(
                $addedId,
                PermissionName::fromString('NEW_POLICY'),
                PermissionTier::ADMIN_SAFE
            )
        ], [], []));
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            $this->handler($unit, $permissionRepository, $eventDispatcher)->handle(CommandMessage::create($command));
            self::fail('Final removal must reject the late reference.');
        } catch (StateConflict $stateConflict) {
            self::assertStringContainsString('became referenced', $stateConflict->getMessage());
        }

        self::assertSame($obsolete, $permissionRepository->getById($obsolete->getId()));
        self::assertNull($permissionRepository->getById($addedId));
        self::assertSame($late, $featureRepository->getById($late->getId()));
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
    }

    public function test_retirement_releases_only_its_feature_reference_for_reconciliation(): void
    {
        foreach (['none', 'feature', 'role', 'agent'] as $remaining) {
            $unit = new InMemoryUnitOfWork();
            $permissionRepository = new InMemoryPermissionRepository($unit);
            $featureRepository = new InMemoryFeatureRepository($unit);
            $roleRepository = new InMemoryRoleRepository($unit);
            $agentRepository = new InMemoryAgentRepository($unit);
            $permission = $this->permission('OBSOLETE');
            $permissionRepository->add($permission);
            $retiring = $this->feature($permission, FeatureStatus::ON);
            $featureRepository->seed($retiring);
            $time = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            if ($remaining === 'feature') {
                $featureRepository->seed(Feature::define(
                    FeatureId::generate(),
                    FeatureName::fromString('other-flag'),
                    $permission->getId()
                ));
            }

            if ($remaining === 'role') {
                $roleRepository->add(Role::define(
                    RoleId::generate(),
                    RoleName::fromString('ROLE_CUSTOM'),
                    [$permission->getId()],
                    $time
                ));
            }

            if ($remaining === 'agent') {
                $agentRepository->add(Agent::reconstitute(
                    AgentId::generate(),
                    AgentName::fromString('reference fixture'),
                    AgentState::ACTIVE,
                    AgentCredentialId::generate(),
                    1,
                    'fixture-encrypted-envelope',
                    [$permission->getId()],
                    1,
                    $time,
                    $time
                ));
            }

            $eventDispatcher = new InMemoryEventDispatcher();
            $discovery = new FixtureFeatureDiscovery(new class {
            }, [], static fn(): bool => true);
            new RemoveFeatureHandler($discovery, $featureRepository, $unit, $eventDispatcher)->handle(
                CommandMessage::create(new RemoveFeature($retiring->getId(), 1))
            );
            self::assertNull($featureRepository->getById($retiring->getId()));
            self::assertSame($permission, $permissionRepository->getById($permission->getId()));
            $planner = new ManagedPolicyPlanner(
                $permissionRepository,
                $roleRepository,
                new InMemoryUserRepository($unit),
                $agentRepository
            );
            $handler = new ReconcileManagedPolicyHandler(
                $permissionRepository,
                $roleRepository,
                $planner,
                $unit,
                $eventDispatcher,
                new FixedClock($time)
            );
            try {
                $handler->handle(CommandMessage::create(new ReconcileManagedPolicy(new ManagedPolicy([], [], []))));
                self::assertSame('none', $remaining);
                self::assertNull($permissionRepository->getById($permission->getId()));
            } catch (RuleViolation | StateConflict $failure) {
                self::assertNotSame('none', $remaining);
                self::assertInstanceOf(RuleViolation::class, $failure);
                if ($remaining === 'agent') {
                    self::assertTrue($agentRepository->hasPermissionAssignment($permission->getId()));
                }

                self::assertSame($permission, $permissionRepository->getById($permission->getId()));
                self::assertCount(2, $eventDispatcher->events());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[1]);
            }
        }
    }

    private function planner(
        InMemoryUnitOfWork $unit,
        InMemoryPermissionRepository $permissionRepository
    ): ManagedPolicyPlanner {
        return new ManagedPolicyPlanner(
            $permissionRepository,
            new InMemoryRoleRepository($unit),
            new InMemoryUserRepository($unit),
            new InMemoryAgentRepository($unit)
        );
    }

    private function handler(
        InMemoryUnitOfWork $unit,
        InMemoryPermissionRepository $permissionRepository,
        InMemoryEventDispatcher $eventDispatcher
    ): ReconcileManagedPolicyHandler {
        return new ReconcileManagedPolicyHandler(
            $permissionRepository,
            new InMemoryRoleRepository($unit),
            $this->planner($unit, $permissionRepository),
            $unit,
            $eventDispatcher,
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
