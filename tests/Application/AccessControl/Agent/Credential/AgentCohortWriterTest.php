<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\Maintenance\AgentMaintenanceResult;
use Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\CountAgentDeliveryKeyReferencesHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentDeliveryMaintenanceHandler;
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
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Maintenance\MaintenanceEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentOperationContract;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
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
    /** @return iterable<string, array{string, bool}> */
    public static function directWriters(): iterable
    {
        $paths = [
            'reservation', 'operation', 'delivery', 'maintenance', 'retirement', 'agent', 'lifecycle', 'permission'
        ];
        foreach ($paths as $path) {
            yield $path.' missing cohort' => [$path, false];
            yield $path.' unreconciled restore' => [$path, true];
        }
    }

    #[DataProvider('directWriters')]
    public function test_direct_repository_paths_cannot_bypass_cohort_rejection(string $path, bool $restored): void
    {
        $env = new DeliveryEnvironment();
        $ports = $env->provisioning;
        $agent = $ports->agentRepository->all()[0];
        $original = $env->operation();
        $revoked = $agent->revoke($env->clock->now());
        $claim = $original->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $env->clock->now()
        );
        $versions = $ports->agentOperationRepository->versions;
        if ($restored) {
            $ports->agentOperationRepository->contract->reconciledGeneration = null;
        } else {
            $ports->agentOperationRepository->contract->current = null;
        }

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
                $ports->operationAuthorization->authorize($ports->key->getScope(), $ports->request->getDestination());
                match ($path) {
                    'reservation' => $ports->agentOperationRepository->reserveDestinationWrite(
                        $ports->request->getDestination()
                    ),
                    'operation' => $ports->agentOperationRepository->add($original, new AgentOperationLimits()),
                    'delivery' => $ports->agentOperationRepository->replaceDelivery($original, $claim, $agent),
                    'maintenance' => $ports->agentOperationRepository->replaceMaintenance(
                        $original,
                        $original->expireMaterial($env->clock->now()->modify('+2 days'))
                    ),
                    'retirement' => $ports->agentOperationRepository->retireCredential($agent, $revoked),
                    'agent' => $ports->agentRepository->add($agent),
                    'lifecycle' => $ports->agentRepository->replace($agent, $revoked),
                    'permission' => $ports->agentRepository->replacePermissionAssignments(
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

        self::assertSame([$agent], $ports->agentRepository->all());
        self::assertSame($original, $env->operation());
        self::assertSame($versions, $ports->agentOperationRepository->versions);
        self::assertCount(1, $ports->auditEvidenceRepository->all());
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);
    }

    public function test_incompatible_cohort_does_not_allow_revocation_or_permission_changes_even_no_ops(): void
    {
        foreach (['revoke credential', 'grant', 'revoke', 'replace'] as $path) {
            $env = new DeliveryEnvironment();
            $ports = $env->provisioning;
            $agent = $ports->agentRepository->all()[0];
            $permissionRepository = new InMemoryPermissionRepository($ports->transaction);
            $permission = Permission::define(
                PermissionId::generate(),
                PermissionName::fromString('CONTENT_PUBLISH'),
                $env->clock->now()
            );
            $permissionRepository->add($permission);
            $coordinator = new AgentPermissionAssignmentCoordinator(
                $ports->agentRepository,
                $permissionRepository,
                $env->clock,
                $ports->transaction
            );
            $ports->agentOperationRepository->contract->current = null;
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
                        $ports->agentRepository,
                        $ports->auditEvidenceRepository,
                        $env->clock,
                        $ports->transaction,
                        $ports->eventDispatcher
                    )->revoke('operator', $agent->getId())
                };
                self::fail('Every writer requires a qualified current cohort.');
            } catch (AgentOperationRejectedException $failure) {
                self::assertSame(AgentOperationFailure::UNAVAILABLE, $failure->getReason());
            }

            self::assertSame($agent, $ports->agentRepository->getById($agent->getId()));
            self::assertSame(0, $ports->agentRepository->permissionAssignmentReplacementCalls());
            self::assertCount(1, $ports->auditEvidenceRepository->all());
        }
    }

    public function test_mismatched_repository_cohorts_reject_before_generation_or_writes(): void
    {
        $env = new ProvisioningEnvironment();
        $agentRepository = new InMemoryAgentRepository($env->transaction);
        $agentRepository->contract->switchTo(InMemoryAgentOperationContract::compatible(2));
        try {
            $env->service(agentRepository: $agentRepository)->provision($env->key, $env->request);
            self::fail('Distinct repository cohorts cannot form a writer.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
        }

        self::assertSame(0, $env->generations);
        self::assertSame([], $agentRepository->all());
        self::assertSame([], $env->agentOperationRepository->operations);
        self::assertSame([], $env->agentOperationRepository->versions);
    }

    public function test_cleanup_switch_back_cannot_acknowledge_an_old_generation(): void
    {
        $env = new MaintenanceEnvironment();
        $delivery = $env->delivery;
        self::assertSame(AgentDeliveryResult::DELIVERED, $delivery->deliver());
        $delivery->revoke();
        $delivery->clock->advance(172801);

        $state = $delivery->provisioning->agentOperationRepository->contract;
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
        $agentOperationRepository = $delivery->provisioning->agentOperationRepository;
        $agentOperationRepository->contract->current = null;
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->rewrapOriginal());
        self::assertSame(0, $env->rewraps);
        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->cleanup());
        self::assertSame(0, $delivery->sink->cleanups);
        self::assertSame(0, $agentOperationRepository->maintenanceWrites);
        $issuance = $delivery->issuance;
        $queries = [
            [
                new ListAgentDeliveryMaintenanceHandler($agentOperationRepository, $env, $delivery->clock),
                new ListAgentDeliveryMaintenance($issuance->getKey()->getScope(), $issuance->getDestination())
            ],
            [
                new CountAgentDeliveryKeyReferencesHandler($agentOperationRepository, $env, $delivery->clock),
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
        $state = $env->delivery->provisioning->agentOperationRepository->contract;
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
