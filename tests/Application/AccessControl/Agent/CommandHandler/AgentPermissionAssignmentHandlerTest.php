<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\GrantPermissionToAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ReplaceAgentPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RevokePermissionFromAgentHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RevokePermissionFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionGrantedToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionRevokedFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedAgentPermissionAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GrantPermissionToAgentHandler::class)]
#[CoversClass(AgentPermissionAssignmentCoordinator::class)]
#[CoversClass(RevokePermissionFromAgentHandler::class)]
#[CoversClass(ReplaceAgentPermissionsHandler::class)]
#[CoversClass(GrantPermissionToAgent::class)]
#[CoversClass(RevokePermissionFromAgent::class)]
#[CoversClass(ReplaceAgentPermissions::class)]
#[CoversClass(PermissionGrantedToAgent::class)]
#[CoversClass(PermissionRevokedFromAgent::class)]
#[CoversClass(AgentPermissionsReplaced::class)]
#[CoversClass(Agent::class)]
#[CoversClass(AgentPermissionAssignmentAuthorizationException::class)]
#[CoversClass(AgentPermissionAssignmentException::class)]
final class AgentPermissionAssignmentHandlerTest extends TestCase
{
    private const string NOW = '2026-08-26T12:00:00+00:00';

    public function test_grant_persists_then_commits_then_publishes_without_changing_credential_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($unitOfWork);
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'consumer-encrypted-hmac-shared-secret-envelope',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $agents->add($agent);
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permission = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('CONTENT_PUBLISH'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $permissions->add($permission);
        $actorId = UserId::generate();
        $authorization = new FixedAgentPermissionAdministrationAuthorization(true);
        $events = new InMemoryEventDispatcher(
            static function ($event) use ($agent, $agents, $permission, $unitOfWork): void {
                self::assertInstanceOf(PermissionGrantedToAgent::class, $event);
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertTrue($agents->getById($agent->getId())?->hasPermission($permission->getId()));
            }
        );
        $handler = new GrantPermissionToAgentHandler(
            $agents,
            $permissions,
            $authorization,
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        $handler->handle(
            CommandMessage::create(new GrantPermissionToAgent($actorId, $agent->getId(), $permission->getId()))
        );

        $stored = $agents->getById($agent->getId());
        self::assertInstanceOf(Agent::class, $stored);
        self::assertTrue($stored->hasPermission($permission->getId()));
        self::assertSame([$permission->getId()], $stored->getPermissionIds());
        self::assertSame(2, $stored->getPermissionAssignmentRevision());
        self::assertSame($agent->getState(), $stored->getState());
        self::assertSame($agent->getCredentialId(), $stored->getCredentialId());
        self::assertSame($agent->getCredentialRevision(), $stored->getCredentialRevision());
        self::assertSame(
            $agent->getEncryptedHmacSharedSecretEnvelope(),
            $stored->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(1, $authorization->calls());
        self::assertTrue($authorization->lastActorId()?->equals($actorId));
        self::assertCount(1, $events->events());
        $event = $events->events()[0];
        self::assertInstanceOf(PermissionGrantedToAgent::class, $event);
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($agent->getId(), $event->getAgentId());
        self::assertSame($permission->getId(), $event->getPermissionId());
        self::assertSame(self::NOW, $event->getGrantedAt()->format(DATE_ATOM));
    }

    public function test_revoke_persists_then_commits_then_publishes_without_changing_credential_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($unitOfWork);
        $permission = $this->permission();
        $agent = $this->agent()->grantPermission(
            $permission->getId(),
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        );
        $agents->add($agent);
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($permission);

        $actorId = UserId::generate();
        $authorization = new FixedAgentPermissionAdministrationAuthorization(true);
        $events = new InMemoryEventDispatcher(
            static function ($event) use ($agent, $agents, $permission, $unitOfWork): void {
                self::assertInstanceOf(PermissionRevokedFromAgent::class, $event);
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertFalse($agents->getById($agent->getId())?->hasPermission($permission->getId()));
            }
        );
        $handler = new RevokePermissionFromAgentHandler(
            $agents,
            $permissions,
            $authorization,
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        $handler->handle(
            CommandMessage::create(new RevokePermissionFromAgent($actorId, $agent->getId(), $permission->getId()))
        );

        $stored = $agents->getById($agent->getId());
        self::assertInstanceOf(Agent::class, $stored);
        self::assertFalse($stored->hasPermission($permission->getId()));
        self::assertSame([], $stored->getPermissionIds());
        self::assertSame(3, $stored->getPermissionAssignmentRevision());
        self::assertSame($agent->getState(), $stored->getState());
        self::assertSame($agent->getCredentialId(), $stored->getCredentialId());
        self::assertSame($agent->getCredentialRevision(), $stored->getCredentialRevision());
        self::assertSame(
            $agent->getEncryptedHmacSharedSecretEnvelope(),
            $stored->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(1, $authorization->calls());
        self::assertCount(1, $events->events());
        $event = $events->events()[0];
        self::assertInstanceOf(PermissionRevokedFromAgent::class, $event);
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($agent->getId(), $event->getAgentId());
        self::assertSame($permission->getId(), $event->getPermissionId());
        self::assertSame(self::NOW, $event->getRevokedAt()->format(DATE_ATOM));
    }

    public function test_complete_set_replacement_persists_then_commits_then_publishes_one_revision_change(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $existingPermission = $this->permission();
        $replacementPermission = $this->permission('CONTENT_REVIEW');
        $agent = $this->agent()->grantPermission(
            $existingPermission->getId(),
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        );
        $agents = new InMemoryAgentRepository($unitOfWork);
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($existingPermission);
        $permissions->add($replacementPermission);

        $actorId = UserId::generate();
        $events = new InMemoryEventDispatcher(
            static function ($event) use ($agent, $agents, $replacementPermission, $unitOfWork): void {
                self::assertInstanceOf(AgentPermissionsReplaced::class, $event);
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertSame(
                    [$replacementPermission->getId()],
                    $agents->getById($agent->getId())?->getPermissionIds()
                );
            }
        );
        $handler = new ReplaceAgentPermissionsHandler(
            $agents,
            $permissions,
            new FixedAgentPermissionAdministrationAuthorization(true),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        $handler->handle(CommandMessage::create(new ReplaceAgentPermissions(
            $actorId,
            $agent->getId(),
            2,
            [$replacementPermission->getId()]
        )));

        $stored = $agents->getById($agent->getId());
        self::assertInstanceOf(Agent::class, $stored);
        self::assertSame([$replacementPermission->getId()], $stored->getPermissionIds());
        self::assertSame(3, $stored->getPermissionAssignmentRevision());
        self::assertSame($agent->getCredentialId(), $stored->getCredentialId());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(1, $agents->permissionAssignmentReplacementCalls());
        self::assertCount(1, $events->events());
        $event = $events->events()[0];
        self::assertInstanceOf(AgentPermissionsReplaced::class, $event);
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($agent->getId(), $event->getAgentId());
        self::assertSame([$replacementPermission->getId()], $event->getPermissionIds());
        self::assertSame(3, $event->getPermissionAssignmentRevision());
        self::assertSame(self::NOW, $event->getReplacedAt()->format(DATE_ATOM));
    }

    public function test_identical_complete_set_is_a_successful_no_op_and_empty_set_is_a_change(): void
    {
        foreach (['identical', 'empty'] as $case) {
            $unitOfWork = new InMemoryUnitOfWork();
            $firstPermission = $this->permission();
            $secondPermission = $this->permission('CONTENT_REVIEW');
            $agent = $this->agent()->grantPermission(
                $firstPermission->getId(),
                new DateTimeImmutable('2026-08-25T13:00:00+00:00')
            );
            if ($case === 'identical') {
                $agent = $agent->grantPermission(
                    $secondPermission->getId(),
                    new DateTimeImmutable('2026-08-25T14:00:00+00:00')
                );
            }

            $agents = new InMemoryAgentRepository($unitOfWork);
            $agents->add($agent);
            $permissions = new InMemoryPermissionRepository($unitOfWork);
            $permissions->add($firstPermission);
            $permissions->add($secondPermission);
            $requestedIds = [];
            if ($case === 'identical') {
                $requestedIds = [$secondPermission->getId(), $firstPermission->getId()];
            }

            $events = new InMemoryEventDispatcher();
            $handler = new ReplaceAgentPermissionsHandler(
                $agents,
                $permissions,
                new FixedAgentPermissionAdministrationAuthorization(true),
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            );

            $handler->handle(CommandMessage::create(new ReplaceAgentPermissions(
                UserId::generate(),
                $agent->getId(),
                $agent->getPermissionAssignmentRevision(),
                $requestedIds
            )));

            $stored = $agents->getById($agent->getId());
            self::assertInstanceOf(Agent::class, $stored);
            if ($case === 'identical') {
                self::assertSame($agent, $stored);
                self::assertSame(
                    [$firstPermission->getId(), $secondPermission->getId()],
                    $stored->getPermissionIds()
                );
                self::assertSame(3, $stored->getPermissionAssignmentRevision());
                self::assertSame(0, $agents->permissionAssignmentReplacementCalls());
            } else {
                self::assertSame([], $stored->getPermissionIds());
                self::assertSame(3, $stored->getPermissionAssignmentRevision());
                self::assertSame(1, $agents->permissionAssignmentReplacementCalls());
            }

            self::assertSame(1, $unitOfWork->transactions);
            if ($case === 'identical') {
                self::assertCount(0, $events->events());
            } else {
                self::assertInstanceOf(AgentPermissionsReplaced::class, $events->events()[0]);
            }
        }
    }

    public function test_invalid_complete_set_requests_fail_without_partial_change(): void
    {
        foreach (
            ['authorization', 'missing-agent', 'stale-revision', 'unknown-permission', 'cas-loss'] as $case
        ) {
            $unitOfWork = new InMemoryUnitOfWork();
            $existingPermission = $this->permission();
            $requestedPermission = $this->permission('CONTENT_REVIEW');
            $agent = $this->agent()->grantPermission(
                $existingPermission->getId(),
                new DateTimeImmutable('2026-08-25T13:00:00+00:00')
            );
            $agents = new InMemoryAgentRepository(
                $unitOfWork,
                replacePermissionAssignmentsSucceeds: $case !== 'cas-loss'
            );
            if ($case !== 'missing-agent') {
                $agents->add($agent);
            }

            $permissions = new InMemoryPermissionRepository($unitOfWork);
            $permissions->add($existingPermission);
            if ($case !== 'unknown-permission') {
                $permissions->add($requestedPermission);
            }

            $requestedIds = [$requestedPermission->getId()];
            $events = new InMemoryEventDispatcher();
            $handler = new ReplaceAgentPermissionsHandler(
                $agents,
                $permissions,
                new FixedAgentPermissionAdministrationAuthorization($case !== 'authorization'),
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            );

            try {
                $handler->handle(CommandMessage::create(new ReplaceAgentPermissions(
                    UserId::generate(),
                    $agent->getId(),
                    $case === 'stale-revision' ? 1 : 2,
                    $requestedIds
                )));
                self::fail(sprintf('Invalid complete-set replacement case "%s" was accepted.', $case));
            } catch (AgentPermissionAssignmentException) {
                $stored = $agents->getById($agent->getId());
                if ($case === 'missing-agent') {
                    self::assertNull($stored);
                } else {
                    self::assertInstanceOf(Agent::class, $stored);
                    self::assertSame([$existingPermission->getId()], $stored->getPermissionIds());
                    self::assertSame(2, $stored->getPermissionAssignmentRevision());
                }

                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_incomplete_or_mismatched_bulk_permission_results_fail_before_persistence(): void
    {
        foreach (['incomplete', 'mismatched'] as $case) {
            $unitOfWork = new InMemoryUnitOfWork();
            $firstPermission = $this->permission();
            $secondPermission = $this->permission('CONTENT_REVIEW');
            $wrongPermission = $this->permission('CONTENT_DELETE');
            $agent = $this->agent();
            $agents = new InMemoryAgentRepository($unitOfWork);
            $agents->add($agent);
            $permissions = new InMemoryPermissionRepository(
                $unitOfWork,
                getByIdsResult: static function () use ($case, $firstPermission, $wrongPermission): array {
                    if ($case === 'incomplete') {
                        return [$firstPermission];
                    }

                    return [$firstPermission, $wrongPermission];
                }
            );
            $permissions->add($firstPermission);
            $permissions->add($secondPermission);
            $events = new InMemoryEventDispatcher();
            $handler = new ReplaceAgentPermissionsHandler(
                $agents,
                $permissions,
                new FixedAgentPermissionAdministrationAuthorization(true),
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            );

            try {
                $handler->handle(CommandMessage::create(new ReplaceAgentPermissions(
                    UserId::generate(),
                    $agent->getId(),
                    1,
                    [$firstPermission->getId(), $secondPermission->getId()]
                )));
                self::fail(sprintf('Invalid bulk Permission result "%s" was accepted.', $case));
            } catch (AgentPermissionAssignmentException) {
                self::assertSame(0, $agents->permissionAssignmentReplacementCalls());
                self::assertSame([], $agents->getById($agent->getId())?->getPermissionIds());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_complete_set_repository_failure_is_rethrown_by_identity_and_reported(): void
    {
        $expectedFailure = new RuntimeException('Complete Agent Permission replacement failed.');
        $unitOfWork = new InMemoryUnitOfWork();
        $requestedPermission = $this->permission();
        $agent = $this->agent();
        $agents = new InMemoryAgentRepository(
            $unitOfWork,
            replacePermissionAssignmentsFailure: $expectedFailure
        );
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($requestedPermission);

        $events = new InMemoryEventDispatcher();
        $handler = new ReplaceAgentPermissionsHandler(
            $agents,
            $permissions,
            new FixedAgentPermissionAdministrationAuthorization(true),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(CommandMessage::create(new ReplaceAgentPermissions(
                UserId::generate(),
                $agent->getId(),
                1,
                [$requestedPermission->getId()]
            )));
            self::fail('A complete-set repository failure was swallowed.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($expectedFailure, $runtimeException);
            $stored = $agents->getById($agent->getId());
            self::assertInstanceOf(Agent::class, $stored);
            self::assertSame([], $stored->getPermissionIds());
            self::assertSame(1, $stored->getPermissionAssignmentRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_command_and_success_event_round_trip_and_reject_every_missing_required_value(): void
    {
        self::assertSame(GrantPermissionToAgent::class, GrantPermissionToAgentHandler::commandRegistration());
        self::assertSame(RevokePermissionFromAgent::class, RevokePermissionFromAgentHandler::commandRegistration());
        self::assertSame(ReplaceAgentPermissions::class, ReplaceAgentPermissionsHandler::commandRegistration());
        $actorId = UserId::generate();
        $agentId = AgentId::generate();
        $permissionId = PermissionId::generate();
        $grantedAt = new DateTimeImmutable(self::NOW);
        $command = new GrantPermissionToAgent($actorId, $agentId, $permissionId);
        $event = new PermissionGrantedToAgent($actorId, $agentId, $permissionId, $grantedAt);
        $revoke = new RevokePermissionFromAgent($actorId, $agentId, $permissionId);
        $revoked = new PermissionRevokedFromAgent($actorId, $agentId, $permissionId, $grantedAt);
        $replace = new ReplaceAgentPermissions($actorId, $agentId, 7, [$permissionId]);
        $replaced = new AgentPermissionsReplaced($actorId, $agentId, [$permissionId], 8, $grantedAt);

        self::assertEquals($command, GrantPermissionToAgent::fromArray($command->toArray()));
        self::assertEquals($event, PermissionGrantedToAgent::fromArray($event->toArray()));
        self::assertEquals($revoke, RevokePermissionFromAgent::fromArray($revoke->toArray()));
        self::assertEquals($revoked, PermissionRevokedFromAgent::fromArray($revoked->toArray()));
        self::assertEquals($replace, ReplaceAgentPermissions::fromArray($replace->toArray()));
        self::assertEquals($replaced, AgentPermissionsReplaced::fromArray($replaced->toArray()));
        self::assertSame($actorId, $command->getActorId());
        self::assertSame($agentId, $command->getAgentId());
        self::assertSame($permissionId, $command->getPermissionId());
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($agentId, $event->getAgentId());
        self::assertSame($permissionId, $event->getPermissionId());
        self::assertSame($grantedAt, $event->getGrantedAt());
        self::assertSame($actorId, $revoke->getActorId());
        self::assertSame($agentId, $revoke->getAgentId());
        self::assertSame($permissionId, $revoke->getPermissionId());
        self::assertSame($actorId, $revoked->getActorId());
        self::assertSame($agentId, $revoked->getAgentId());
        self::assertSame($permissionId, $revoked->getPermissionId());
        self::assertSame($grantedAt, $revoked->getRevokedAt());
        self::assertSame($actorId, $replace->getActorId());
        self::assertSame($agentId, $replace->getAgentId());
        self::assertSame(7, $replace->getExpectedPermissionAssignmentRevision());
        self::assertSame([$permissionId], $replace->getPermissionIds());
        self::assertSame($actorId, $replaced->getActorId());
        self::assertSame($agentId, $replaced->getAgentId());
        self::assertSame([$permissionId], $replaced->getPermissionIds());
        self::assertSame(8, $replaced->getPermissionAssignmentRevision());
        self::assertSame($grantedAt, $replaced->getReplacedAt());

        $cases = [
            [GrantPermissionToAgent::class, ['actor_id', 'agent_id', 'permission_id']],
            [RevokePermissionFromAgent::class, ['actor_id', 'agent_id', 'permission_id']],
            [ReplaceAgentPermissions::class, [
                'actor_id',
                'agent_id',
                'expected_permission_assignment_revision',
                'permission_ids',
            ]],
            [PermissionGrantedToAgent::class, ['actor_id', 'agent_id', 'permission_id', 'granted_at']],
            [PermissionRevokedFromAgent::class, ['actor_id', 'agent_id', 'permission_id', 'revoked_at']],
            [AgentPermissionsReplaced::class, [
                'actor_id',
                'agent_id',
                'permission_ids',
                'permission_assignment_revision',
                'replaced_at',
            ]],
        ];
        foreach ($cases as [$type, $keys]) {
            foreach ($keys as $missing) {
                $data = [
                    'actor_id' => $actorId->toString(),
                    'agent_id' => $agentId->toString(),
                    'permission_id' => $permissionId->toString(),
                    'granted_at' => self::NOW,
                    'revoked_at' => self::NOW,
                    'expected_permission_assignment_revision' => 7,
                    'permission_ids' => [$permissionId->toString()],
                    'permission_assignment_revision' => 8,
                    'replaced_at' => self::NOW,
                ];
                unset($data[$missing]);

                try {
                    $type::fromArray($data);
                    self::fail('Missing Agent Permission message data was accepted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function test_authorization_denial_is_reported_and_leaves_assignments_unchanged(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($unitOfWork);
        $agent = $this->agent();
        $agents->add($agent);
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permission = $this->permission();
        $permissions->add($permission);
        $events = new InMemoryEventDispatcher();
        $handler = new GrantPermissionToAgentHandler(
            $agents,
            $permissions,
            new FixedAgentPermissionAdministrationAuthorization(false),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(
                CommandMessage::create(
                    new GrantPermissionToAgent(UserId::generate(), $agent->getId(), $permission->getId())
                )
            );
            self::fail('Unauthorized Agent Permission administration was accepted.');
        } catch (AgentPermissionAssignmentAuthorizationException $agentPermissionAssignmentAuthorizationException) {
            self::assertSame(
                'Agent Permission-assignment administration is not authorized.',
                $agentPermissionAssignmentAuthorizationException->getMessage()
            );
            $stored = $agents->getById($agent->getId());
            self::assertInstanceOf(Agent::class, $stored);
            self::assertSame([], $stored->getPermissionIds());
            self::assertSame(1, $stored->getPermissionAssignmentRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_revoke_authorization_denial_is_reported_and_leaves_assignments_unchanged(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permission = $this->permission();
        $agent = $this->agent()->grantPermission(
            $permission->getId(),
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        );
        $agents = new InMemoryAgentRepository($unitOfWork);
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($permission);

        $events = new InMemoryEventDispatcher();
        $handler = new RevokePermissionFromAgentHandler(
            $agents,
            $permissions,
            new FixedAgentPermissionAdministrationAuthorization(false),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(
                CommandMessage::create(
                    new RevokePermissionFromAgent(UserId::generate(), $agent->getId(), $permission->getId())
                )
            );
            self::fail('Unauthorized Agent Permission revocation was accepted.');
        } catch (AgentPermissionAssignmentAuthorizationException) {
            $stored = $agents->getById($agent->getId());
            self::assertInstanceOf(Agent::class, $stored);
            self::assertTrue($stored->hasPermission($permission->getId()));
            self::assertSame(2, $stored->getPermissionAssignmentRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_invalid_or_concurrent_grants_fail_without_partial_change(): void
    {
        foreach (['missing-agent', 'unknown-permission', 'cas-loss'] as $case) {
            $unitOfWork = new InMemoryUnitOfWork();
            $agents = new InMemoryAgentRepository(
                $unitOfWork,
                replacePermissionAssignmentsSucceeds: $case !== 'cas-loss'
            );
            $permission = $this->permission();
            $agent = $this->agent();
            if ($case !== 'missing-agent') {
                $agents->add($agent);
            }

            $permissions = new InMemoryPermissionRepository($unitOfWork);
            if ($case !== 'unknown-permission') {
                $permissions->add($permission);
            }

            $events = new InMemoryEventDispatcher();
            $handler = new GrantPermissionToAgentHandler(
                $agents,
                $permissions,
                new FixedAgentPermissionAdministrationAuthorization(true),
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            );
            $beforeIds = $agent->getPermissionIds();
            $beforeRevision = $agent->getPermissionAssignmentRevision();

            try {
                $handler->handle(
                    CommandMessage::create(
                        new GrantPermissionToAgent(UserId::generate(), $agent->getId(), $permission->getId())
                    )
                );
                self::fail(sprintf('Invalid Agent Permission grant case "%s" was accepted.', $case));
            } catch (AgentPermissionAssignmentException) {
                self::assertSame(
                    $case === 'missing-agent' ? [] : $beforeIds,
                    $agents->getById($agent->getId())?->getPermissionIds() ?? []
                );
                self::assertSame(
                    $case === 'missing-agent' ? null : $beforeRevision,
                    $agents->getById($agent->getId())?->getPermissionAssignmentRevision()
                );
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_invalid_or_concurrent_revokes_fail_without_partial_change(): void
    {
        foreach (['missing-agent', 'unknown-permission', 'cas-loss'] as $case) {
            $unitOfWork = new InMemoryUnitOfWork();
            $agents = new InMemoryAgentRepository(
                $unitOfWork,
                replacePermissionAssignmentsSucceeds: $case !== 'cas-loss'
            );
            $permission = $this->permission();
            $agent = $this->agent();
            $agent = $agent->grantPermission(
                $permission->getId(),
                new DateTimeImmutable('2026-08-25T13:00:00+00:00')
            );

            if ($case !== 'missing-agent') {
                $agents->add($agent);
            }

            $permissions = new InMemoryPermissionRepository($unitOfWork);
            if ($case !== 'unknown-permission') {
                $permissions->add($permission);
            }

            $events = new InMemoryEventDispatcher();
            $handler = new RevokePermissionFromAgentHandler(
                $agents,
                $permissions,
                new FixedAgentPermissionAdministrationAuthorization(true),
                new FixedClock(self::NOW),
                $unitOfWork,
                $events
            );
            $beforeIds = $agent->getPermissionIds();
            $beforeRevision = $agent->getPermissionAssignmentRevision();

            try {
                $handler->handle(
                    CommandMessage::create(
                        new RevokePermissionFromAgent(UserId::generate(), $agent->getId(), $permission->getId())
                    )
                );
                self::fail(sprintf('Invalid Agent Permission revoke case "%s" was accepted.', $case));
            } catch (AgentPermissionAssignmentException) {
                self::assertSame(
                    $case === 'missing-agent' ? [] : $beforeIds,
                    $agents->getById($agent->getId())?->getPermissionIds() ?? []
                );
                self::assertSame(
                    $case === 'missing-agent' ? null : $beforeRevision,
                    $agents->getById($agent->getId())?->getPermissionAssignmentRevision()
                );
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_already_satisfied_commands_have_no_write_or_success_event(): void
    {
        foreach (['grant', 'revoke', 'replace'] as $case) {
            $unitOfWork = new InMemoryUnitOfWork();
            $firstPermission = $this->permission();
            $secondPermission = $this->permission('CONTENT_REVIEW');
            $agent = $this->agent();
            if ($case !== 'revoke') {
                $agent = $agent->grantPermission($firstPermission->getId(), new DateTimeImmutable(self::NOW));
            }

            if ($case === 'replace') {
                $agent = $agent->grantPermission($secondPermission->getId(), new DateTimeImmutable(self::NOW));
            }

            $agents = new InMemoryAgentRepository($unitOfWork);
            $agents->add($agent);
            $permissions = new InMemoryPermissionRepository($unitOfWork);
            $permissions->add($firstPermission);
            $permissions->add($secondPermission);
            $authorization = new FixedAgentPermissionAdministrationAuthorization(true);
            $events = new InMemoryEventDispatcher();
            $beforeUpdatedAt = $agent->getUpdatedAt();

            match ($case) {
                'grant' => new GrantPermissionToAgentHandler(
                    $agents,
                    $permissions,
                    $authorization,
                    new FixedClock(self::NOW),
                    $unitOfWork,
                    $events
                )->handle(CommandMessage::create(
                    new GrantPermissionToAgent(UserId::generate(), $agent->getId(), $firstPermission->getId())
                )),
                'revoke' => new RevokePermissionFromAgentHandler(
                    $agents,
                    $permissions,
                    $authorization,
                    new FixedClock(self::NOW),
                    $unitOfWork,
                    $events
                )->handle(CommandMessage::create(
                    new RevokePermissionFromAgent(UserId::generate(), $agent->getId(), $firstPermission->getId())
                )),
                'replace' => new ReplaceAgentPermissionsHandler(
                    $agents,
                    $permissions,
                    $authorization,
                    new FixedClock(self::NOW),
                    $unitOfWork,
                    $events
                )->handle(CommandMessage::create(new ReplaceAgentPermissions(
                    UserId::generate(),
                    $agent->getId(),
                    $agent->getPermissionAssignmentRevision(),
                    [$secondPermission->getId(), $firstPermission->getId(), $firstPermission->getId()]
                ))),
            };

            $stored = $agents->getById($agent->getId());
            self::assertSame($agent, $stored);
            self::assertSame($beforeUpdatedAt, $stored->getUpdatedAt());
            self::assertSame(0, $agents->permissionAssignmentReplacementCalls());
            self::assertSame(1, $unitOfWork->transactions);
            self::assertSame(1, $authorization->calls());
            self::assertCount(0, $events->events());
        }
    }

    public function test_permission_must_remain_authoritative_at_the_fenced_persistence_boundary(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $permission = $this->permission();
        $agents = new InMemoryAgentRepository(
            $unitOfWork,
            beforeReplacePermissionAssignments: static function () use ($permission, $unitOfWork): void {
                self::assertTrue($unitOfWork->authorizationReferenceState()->isReferenceFenceHeld());
                $unitOfWork->authorizationReferenceState()->removePermission($permission->getId());
            }
        );
        $agent = $this->agent();
        $agents->add($agent);
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($permission);

        $events = new InMemoryEventDispatcher();
        $handler = new GrantPermissionToAgentHandler(
            $agents,
            $permissions,
            new FixedAgentPermissionAdministrationAuthorization(true),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(
                CommandMessage::create(
                    new GrantPermissionToAgent(UserId::generate(), $agent->getId(), $permission->getId())
                )
            );
            self::fail('A Permission that lost authority before persistence was assigned.');
        } catch (AgentPermissionAssignmentException) {
            $stored = $agents->getById($agent->getId());
            self::assertInstanceOf(Agent::class, $stored);
            self::assertSame([], $stored->getPermissionIds());
            self::assertSame(1, $stored->getPermissionAssignmentRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_dependency_failure_is_rethrown_by_identity_and_reported(): void
    {
        $expectedFailure = new RuntimeException('authorization failed');
        $unitOfWork = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($unitOfWork);
        $agent = $this->agent();
        $agents->add($agent);
        $permission = $this->permission();
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($permission);

        $events = new InMemoryEventDispatcher();
        $handler = new GrantPermissionToAgentHandler(
            $agents,
            $permissions,
            new FixedAgentPermissionAdministrationAuthorization(true, $expectedFailure),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(
                CommandMessage::create(
                    new GrantPermissionToAgent(UserId::generate(), $agent->getId(), $permission->getId())
                )
            );
            self::fail('A dependency failure was swallowed.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($expectedFailure, $runtimeException);
            self::assertSame([], $agents->getById($agent->getId())?->getPermissionIds());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_revoke_repository_failure_is_rethrown_by_identity_and_reported(): void
    {
        $expectedFailure = new RuntimeException('Agent Permission replacement failed.');
        $unitOfWork = new InMemoryUnitOfWork();
        $permission = $this->permission();
        $agent = $this->agent()->grantPermission(
            $permission->getId(),
            new DateTimeImmutable('2026-08-25T13:00:00+00:00')
        );
        $agents = new InMemoryAgentRepository(
            $unitOfWork,
            replacePermissionAssignmentsFailure: $expectedFailure
        );
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add($permission);

        $events = new InMemoryEventDispatcher();
        $handler = new RevokePermissionFromAgentHandler(
            $agents,
            $permissions,
            new FixedAgentPermissionAdministrationAuthorization(true),
            new FixedClock(self::NOW),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(
                CommandMessage::create(
                    new RevokePermissionFromAgent(UserId::generate(), $agent->getId(), $permission->getId())
                )
            );
            self::fail('An Agent repository failure was swallowed.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($expectedFailure, $runtimeException);
            $stored = $agents->getById($agent->getId());
            self::assertInstanceOf(Agent::class, $stored);
            self::assertTrue($stored->hasPermission($permission->getId()));
            self::assertSame(2, $stored->getPermissionAssignmentRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    private function agent(): Agent
    {
        return Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'consumer-encrypted-hmac-shared-secret-envelope',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }

    private function permission(string $name = 'CONTENT_PUBLISH'): Permission
    {
        return Permission::define(
            PermissionId::generate(),
            PermissionName::fromString($name),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }
}
