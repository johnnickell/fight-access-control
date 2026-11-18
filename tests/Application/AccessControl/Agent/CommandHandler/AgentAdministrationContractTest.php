<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\GrantPermissionToAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ReplaceAgentPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RevokePermissionFromAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RevokePermissionFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(GrantPermissionToAgentHandler::class)]
#[CoversClass(RevokePermissionFromAgentHandler::class)]
#[CoversClass(ReplaceAgentPermissionsHandler::class)]
#[CoversClass(UpdateAgentHandler::class)]
#[CoversClass(AgentPermissionAssignmentCoordinator::class)]
final class AgentAdministrationContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['grant', 'revoke', 'replace'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function test_wrong_agent_identity_is_internal_even_for_an_apparent_noop(string $operation): void
    {
        $permission = $this->permission();
        $wrong = $this->agent();
        if ($operation !== 'revoke') {
            $wrong = $wrong->grantPermission($permission->getId(), new DateTimeImmutable('2030-01-01'));
        }

        $agents = $this->createMock(AgentRepository::class);
        $contract = new InMemoryAgentRepository()->getOperationContract();
        $agents->method('getOperationContract')->willReturn($contract);
        $agents->method('getById')->willReturn($wrong);
        $agents->expects(self::never())->method('replacePermissionAssignments');
        $agents->method('validatePermissionAssignments')->willReturn(true);
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willReturn($permission);
        $permissions->method('getByIds')->willReturn([$permission]);
        [$handler, $command] = $this->handler(
            $operation,
            $agents,
            $permissions,
            AgentId::generate(),
            $permission,
            revision: $wrong->getPermissionAssignmentRevision()
        );
        $failure = $this->failure(fn() => $handler->handle(CommandMessage::create($command)));
        self::assertInstanceOf(LogicException::class, $failure);
        self::assertSame($operation === 'revoke' ? [] : [$permission->getId()], $wrong->getPermissionIds());
    }

    #[DataProvider('operations')]
    public function test_wrong_single_permission_identity_is_internal_not_absence(string $operation): void
    {
        $agent = $this->agent();
        $agents = $this->createMock(AgentRepository::class);
        $agents->method('getOperationContract')->willReturn(new InMemoryAgentRepository()->getOperationContract());
        $agents->method('getById')->willReturn($agent);
        $agents->expects(self::never())->method('replacePermissionAssignments');
        $permissions = $this->createStub(PermissionRepository::class);
        $permissions->method('getById')->willReturn($this->permission());
        [$handler, $command] = $this->handler($operation, $agents, $permissions, $agent->getId(), $this->permission());
        self::assertInstanceOf(LogicException::class, $this->failure(
            fn() => $handler->handle(CommandMessage::create($command))
        ));
    }

    #[DataProvider('operations')]
    public function test_genuine_absence_has_application_not_found_intent(string $operation): void
    {
        foreach (['agent', 'permission'] as $missing) {
            $uow = new InMemoryUnitOfWork();
            $agents = new InMemoryAgentRepository($uow);
            $agent = $this->agent();
            if ($missing !== 'agent') {
                $agents->add($agent);
            }

            $permissions = new InMemoryPermissionRepository($uow);
            [$handler, $command] = $this->handler(
                $operation,
                $agents,
                $permissions,
                $agent->getId(),
                $this->permission(),
                $uow
            );
            self::assertInstanceOf(ResourceNotFound::class, $this->failure(
                fn() => $handler->handle(CommandMessage::create($command))
            ));
            self::assertSame(0, $agents->permissionAssignmentReplacementCalls());
        }
    }

    public function test_protected_assignment_is_a_rule_but_explicit_stale_revision_is_a_precondition(): void
    {
        foreach (['grant', 'replace', 'stale'] as $case) {
            $uow = new InMemoryUnitOfWork();
            $agents = new InMemoryAgentRepository($uow);
            $agent = $this->agent();
            $agents->add($agent);
            $permissions = new InMemoryPermissionRepository($uow);
            $permission = $this->permission($case !== 'stale');
            $permissions->add($permission);
            [$handler, $command] = $this->handler(
                $case === 'grant' ? 'grant' : 'replace',
                $agents,
                $permissions,
                $agent->getId(),
                $permission,
                $uow,
                $case === 'stale' ? 2 : 1
            );
            self::assertInstanceOf($case === 'stale' ? PreconditionFailed::class : RuleViolation::class, $this->failure(
                fn() => $handler->handle(CommandMessage::create($command))
            ));
            self::assertSame($agent, $agents->getById($agent->getId()));
        }
    }

    public function test_invalid_name_has_application_input_intent_before_repository_admission(): void
    {
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::never())->method('rename');
        $handler = new UpdateAgentHandler(
            $agents,
            new FixedClock('2030-01-01'),
            new InMemoryUnitOfWork(),
            new InMemoryEventDispatcher()
        );
        self::assertInstanceOf(InvalidInput::class, $this->failure(fn() => $handler->handle(CommandMessage::create(
            new UpdateAgent(new AgentUpdateInitiator(UserId::generate()), AgentId::generate(), '')
        ))));
    }

    #[DataProvider('operations')]
    public function test_storage_faults_retain_identity_even_when_their_type_looks_like_a_domain_refusal(
        string $operation
    ): void {
        $fault = new AgentPermissionAssignmentException('Synthetic repository diagnostic', 404);
        $agents = $this->createStub(AgentRepository::class);
        $agents->method('getOperationContract')->willReturn(new InMemoryAgentRepository()->getOperationContract());
        $agents->method('getById')->willThrowException($fault);
        [$handler, $command] = $this->handler(
            $operation,
            $agents,
            $this->createStub(PermissionRepository::class),
            AgentId::generate(),
            $this->permission()
        );
        self::assertSame($fault, $this->failure(fn() => $handler->handle(CommandMessage::create($command))));
    }

    #[DataProvider('operations')]
    public function test_revision_exhaustion_is_a_known_domain_rule_without_partial_write(string $operation): void
    {
        $uow = new InMemoryUnitOfWork();
        $original = $this->agent();
        $permission = $this->permission();
        $agent = Agent::reconstitute(
            $original->getId(),
            $original->getName(),
            $original->getState(),
            $original->getCredentialId(),
            $original->getCredentialRevision(),
            $original->getEncryptedHmacSharedSecretEnvelope(),
            $operation === 'revoke' ? [$permission->getId()] : [],
            PHP_INT_MAX,
            $original->getCreatedAt(),
            $original->getUpdatedAt()
        );
        $agents = new InMemoryAgentRepository($uow);
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository($uow);
        $permissions->add($permission);
        [$handler, $command] = $this->handler(
            $operation,
            $agents,
            $permissions,
            $agent->getId(),
            $permission,
            $uow,
            PHP_INT_MAX
        );
        $failure = $this->failure(fn() => $handler->handle(CommandMessage::create($command)));
        self::assertInstanceOf(RuleViolation::class, $failure);
        self::assertInstanceOf(AgentPermissionAssignmentException::class, $failure->getPrevious());
        self::assertSame($agent, $agents->getById($agent->getId()));
        self::assertSame(0, $agents->permissionAssignmentReplacementCalls());
    }

    public function test_stale_revision_is_rejected_even_when_the_complete_set_is_already_equal(): void
    {
        $uow = new InMemoryUnitOfWork();
        $permission = $this->permission();
        $agent = $this->agent()->grantPermission($permission->getId(), new DateTimeImmutable('2030-01-01'));
        $agents = new InMemoryAgentRepository($uow);
        $agents->add($agent);

        $permissions = new InMemoryPermissionRepository($uow);
        $permissions->add($permission);
        [$handler, $command] = $this->handler('replace', $agents, $permissions, $agent->getId(), $permission, $uow);
        self::assertInstanceOf(PreconditionFailed::class, $this->failure(
            fn() => $handler->handle(CommandMessage::create($command))
        ));
        self::assertSame($agent, $agents->getById($agent->getId()));
    }

    public function test_repository_conflict_is_distinct_from_a_caller_precondition(): void
    {
        $uow = new InMemoryUnitOfWork();
        $agent = $this->agent();
        $agents = new InMemoryAgentRepository($uow, replacePermissionAssignmentsSucceeds: false);
        $agents->add($agent);

        $permission = $this->permission();
        $permissions = new InMemoryPermissionRepository($uow);
        $permissions->add($permission);
        [$handler, $command] = $this->handler('replace', $agents, $permissions, $agent->getId(), $permission, $uow);
        self::assertInstanceOf(StateConflict::class, $this->failure(
            fn() => $handler->handle(CommandMessage::create($command))
        ));
        self::assertSame($agent, $agents->getById($agent->getId()));
    }

    /** @return array{CommandHandler, Command} */
    private function handler(
        string $operation,
        AgentRepository $agents,
        PermissionRepository $permissions,
        AgentId $agentId,
        Permission $permission,
        ?InMemoryUnitOfWork $uow = null,
        int $revision = 1
    ): array {
        $dispatcher = new InMemoryEventDispatcher(static function ($event): void {
            self::assertInstanceOf(CommandFailedEvent::class, $event);
        });
        $uow ??= new InMemoryUnitOfWork();
        $clock = new FixedClock('2030-01-01');
        $actor = UserId::generate();

        return match ($operation) {
            'grant' => [
                new GrantPermissionToAgentHandler($agents, $permissions, $clock, $uow, $dispatcher),
                new GrantPermissionToAgent($actor, $agentId, $permission->getId())
            ],
            'revoke' => [
                new RevokePermissionFromAgentHandler($agents, $permissions, $clock, $uow, $dispatcher),
                new RevokePermissionFromAgent($actor, $agentId, $permission->getId())
            ],
            default => [
                new ReplaceAgentPermissionsHandler($agents, $permissions, $clock, $uow, $dispatcher),
                new ReplaceAgentPermissions($actor, $agentId, $revision, [$permission->getId()])
            ]
        };
    }

    private function agent(): Agent
    {
        return Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Current'),
            AgentCredentialId::generate(),
            'synthetic-envelope',
            new DateTimeImmutable('2029-01-01')
        );
    }

    private function permission(bool $protected = false): Permission
    {
        return Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('READ_RECORDS'),
            $protected ? PermissionTier::SUPER_ADMIN_ONLY : PermissionTier::ADMIN_SAFE,
            new DateTimeImmutable('2029-01-01')
        );
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('The operation must fail.');
    }
}
