<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Command;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\GrantPermissionToAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ReplaceAgentPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RevokePermissionFromAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RevokePermissionFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

#[CoversClass(GrantPermissionToAgentHandler::class)]
#[CoversClass(RevokePermissionFromAgentHandler::class)]
#[CoversClass(ReplaceAgentPermissionsHandler::class)]
#[CoversClass(UpdateAgentHandler::class)]
#[CoversClass(AgentPermissionAssignmentCoordinator::class)]
final class AgentAdministrationCommandTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['grant', 'revoke', 'replace', 'rename'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function test_real_routing_serialized_dispatch_and_retry_are_void_and_publish_only_real_changes(
        string $operation
    ): void {
        [$env, $bus, $command] = $this->environment($operation);
        $id = $env->agentRepository->all()[0]->getId();
        $before = $env->agentRepository->getById($id);
        $audit = $env->auditEvidenceRepository->all();
        $operations = $env->operationRepository->operations;
        $decoded = CommandMessage::arrayDeserialize(CommandMessage::create($command)->toArray());
        $bus->dispatch($decoded);
        $after = $env->agentRepository->getById($id);
        self::assertNotSame($before, $after);
        self::assertTrue($env->transaction->transactionCompleted);
        self::assertFalse($env->transaction->transactionActive);
        self::assertCount(1, $env->eventDispatcher->events());
        self::assertNotInstanceOf(CommandFailedEvent::class, $env->eventDispatcher->events()[0]);
        if ($command instanceof ReplaceAgentPermissions) {
            $command = new ReplaceAgentPermissions(
                $command->getActorId(),
                $id,
                $after->getPermissionAssignmentRevision(),
                $command->getPermissionIds()
            );
        }

        $bus->execute($command);
        self::assertSame($after, $env->agentRepository->getById($id));
        self::assertCount(1, $env->eventDispatcher->events());
        self::assertSame($before->getCredentialId(), $after->getCredentialId());
        self::assertSame($before->getCredentialRevision(), $after->getCredentialRevision());
        self::assertSame($audit, $env->auditEvidenceRepository->all());
        self::assertSame($operations, $env->operationRepository->operations);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failureStages(): iterable
    {
        foreach (['grant', 'revoke', 'replace', 'rename'] as $operation) {
            foreach (['before-commit', 'lost-acknowledgement', 'publication'] as $stage) {
                yield $operation.' '.$stage => [$operation, $stage];
            }
        }
    }

    #[DataProvider('failureStages')]
    public function test_primary_failure_survives_secondary_publication_and_commit_truth_is_preserved(
        string $operation,
        string $stage
    ): void {
        [$env, , $command, $permissions] = $this->environment($operation);
        $before = $env->agentRepository->all()[0];
        $audit = $env->auditEvidenceRepository->all();
        $operations = $env->operationRepository->operations;
        $primary = new RuntimeException('Synthetic primary diagnostic', 409);
        $attempted = [];
        $env->eventDispatcher = new InMemoryEventDispatcher(static function (Event $event) use (
            $primary,
            &$attempted
        ): never {
            $attempted[] = $event;
            if ($event instanceof CommandFailedEvent) {
                throw new RuntimeException('Synthetic secondary diagnostic');
            }

            throw $primary;
        });
        if ($stage === 'before-commit') {
            $env->transaction->beforeCommit = static fn(): never => throw $primary;
        } elseif ($stage === 'lost-acknowledgement') {
            $env->transaction->afterCommit = static fn(): never => throw $primary;
        }

        $bus = $this->bus($env, $permissions);
        try {
            $bus->execute($command);
            self::fail('Failure must not become a successful void acknowledgement.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($primary, $runtimeException);
        }

        $stored = $env->agentRepository->getById($before->getId());
        if ($stage === 'before-commit') {
            self::assertSame($before, $stored);
        } else {
            self::assertNotSame($before, $stored);
        }

        self::assertSame($audit, $env->auditEvidenceRepository->all());
        self::assertSame($operations, $env->operationRepository->operations);
        self::assertCount($stage === 'publication' ? 2 : 1, $attempted);
        $failure = array_last($attempted);
        self::assertInstanceOf(CommandFailedEvent::class, $failure);
        self::assertSame($command, $failure->getCommand());
        $roundTrip = CommandFailedEvent::fromArray($failure->toArray());
        self::assertSame($failure->toArray(), $roundTrip->toArray());
        self::assertStringNotContainsString('diagnostic', json_encode($roundTrip->toArray(), JSON_THROW_ON_ERROR));
        self::assertFalse($env->transaction->transactionActive);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function safeFailures(): iterable
    {
        foreach (['grant', 'revoke', 'replace', 'rename'] as $operation) {
            yield $operation.' missing' => [$operation, 'missing', 404];
            yield $operation.' storage' => [$operation, 'storage', 500];
        }

        yield 'protected grant' => ['grant', 'protected', 422];
        yield 'protected set' => ['replace', 'protected', 422];
        yield 'stale caller' => ['replace', 'stale', 412];
        yield 'invalid name' => ['rename', 'invalid', 400];
        yield 'backdated name' => ['rename', 'backdated', 409];
    }

    #[DataProvider('safeFailures')]
    public function test_supported_safe_http_composition_classifies_intent_without_copying_diagnostics(
        string $operation,
        string $case,
        int $status
    ): void {
        [$env, $bus, $command] = $this->environment($operation, $case);
        $before = $env->agentRepository->all()[0];
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function () use ($bus, $command): ResponseInterface {
                $bus->execute($command);

                return new ResponseFactory()->createResponse(204);
            }
        );
        $response = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        )->process(new ServerRequestFactory()->createServerRequest('POST', '/administration'), $handler);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('Synthetic', (string) $response->getBody());
        self::assertSame($before, $env->agentRepository->getById($before->getId()));
    }

    /** @return array{ProvisioningEnvironment, RoutingCommandBus, Command, InMemoryPermissionRepository} */
    private function environment(string $operation, string $case = 'ordinary'): array
    {
        $env = new ProvisioningEnvironment();
        $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        $env->eventDispatcher = new InMemoryEventDispatcher();
        $agent = $env->agentRepository->all()[0];
        $permission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('READ_RECORDS'),
            $case === 'protected' ? PermissionTier::SUPER_ADMIN_ONLY : PermissionTier::ADMIN_SAFE,
            new DateTimeImmutable('2026-01-01')
        );
        $permissions = new InMemoryPermissionRepository($env->transaction);
        $permissions->add($permission);
        if ($operation === 'revoke') {
            $env->transaction->commitTransactional(function () use ($env, $agent, $permission): void {
                self::assertTrue($env->agentRepository->replacePermissionAssignments(
                    $agent,
                    $agent->grantPermission($permission->getId(), new DateTimeImmutable('2026-09-27T12:00:01Z'))
                ));
            });
        }

        if ($case === 'storage') {
            $env->transaction->beforeCommit = static fn(): never => throw new RuntimeException(
                'Synthetic storage',
                404
            );
        }

        $id = $case === 'missing' ? AgentId::generate() : $agent->getId();
        $actor = UserId::generate();
        $command = match ($operation) {
            'grant' => new GrantPermissionToAgent($actor, $id, $permission->getId()),
            'revoke' => new RevokePermissionFromAgent($actor, $id, $permission->getId()),
            'replace' => new ReplaceAgentPermissions(
                $actor,
                $id,
                $case === 'stale' ? 2 : 1,
                [$permission->getId()]
            ),
            default => new UpdateAgent(new AgentUpdateInitiator($actor), $id, $case === 'invalid' ? '' : 'Renamed')
        };

        return [$env, $this->bus($env, $permissions, $case === 'backdated'), $command, $permissions];
    }

    private function bus(
        ProvisioningEnvironment $env,
        InMemoryPermissionRepository $permissions,
        bool $backdated = false
    ): RoutingCommandBus {
        $router = new InMemoryCommandRouter();

        $clock = new FixedClock($backdated ? '2026-09-26T12:00:00Z' : '2026-09-27T12:00:02Z');
        foreach (
            [GrantPermissionToAgentHandler::class, RevokePermissionFromAgentHandler::class,
                ReplaceAgentPermissionsHandler::class] as $type
        ) {
            $handler = new $type($env->agentRepository, $permissions, $clock, $env->transaction, $env->eventDispatcher);
            $router->registerHandler($handler::commandRegistration(), $handler);
        }

        $handler = new UpdateAgentHandler($env->agentRepository, $clock, $env->transaction, $env->eventDispatcher);
        $router->registerHandler($handler::commandRegistration(), $handler);

        return new RoutingCommandBus($router);
    }
}
