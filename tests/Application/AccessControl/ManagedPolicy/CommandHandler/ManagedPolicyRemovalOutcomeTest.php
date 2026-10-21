<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ManagedPolicyPlanner::class)]
#[CoversClass(PreviewManagedPolicyHandler::class)]
#[CoversClass(ReconcileManagedPolicyHandler::class)]
final class ManagedPolicyRemovalOutcomeTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function notificationOutcomes(): iterable
    {
        yield 'notification succeeds' => [false];
        yield 'notification fails' => [true];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function precommitOutcomes(): iterable
    {
        foreach (['removal', 'commit'] as $boundary) {
            foreach ([false, true] as $notificationFails) {
                yield $boundary.(int) $notificationFails => [$boundary, $notificationFails];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function referenceWriters(): iterable
    {
        yield 'Role' => ['role'];
        yield 'Agent' => ['agent'];
        yield 'Feature' => ['feature'];
    }

    #[DataProvider('notificationOutcomes')]
    public function test_reference_lookup_failure_propagates_from_preview_and_apply(bool $notificationFails): void
    {
        $unit = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unit);
        $roles = new InMemoryRoleRepository($unit);
        $permission = $this->permission('OBSOLETE');
        $permissions->add($permission);
        $primary = new RuntimeException('Synthetic reference lookup detail.');
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::exactly(2))->method('hasPermissionAssignment')
            ->with($permission->getId())->willThrowException($primary);
        $planner = new ManagedPolicyPlanner($permissions, $roles, new InMemoryUserRepository($unit), $agents);
        $policy = new ManagedPolicy([], []);
        $caught = null;
        try {
            new PreviewManagedPolicyHandler($planner)->handle(QueryMessage::create(new PreviewManagedPolicy($policy)));
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertSame(0, $unit->transactions);
        self::assertSame([$permission], $permissions->getManaged());
        $attempts = [];
        $events = $this->failureEvents($attempts, $notificationFails);
        $caught = null;
        try {
            $this->handler($unit, $permissions, $roles, $planner, $events)->handle(
                CommandMessage::create(new ReconcileManagedPolicy($policy))
            );
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertSame([$permission], $permissions->getManaged());
        self::assertSame([], $roles->getManaged());
        self::assertFalse($unit->transactionCompleted);
        $this->assertFailure($attempts);
    }

    #[DataProvider('precommitOutcomes')]
    public function test_late_persistence_failure_rolls_back_the_complete_policy(
        string $boundary,
        bool $notificationFails
    ): void {
        $unit = new InMemoryUnitOfWork();
        $primary = new RuntimeException('Synthetic persistence detail.');
        $permissions = new InMemoryPermissionRepository(
            $unit,
            beforeRemove: static function () use ($boundary, $primary): void {
                if ($boundary === 'removal') {
                    throw $primary;
                }
            }
        );
        if ($boundary === 'commit') {
            $unit->beforeCommit = static function () use ($primary): void {
                throw $primary;
            };
        }

        $roles = new InMemoryRoleRepository($unit);
        $old = $this->permission('OBSOLETE');
        $permissions->add($old);
        $oldRole = Role::defineManaged(RoleId::generate(), RoleName::fromString('ROLE_OLD'), [], $this->now());
        $roles->add($oldRole);
        $new = $this->permission('NEW_POLICY');
        $newRole = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_NEW'),
            [$new->getId()],
            $this->now()
        );
        $policy = $this->policy($new, $newRole);
        $planner = $this->planner($unit, $permissions, $roles);
        $planner->plan($policy);

        $attempts = [];
        $events = $this->failureEvents($attempts, $notificationFails);
        $caught = null;
        try {
            $this->handler($unit, $permissions, $roles, $planner, $events)->handle(
                CommandMessage::create(new ReconcileManagedPolicy($policy))
            );
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertSame([$old], $permissions->getManaged());
        self::assertSame([$oldRole], $roles->getManaged());
        self::assertNull($permissions->getById($new->getId()));
        self::assertNull($roles->getById($newRole->getId()));
        self::assertFalse($unit->transactionCompleted);
        self::assertFalse($unit->transactionActive);
        $this->assertFailure($attempts);
    }

    #[DataProvider('notificationOutcomes')]
    public function test_publication_failure_keeps_committed_removal_and_original_throwable(
        bool $notificationFails
    ): void {
        $unit = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unit);
        $roles = new InMemoryRoleRepository($unit);
        $old = $this->permission('OBSOLETE');
        $permissions->add($old);
        $new = $this->permission('NEW_POLICY');
        $newRole = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_NEW'),
            [$new->getId()],
            $this->now()
        );
        $policy = $this->policy($new, $newRole);
        $planner = $this->planner($unit, $permissions, $roles);
        $plan = $planner->plan($policy);
        $primary = new RuntimeException('Synthetic publication detail.');
        $attempts = [];
        $events = new InMemoryEventDispatcher(static function (Event $event) use (
            $unit,
            &$attempts,
            $primary,
            $notificationFails
        ): void {
            self::assertTrue($unit->transactionCompleted);
            self::assertFalse($unit->transactionActive);
            $attempts[] = $event;
            if ($event instanceof ManagedPolicyReconciled) {
                throw $primary;
            }

            if ($notificationFails) {
                throw new RuntimeException('Synthetic notification detail.');
            }
        });
        $caught = null;
        try {
            $this->handler($unit, $permissions, $roles, $planner, $events)->handle(
                CommandMessage::create(new ReconcileManagedPolicy($policy))
            );
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertSame($primary, $caught);
        self::assertTrue($unit->transactionCompleted);
        self::assertNull($permissions->getById($old->getId()));
        self::assertSame('NEW_POLICY', $permissions->getById($new->getId())?->getName()->toString());
        self::assertEquals($newRole, $roles->getById($newRole->getId()));
        self::assertCount(2, $attempts);
        self::assertInstanceOf(ManagedPolicyReconciled::class, $attempts[0]);
        self::assertSame($plan->toArray(), $attempts[0]->getPlan());
        $this->assertFailure([$attempts[1]]);
    }

    #[DataProvider('referenceWriters')]
    public function test_removal_winner_rejects_a_later_reference_using_a_stale_permission(string $writer): void
    {
        $unit = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unit);
        $roles = new InMemoryRoleRepository($unit);
        $agents = new InMemoryAgentRepository($unit);
        $features = new InMemoryFeatureRepository($unit);
        $old = $this->permission('OBSOLETE');
        $permissions->add($old);
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_CUSTOM'), [], $this->now());
        $roles->add($role);
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Policy fixture'),
            AgentCredentialId::generate(),
            'fixture-encrypted-envelope',
            $this->now()
        );
        $agents->add($agent);
        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('policy-fixture'), $old->getId());
        $planner = new ManagedPolicyPlanner($permissions, $roles, new InMemoryUserRepository($unit), $agents);
        $policy = new ManagedPolicy([], []);
        $preview = new PreviewManagedPolicyHandler($planner)->handle(
            QueryMessage::create(new PreviewManagedPolicy($policy))
        );
        $events = new InMemoryEventDispatcher();
        $this->handler($unit, $permissions, $roles, $planner, $events)->handle(
            CommandMessage::create(new ReconcileManagedPolicy($policy))
        );
        self::assertNull($permissions->getById($old->getId()));
        self::assertInstanceOf(ManagedPolicyReconciled::class, $events->events()[0]);
        self::assertSame($preview->toArray(), $events->events()[0]->getPlan());

        $now = $this->now();
        $unit->commitTransactional(static function () use (
            $writer,
            $roles,
            $role,
            $agents,
            $agent,
            $features,
            $feature,
            $old,
            $now
        ): void {
            if ($writer === 'role') {
                self::assertFalse($roles->replace($role, $role->grantPermissionToCustom($old->getId(), $now)));
            } elseif ($writer === 'agent') {
                self::assertFalse($agents->replacePermissionAssignments(
                    $agent,
                    $agent->grantPermission($old->getId(), $now)
                ));
            } else {
                try {
                    $features->add($feature);
                    self::fail('A removed Permission cannot acquire a Feature reference.');
                } catch (FeatureReferenceException) {
                    self::assertNull($features->getById($feature->getId()));
                }
            }
        });

        self::assertSame($role, $roles->getById($role->getId()));
        self::assertSame($agent, $agents->getById($agent->getId()));
        self::assertSame([], $features->records);
        self::assertNull($permissions->getById($old->getId()));
        self::assertCount(1, $events->events());
    }

    /** @param list<Event> $attempts */
    private function assertFailure(array $attempts): void
    {
        self::assertCount(1, $attempts);
        self::assertInstanceOf(CommandFailedEvent::class, $attempts[0]);
        self::assertSame('Managed policy reconciliation failed.', $attempts[0]->getErrorMessage());
        self::assertSame($attempts[0]->toArray(), CommandFailedEvent::fromArray($attempts[0]->toArray())->toArray());
    }

    /** @param list<Event> $attempts */
    private function failureEvents(array &$attempts, bool $notificationFails): InMemoryEventDispatcher
    {
        return new InMemoryEventDispatcher(static function (Event $event) use (&$attempts, $notificationFails): void {
            $attempts[] = $event;
            if ($notificationFails) {
                throw new RuntimeException('Synthetic notification detail.');
            }
        });
    }

    private function handler(
        InMemoryUnitOfWork $unit,
        InMemoryPermissionRepository $permissions,
        InMemoryRoleRepository $roles,
        ManagedPolicyPlanner $planner,
        InMemoryEventDispatcher $events
    ): ReconcileManagedPolicyHandler {
        return new ReconcileManagedPolicyHandler(
            $permissions,
            $roles,
            $planner,
            $unit,
            $events,
            new FixedClock($this->now())
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-23T12:00:00+00:00');
    }

    private function permission(string $name): Permission
    {
        return Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString($name),
            PermissionTier::ADMIN_SAFE,
            $this->now()
        );
    }

    private function planner(
        InMemoryUnitOfWork $unit,
        InMemoryPermissionRepository $permissions,
        InMemoryRoleRepository $roles
    ): ManagedPolicyPlanner {
        return new ManagedPolicyPlanner(
            $permissions,
            $roles,
            new InMemoryUserRepository($unit),
            new InMemoryAgentRepository($unit)
        );
    }

    private function policy(Permission $permission, Role $role): ManagedPolicy
    {
        return new ManagedPolicy([
            new ManagedPermissionDefinition($permission->getId(), $permission->getName(), $permission->getManagedTier())
        ], [
            new ManagedRoleDefinition($role->getId(), $role->getName(), $role->getPermissionIds())
        ]);
    }
}
