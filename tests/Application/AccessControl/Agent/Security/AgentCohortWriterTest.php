<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\CountAgentDeliveryKeyReferencesHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentDeliveryMaintenanceHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentOperationContract;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\MaintenanceEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountAgentDeliveryKeyReferencesHandler::class)]
#[CoversClass(ListAgentDeliveryMaintenanceHandler::class)]
#[CoversClass(AgentOperationContract::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentPermissionAssignmentCoordinator::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AgentProvisioningService::class)]
final class AgentCohortWriterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function directWriters(): iterable
    {
        $paths = [
            'reservation', 'operation', 'delivery', 'maintenance', 'retirement', 'agent', 'lifecycle', 'permission'
        ];
        foreach ($paths as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('directWriters')]
    public function test_direct_repository_paths_cannot_bypass_cohort_rejection(string $path): void
    {
        $env = new DeliveryEnvironment();
        $ports = $env->provisioning;
        $agent = $ports->agents->all()[0];
        $original = $env->operation();
        $revoked = $agent->revoke($env->clock->now());
        $claim = $original->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $env->clock->now()
        );
        $versions = $ports->operations->versions;
        $ports->operations->contract->current = null;
        try {
            $ports->transaction->commitTransactional(static function () use (
                $path,
                $env,
                $ports,
                $agent,
                $original,
                $revoked,
                $claim
            ): void {
                $ports->authorization->authorize($ports->key->getScope(), $ports->request->getDestination());
                match ($path) {
                    'reservation' => $ports->operations->reserveDestinationWrite($ports->request->getDestination()),
                    'operation' => $ports->operations->add($original, new AgentOperationLimits()),
                    'delivery' => $ports->operations->replaceDelivery($original, $claim, $agent),
                    'maintenance' => $ports->operations->replaceMaintenance(
                        $original,
                        $original->expireMaterial($env->clock->now()->modify('+2 days'))
                    ),
                    'retirement' => $ports->operations->retireCredential($agent, $revoked),
                    'agent' => $ports->agents->add($agent),
                    'lifecycle' => $ports->agents->replace($agent, $revoked),
                    'permission' => $ports->agents->replacePermissionAssignments(
                        $agent,
                        $agent->grantPermission(PermissionId::generate(), $env->clock->now())
                    ),
                    default => throw new LogicException('Unknown conformance writer.')
                };
            });
            self::fail('A direct writer bypassed the cohort.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
        }

        self::assertSame([$agent], $ports->agents->all());
        self::assertSame($original, $env->operation());
        self::assertSame($versions, $ports->operations->versions);
        self::assertCount(1, $ports->audit->all());
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);
    }

    public function test_incompatible_cohort_does_not_allow_revocation_or_permission_changes_even_no_ops(): void
    {
        foreach (['revoke credential', 'grant', 'revoke', 'replace'] as $path) {
            $env = new DeliveryEnvironment();
            $ports = $env->provisioning;
            $agent = $ports->agents->all()[0];
            $permissions = new InMemoryPermissionRepository($ports->transaction);
            $permission = Permission::define(
                PermissionId::generate(),
                PermissionName::fromString('CONTENT_PUBLISH'),
                $env->clock->now()
            );
            $permissions->add($permission);
            $coordinator = new AgentPermissionAssignmentCoordinator(
                $ports->agents,
                $permissions,
                $env->clock,
                $ports->transaction
            );
            $ports->operations->contract->current = null;
            $actor = UserId::generate();
            try {
                match ($path) {
                    'grant' => $coordinator->grant($actor, $agent->getId(), $permission->getId()),
                    'revoke' => $coordinator->revoke($actor, $agent->getId(), $permission->getId()),
                    'replace' => $coordinator->replace(
                        $actor,
                        $agent->getId(),
                        $agent->getPermissionAssignmentRevision(),
                        []
                    ),
                    'revoke credential' => new AgentCredentialLifecycleService(
                        $ports->agents,
                        $ports->audit,
                        $env->clock,
                        $ports->transaction,
                        $ports->events
                    )->revoke('operator', $agent->getId())
                };
                self::fail('Every writer requires a qualified current cohort.');
            } catch (AgentOperationRejectedException $failure) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $failure->getReason());
            }

            self::assertSame($agent, $ports->agents->getById($agent->getId()));
            self::assertSame(0, $ports->agents->permissionAssignmentReplacementCalls());
            self::assertCount(1, $ports->audit->all());
        }
    }

    public function test_mismatched_repository_cohorts_reject_before_generation_or_writes(): void
    {
        $env = new ProvisioningEnvironment();
        $agents = new InMemoryAgentRepository($env->transaction);
        $agents->contract->switchTo(InMemoryAgentOperationContract::compatible(2));
        try {
            $env->service(agents: $agents)->provision($env->key, $env->request);
            self::fail('Distinct repository cohorts cannot form a writer.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
        }

        self::assertSame(0, $env->generations);
        self::assertSame([], $agents->all());
        self::assertSame([], $env->operations->operations);
        self::assertSame([], $env->operations->versions);
    }

    public function test_cleanup_switch_back_cannot_acknowledge_an_old_generation(): void
    {
        $env = new MaintenanceEnvironment();
        $delivery = $env->delivery;
        self::assertSame(AgentDeliveryResult::DELIVERED, $delivery->deliver());
        $delivery->revoke();
        $delivery->clock->advance(172801);

        $state = $delivery->provisioning->operations->contract;
        $delivery->sink->afterCleanup = static function () use ($state): void {
            $state->switchTo(InMemoryAgentOperationContract::compatible(2));
            $state->switchTo(InMemoryAgentOperationContract::compatible(3));
        };
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->cleanup());
        self::assertFalse($delivery->operation()->isSinkCleaned());
        self::assertSame(1, $delivery->sink->cleanups);
        $delivery->sink->afterCleanup = null;
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertTrue($delivery->operation()->isSinkCleaned());
    }

    public function test_missing_cohort_blocks_maintenance_keys_cleanup_and_operational_queries(): void
    {
        $env = new MaintenanceEnvironment();
        $delivery = $env->delivery;
        $operations = $delivery->provisioning->operations;
        $operations->contract->current = null;
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->rewrapOriginal());
        self::assertSame(0, $env->rewraps);
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->cleanup());
        self::assertSame(0, $delivery->sink->cleanups);
        self::assertSame(0, $operations->maintenanceWrites);
        $issuance = $delivery->issuance;
        $queries = [
            [
                new ListAgentDeliveryMaintenanceHandler($operations, $env, $delivery->clock),
                new ListAgentDeliveryMaintenance($issuance->getKey()->getScope(), $issuance->getDestination())
            ],
            [
                new CountAgentDeliveryKeyReferencesHandler($operations, $env, $delivery->clock),
                new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('test-key-v1'))
            ]
        ];
        $transactions = $delivery->provisioning->transaction->transactions;
        foreach ($queries as [$handler, $query]) {
            try {
                $handler->handle(QueryMessage::create($query));
                self::fail('Unavailable compatibility cannot return empty work or zero key references.');
            } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
            }
        }

        self::assertSame($transactions, $delivery->provisioning->transaction->transactions);
        self::assertNotNull($delivery->operation()->getMaterial());
    }

    public function test_material_maintenance_holds_cohort_fence_and_rolls_back_on_switch_contention(): void
    {
        $env = new MaintenanceEnvironment();
        $before = $env->delivery->operation();
        $state = $env->delivery->provisioning->operations->contract;
        $env->afterRewrap = static function () use ($state): void {
            $state->switchTo(InMemoryAgentOperationContract::compatible(2));
        };
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->rewrapOriginal());
        self::assertSame($before, $env->delivery->operation());
        self::assertFalse($state->locked);
        $state->switchTo(InMemoryAgentOperationContract::compatible(2));
        $env->afterRewrap = null;
        self::assertSame(AgentMaintenanceResult::REWRAPPED, $env->rewrapOriginal());
    }
}
