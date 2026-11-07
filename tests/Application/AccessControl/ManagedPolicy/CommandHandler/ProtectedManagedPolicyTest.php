<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\GrantPermissionToAgentHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\GrantPermissionToCustomRoleHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Exception\ManagedPolicyDefinitionException;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\Role\ImpersonatingRole;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManagedPolicy::class)]
#[CoversClass(ManagedPolicyPlanner::class)]
#[CoversClass(ReconcileManagedPolicyHandler::class)]
final class ProtectedManagedPolicyTest extends TestCase
{
    public function test_definition_rejects_protected_membership_on_ordinary_role_before_preview_or_apply(): void
    {
        $permission = $this->definition(PermissionTier::SUPER_ADMIN_ONLY);
        foreach (['ROLE_EDITOR', 'ROLE_SUPER_ADMIN'] as $name) {
            $roles = [new ManagedRoleDefinition(
                RoleId::generate(),
                RoleName::fromString($name),
                [$permission->getId()]
            )];
            if ($name === 'ROLE_SUPER_ADMIN') {
                self::assertCount(1, new ManagedPolicy([$permission], $roles)->getRoles());
                continue;
            }

            try {
                new ManagedPolicy([$permission], $roles);
                self::fail('A protected definition outside managed Super Admin must be rejected.');
            } catch (ManagedPolicyDefinitionException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_preview_and_apply_reject_a_role_spoofing_the_designated_identity(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $spoof = ImpersonatingRole::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [],
            $this->now()
        );
        $roleRepository->add($spoof);
        $policy = $this->policy(PermissionTier::SUPER_ADMIN_ONLY, [
            new ManagedRoleDefinition(
                $spoof->getId(),
                RoleName::fromString('ROLE_SUPER_ADMIN'),
                [$this->definition(PermissionTier::SUPER_ADMIN_ONLY)->getId()]
            )
        ]);
        $planner = new ManagedPolicyPlanner(
            $permissionRepository,
            $roleRepository,
            new InMemoryUserRepository($unitOfWork),
            $agentRepository
        );
        try {
            new PreviewManagedPolicyHandler($planner)->handle(QueryMessage::create(new PreviewManagedPolicy($policy)));
            self::fail('A spoofed designated Role must not be previewed as authoritative.');
        } catch (ManagedRoleException) {
            self::addToAssertionCount(1);
        }

        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new ReconcileManagedPolicyHandler(
            $permissionRepository,
            $roleRepository,
            $planner,
            $unitOfWork,
            $eventDispatcher,
            new FixedClock($this->now())
        );
        try {
            $handler->handle(CommandMessage::create(new ReconcileManagedPolicy($policy)));
            self::fail('A spoofed designated Role must not change authority.');
        } catch (ManagedRoleException) {
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            self::assertSame('Managed policy reconciliation failed.', $eventDispatcher->events()[0]->getErrorMessage());
        }

        self::assertNull($permissionRepository->getById($this->definition(PermissionTier::SUPER_ADMIN_ONLY)->getId()));
        self::assertSame($spoof, $roleRepository->getById($spoof->getId()));
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
    }

    public function test_grant_first_blocks_promotion_until_membership_is_removed_for_each_authority(): void
    {
        foreach (['custom', 'managed', 'agent'] as $holder) {
            $unitOfWork = new InMemoryUnitOfWork();
            $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
            $roleRepository = new InMemoryRoleRepository($unitOfWork);
            $agentRepository = new InMemoryAgentRepository($unitOfWork);
            $permission = $this->currentPermission();
            $permissionRepository->add($permission);
            $role = Role::define(
                RoleId::generate(),
                RoleName::fromString('ROLE_CUSTOM'),
                [],
                $this->now()
            );
            $agent = $this->agent();
            if ($holder === 'agent') {
                $agentRepository->add($agent);
                $grant = new GrantPermissionToAgentHandler(
                    $agentRepository,
                    $permissionRepository,
                    new FixedClock($this->now()),
                    $unitOfWork,
                    new InMemoryEventDispatcher()
                );
                $grant->handle(CommandMessage::create(
                    new GrantPermissionToAgent(UserId::generate(), $agent->getId(), $permission->getId())
                ));
            } elseif ($holder === 'managed') {
                $role = Role::defineManaged(
                    $role->getId(),
                    RoleName::fromString('ROLE_EDITOR'),
                    [$permission->getId()],
                    $this->now()
                );
                $roleRepository->add($role);
            } else {
                $roleRepository->add($role);
                $grant = new GrantPermissionToCustomRoleHandler(
                    $roleRepository,
                    $permissionRepository,
                    new FixedClock($this->now()),
                    $unitOfWork,
                    new InMemoryEventDispatcher()
                );
                $grant->handle(CommandMessage::create(
                    new GrantPermissionToCustomRole(UserId::generate(), $role->getId(), $permission->getId())
                ));
            }

            $eventDispatcher = new InMemoryEventDispatcher();
            $planner = new ManagedPolicyPlanner(
                $permissionRepository,
                $roleRepository,
                new InMemoryUserRepository($unitOfWork),
                $agentRepository
            );
            $policy = $this->policy(
                PermissionTier::SUPER_ADMIN_ONLY,
                $holder === 'managed' ? [$this->roleDefinition($role, [])] : []
            );
            $handler = new ReconcileManagedPolicyHandler(
                $permissionRepository,
                $roleRepository,
                $planner,
                $unitOfWork,
                $eventDispatcher,
                new FixedClock($this->now())
            );
            try {
                new PreviewManagedPolicyHandler($planner)->handle(
                    QueryMessage::create(new PreviewManagedPolicy($policy))
                );
                self::fail('Preview must disclose the forbidden promotion.');
            } catch (RuleViolation) {
                self::addToAssertionCount(1);
            }

            try {
                $handler->handle(CommandMessage::create(new ReconcileManagedPolicy($policy)));
                self::fail('Promotion must fail until forbidden membership is removed.');
            } catch (RuleViolation) {
                self::assertSame(
                    PermissionTier::ADMIN_SAFE,
                    $permissionRepository->getById($permission->getId())?->getTier()
                );
            }

            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);

            if ($holder === 'agent') {
                $held = $agentRepository->getById($agent->getId());
                self::assertInstanceOf(Agent::class, $held);
                self::assertTrue($agentRepository->replacePermissionAssignments(
                    $held,
                    $held->revokePermission($permission->getId(), $this->now())
                ));
            } else {
                $held = $roleRepository->getById($role->getId());
                self::assertInstanceOf(Role::class, $held);
                if ($holder === 'custom') {
                    self::assertTrue($roleRepository->replace(
                        $held,
                        $held->revokePermissionFromCustom($permission->getId(), $this->now())
                    ));
                } else {
                    // Remove managed membership in an ADMIN_SAFE reconciliation before promoting.
                    $handler->handle(CommandMessage::create(new ReconcileManagedPolicy(
                        $this->policy(PermissionTier::ADMIN_SAFE, [$this->roleDefinition($held, [])])
                    )));
                }
            }

            $handler->handle(CommandMessage::create(new ReconcileManagedPolicy($policy)));
            self::assertSame(
                PermissionTier::SUPER_ADMIN_ONLY,
                $permissionRepository->getById($permission->getId())->getTier()
            );
            self::assertInstanceOf(
                ManagedPolicyReconciled::class,
                $eventDispatcher->events()[array_key_last($eventDispatcher->events())]
            );
        }
    }

    public function test_promotion_first_denies_new_custom_and_agent_grants_without_a_success_event(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $permission = $this->currentPermission();
        $permissionRepository->add($permission);
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_CUSTOM'), [], $this->now());
        $roleRepository->add($role);
        $agent = $this->agent();
        $agentRepository->add($agent);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new ReconcileManagedPolicyHandler(
            $permissionRepository,
            $roleRepository,
            new ManagedPolicyPlanner(
                $permissionRepository,
                $roleRepository,
                new InMemoryUserRepository($unitOfWork),
                $agentRepository
            ),
            $unitOfWork,
            $eventDispatcher,
            new FixedClock($this->now())
        );
        $handler->handle(CommandMessage::create(
            new ReconcileManagedPolicy($this->policy(PermissionTier::SUPER_ADMIN_ONLY))
        ));
        self::assertInstanceOf(ManagedPolicyReconciled::class, $eventDispatcher->events()[0]);

        $roleEventDispatcher = new InMemoryEventDispatcher();
        $agentEventDispatcher = new InMemoryEventDispatcher();
        $roleGrant = new GrantPermissionToCustomRoleHandler(
            $roleRepository,
            $permissionRepository,
            new FixedClock($this->now()),
            $unitOfWork,
            $roleEventDispatcher
        );
        $agentGrant = new GrantPermissionToAgentHandler(
            $agentRepository,
            $permissionRepository,
            new FixedClock($this->now()),
            $unitOfWork,
            $agentEventDispatcher
        );
        foreach (
            [
                [$roleGrant, new GrantPermissionToCustomRole(UserId::generate(), $role->getId(), $permission->getId())],
                [$agentGrant, new GrantPermissionToAgent(UserId::generate(), $agent->getId(), $permission->getId())]
            ] as [$handler, $command]
        ) {
            try {
                $handler->handle(CommandMessage::create($command));
                self::fail('Protected membership must not be committed.');
            } catch (RuleViolation) {
                self::assertSame(
                    PermissionTier::SUPER_ADMIN_ONLY,
                    $permissionRepository->getById($permission->getId())?->getTier()
                );
            }
        }

        self::assertInstanceOf(CommandFailedEvent::class, $roleEventDispatcher->events()[0]);
        self::assertInstanceOf(CommandFailedEvent::class, $agentEventDispatcher->events()[0]);
        self::assertFalse($roleRepository->getById($role->getId())?->hasPermission($permission->getId()));
        self::assertFalse($agentRepository->getById($agent->getId())?->hasPermission($permission->getId()));
    }

    public function test_stale_promotion_write_rechecks_membership_and_rolls_back_without_success(): void
    {
        foreach (['custom', 'agent'] as $holder) {
            $unitOfWork = new InMemoryUnitOfWork();
            $roleRepository = new InMemoryRoleRepository($unitOfWork);
            $agentRepository = new InMemoryAgentRepository($unitOfWork);
            $permission = $this->currentPermission();
            $agent = $this->agent();
            $agentRepository->add($agent);
            $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_CUSTOM'), [], $this->now());
            $roleRepository->add($role);
            $permissionRepository = new InMemoryPermissionRepository(
                $unitOfWork,
                beforeReplace: function () use (
                    $holder,
                    $roleRepository,
                    $agentRepository,
                    $role,
                    $agent,
                    $permission
                ): void {
                    if ($holder === 'custom') {
                        self::assertTrue($roleRepository->replace(
                            $role,
                            $role->grantPermissionToCustom($permission->getId(), $this->now())
                        ));
                    } else {
                        self::assertTrue($agentRepository->replacePermissionAssignments(
                            $agent,
                            $agent->grantPermission($permission->getId(), $this->now())
                        ));
                    }
                }
            );
            $permissionRepository->add($permission);
            $eventDispatcher = new InMemoryEventDispatcher();
            $handler = new ReconcileManagedPolicyHandler(
                $permissionRepository,
                $roleRepository,
                new ManagedPolicyPlanner(
                    $permissionRepository,
                    $roleRepository,
                    new InMemoryUserRepository($unitOfWork),
                    $agentRepository
                ),
                $unitOfWork,
                $eventDispatcher,
                new FixedClock($this->now())
            );

            $auxiliaryId = PermissionId::generate();
            $policy = new ManagedPolicy([
                new ManagedPermissionDefinition(
                    $auxiliaryId,
                    PermissionName::fromString('AUXILIARY'),
                    PermissionTier::ADMIN_SAFE
                ),
                $this->definition(PermissionTier::SUPER_ADMIN_ONLY)
            ], []);
            try {
                $handler->handle(CommandMessage::create(new ReconcileManagedPolicy($policy)));
                self::fail('A stale promotion must not overwrite a later grant.');
            } catch (LogicException $failure) {
                self::assertSame('Managed permission changed after preflight.', $failure->getMessage());
            }

            self::assertSame($permission, $permissionRepository->getById($permission->getId()));
            self::assertNull($permissionRepository->getById($auxiliaryId));
            self::assertFalse($roleRepository->getById($role->getId())?->hasPermission($permission->getId()));
            self::assertFalse($agentRepository->getById($agent->getId())?->hasPermission($permission->getId()));
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    /** @phpstan-param list<ManagedRoleDefinition> $roles */
    private function policy(PermissionTier $tier, array $roles = []): ManagedPolicy
    {
        return new ManagedPolicy([$this->definition($tier)], $roles);
    }

    private function definition(PermissionTier $tier): ManagedPermissionDefinition
    {
        return new ManagedPermissionDefinition(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000101'),
            PermissionName::fromString('MANAGE_USERS'),
            $tier
        );
    }

    private function currentPermission(): Permission
    {
        $definition = $this->definition(PermissionTier::ADMIN_SAFE);

        return Permission::defineManaged(
            $definition->getId(),
            $definition->getName(),
            $definition->getTier(),
            $this->now()
        );
    }

    /** @phpstan-param list<PermissionId> $ids */
    private function roleDefinition(Role $role, array $ids): ManagedRoleDefinition
    {
        return new ManagedRoleDefinition($role->getId(), $role->getName(), $ids);
    }

    private function agent(): Agent
    {
        return Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentCredentialId::generate(),
            'encrypted:secret',
            $this->now()
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-26T12:00:00+00:00');
    }
}
