<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent;

use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentProfileToolFailures;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\GetAgentProfileTool;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\UpdateAgentProfileTool;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameUpdateConflictException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentUpdateException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Mcp\Capability\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Protocol\Exception\McpProtocolException;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Protocol\McpResult;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Messaging\Command\SynchronousCommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

#[CoversClass(GetAgentProfileTool::class)]
#[CoversClass(UpdateAgentProfileTool::class)]
#[CoversClass(AgentProfileToolFailures::class)]
final class AgentProfileToolsTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool}> */
    public static function authority(): iterable
    {
        yield 'read only' => [[AgentProfilePermissions::READ], false];
        yield 'update only' => [[AgentProfilePermissions::UPDATE], false];
        yield 'neither' => [[], false];
        yield 'unresolved' => [[], true];
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidInput(): iterable
    {
        yield 'read target' => ['agent.profile.read', ['agent_id' => 'another-agent']];
        yield 'read name' => ['agent.profile.read', ['name' => 'not an input']];
        yield 'missing name' => ['agent.profile.update', []];
        yield 'non-string name' => ['agent.profile.update', ['name' => 42]];
        yield 'empty name' => ['agent.profile.update', ['name' => '']];
        yield 'blank name' => ['agent.profile.update', ['name' => " \t\n"]];
        yield 'unicode blank name' => ['agent.profile.update', ['name' => "\u{00A0}\u{3000}"]];
        yield 'long name' => ['agent.profile.update', ['name' => str_repeat('é', 121)]];
        foreach (['agent_id', 'initiator', 'credential_id', 'permissions', 'state'] as $field) {
            yield $field => ['agent.profile.update', ['name' => 'Valid', $field => 'forbidden']];
        }
    }

    public function test_registered_schemas_match_exact_minimal_profile_and_name_only_input(): void
    {
        $env = new ProfileToolEnvironment();
        $env->invoker();

        $output = [
            'type'                 => 'object',
            'properties'           => ['agent_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'required'             => ['agent_id', 'name'],
            'additionalProperties' => false
        ];
        foreach (['agent.profile.read', 'agent.profile.update'] as $name) {
            $definition = $env->registry->definition($name);
            self::assertNotNull($definition);
            self::assertSame($output, json_decode($definition->outputSchema()->toString(), true));
            self::assertFalse($definition->requiresFormElicitation());
            $input = json_decode($definition->inputSchema()->toString(), true);
            self::assertSame('object', $input['type']);
            self::assertFalse($input['additionalProperties']);
            if ($name === 'agent.profile.read') {
                self::assertSame(['type' => 'object', 'additionalProperties' => false], $input);
            } else {
                self::assertSame(['name'], array_keys($input['properties']));
                self::assertSame(['name'], $input['required']);
                self::assertSame('string', $input['properties']['name']['type']);
            }
        }

        self::assertSame(0, $env->nonces->consumptionCalls());
        self::assertSame(0, $env->storage->agentRepository->nameWrites);
        self::assertSame([], $env->storage->eventDispatcher->events());
    }

    public function test_real_rename_and_noop_share_acknowledgement_but_only_real_change_writes_and_publishes(): void
    {
        $env = new ProfileToolEnvironment();
        $invoker = $env->invoker(audit: true);
        $env->provider->resolve($env->request, $env->correlationId);
        $transactions = $env->storage->transaction->transactions;
        $operations = $env->storage->operationRepository->operations;
        $before = $this->wireResult($invoker->invoke('agent.profile.read', (object) []));
        $this->assertProfile($before, $env, 'Production deployment');
        self::assertSame($transactions, $env->storage->transaction->transactions);
        self::assertSame([], $env->storage->eventDispatcher->events());
        $changed = $this->wireResult($invoker->invoke('agent.profile.update', [
            'name' => "\0\u{00A0}New worker\u{3000}"
        ]));
        $this->assertProfile($changed, $env, 'New worker');
        $after = $env->storage->agentRepository->getById($env->original->getId());
        self::assertNotNull($after);
        self::assertSame('New worker', $after->getName()->toString());
        self::assertSame(1, $env->storage->agentRepository->nameWrites);
        self::assertCount(1, $env->storage->eventDispatcher->events());
        $event = $env->storage->eventDispatcher->events()[0];
        self::assertInstanceOf(AgentNameChanged::class, $event);
        self::assertSame('agent:'.$env->original->getId()->toString(), $event->getInitiator()->toString());
        self::assertTrue($env->original->getId()->equals($event->getAgentId()));
        $unchanged = $this->wireResult($invoker->invoke('agent.profile.update', [
            'name' => "\u{3000}New worker\u{00A0}\0"
        ]));
        self::assertSame($changed, $unchanged);
        self::assertSame($after, $env->storage->agentRepository->getById($env->original->getId()));
        self::assertSame(1, $env->storage->agentRepository->nameWrites);
        self::assertCount(1, $env->storage->eventDispatcher->events());
        self::assertSame($operations, $env->storage->operationRepository->operations);
        self::assertSame($env->original->getCredentialId(), $after->getCredentialId());
        self::assertSame($env->original->getPermissionIds(), $after->getPermissionIds());
        $this->assertProfile(
            $this->wireResult($invoker->invoke('agent.profile.read', (object) [])),
            $env,
            'New worker'
        );
        self::assertSame(1, $env->nonces->consumptionCalls());
        self::assertSame($transactions + 2, $env->storage->transaction->transactions);
        $records = array_values(array_filter(
            $env->storage->auditEvidenceRepository->all(),
            static fn(AuditEvidence $record): bool => $record->action() === AuditAction::AGENT_NAME_CHANGED
        ));
        self::assertCount(1, $records);
        self::assertTrue($env->original->getId()->equals($records[0]->actorId()));
        self::assertTrue($env->original->getId()->equals($records[0]->getSubject()->getId()));
        self::assertSame($after->getUpdatedAt(), $records[0]->getOccurredAt());
        self::assertNotNull($records[0]->getCorrelation()->getMessageId());
        self::assertSame(['name_changed' => true], $records[0]->getFacts()->toArray());
    }

    public function test_acknowledgement_is_not_a_read_of_a_competing_later_name(): void
    {
        $env = new ProfileToolEnvironment();
        $handler = new UpdateAgentHandler(
            $env->storage->agentRepository,
            new FixedClock($env->now()),
            $env->storage->transaction,
            $env->storage->eventDispatcher
        );
        $synchronousCommandBus = $this->createMock(SynchronousCommandBus::class);
        $synchronousCommandBus->expects(self::once())->method('execute')->willReturnCallback(
            static function (UpdateAgent $command) use ($env, $handler): void {
                $handler->handle(CommandMessage::create($command));
                // A second committed command wins before the first caller receives its acknowledgement.
                $handler->handle(CommandMessage::create(new UpdateAgent(
                    new AgentUpdateInitiator($env->original->getId()),
                    $env->original->getId(),
                    'Competing winner'
                )));
            }
        );
        $queryBus = $this->createMock(QueryBus::class);
        $queryBus->expects(self::never())->method('fetch');
        $invoker = $env->invoker($queryBus, $synchronousCommandBus);
        $this->assertProfile(
            $this->wireResult($invoker->invoke('agent.profile.update', ['name' => 'My request'])),
            $env,
            'My request'
        );
        $winner = $env->storage->agentRepository->getById($env->original->getId());
        self::assertNotNull($winner);
        self::assertSame('Competing winner', $winner->getName()->toString());
        self::assertSame(2, $env->storage->agentRepository->nameWrites);
        self::assertCount(2, $env->storage->eventDispatcher->events());
    }

    /** @param list<string> $granted */
    #[DataProvider('authority')]
    public function test_distinct_permissions_conceal_denied_tools_before_arguments_or_buses(
        array $granted,
        bool $unresolved
    ): void {
        $env = new ProfileToolEnvironment();
        $agent = $env->original;
        foreach ([AgentProfilePermissions::READ, AgentProfilePermissions::UPDATE] as $name) {
            if (!in_array($name, $granted, true)) {
                $permission = $env->permissionRepository->getByName(PermissionName::fromString($name));
                self::assertNotNull($permission);
                $agent = $agent->revokePermission($permission->getId(), $env->now());
            }
        }

        $env->storage->agentRepository->restoreSnapshot($unresolved ? [] : [$agent]);
        $queryBus = $this->createMock(QueryBus::class);
        $queryBus->expects(self::exactly(in_array(AgentProfilePermissions::READ, $granted, true) ? 1 : 0))
            ->method('fetch')->willReturn(new AgentProfileView($agent->getId(), AgentName::fromString('Visible')));
        $synchronousCommandBus = $this->createMock(SynchronousCommandBus::class);
        $synchronousCommandBus->expects(
            self::exactly(in_array(AgentProfilePermissions::UPDATE, $granted, true) ? 1 : 0)
        )
            ->method('execute');
        $invoker = $env->invoker($queryBus, $synchronousCommandBus);
        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::never())->method('report');
        $requirements = [
            'agent.profile.read'   => AgentProfilePermissions::READ,
            'agent.profile.update' => AgentProfilePermissions::UPDATE
        ];
        foreach ($requirements as $tool => $permission) {
            if (in_array($permission, $granted, true)) {
                $arguments = $permission === AgentProfilePermissions::READ ? (object) [] : ['name' => 'Valid'];
                self::assertArrayNotHasKey('isError', $invoker->invoke($tool, $arguments, $progress)->toArray());
            } else {
                foreach ([['name' => ''], ['agent_id' => 'other'], null] as $arguments) {
                    try {
                        $invoker->invoke($tool, $arguments, $progress);
                        self::fail('Denied Tool must remain concealed.');
                    } catch (McpProtocolException $exception) {
                        self::assertSame(
                            ['code' => -32602, 'message' => 'Unknown or unavailable tool.'],
                            $exception->protocolError()->toArray()
                        );
                    }
                }
            }
        }

        self::assertSame($unresolved ? 0 : 1, $env->nonces->consumptionCalls());
    }

    public function test_later_request_observes_revoked_update_permission_without_changing_read_permission(): void
    {
        $env = new ProfileToolEnvironment();
        $first = $env->invoker();
        $this->assertProfile(
            $this->wireResult($first->invoke('agent.profile.update', ['name' => 'Admitted'])),
            $env,
            'Admitted'
        );
        $agent = $env->storage->agentRepository->getById($env->original->getId());
        self::assertNotNull($agent);
        $permission = $env->permissionRepository->getByName(
            PermissionName::fromString(AgentProfilePermissions::UPDATE)
        );
        self::assertNotNull($permission);
        $env->storage->agentRepository->restoreSnapshot([$agent->revokePermission($permission->getId(), $env->now())]);
        $env->nextRequest();

        $later = $env->invoker();
        $this->assertProfile($this->wireResult($later->invoke('agent.profile.read', (object) [])), $env, 'Admitted');
        self::assertSame(2, $env->nonces->consumptionCalls());
        $this->expectException(McpProtocolException::class);
        $this->expectExceptionMessage('Unknown or unavailable tool.');
        $later->invoke('agent.profile.update', ['name' => 'Denied']);
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidInput')]
    public function test_invalid_inputs_never_reach_query_or_command(string $tool, array $arguments): void
    {
        $env = new ProfileToolEnvironment();
        $queryBus = $this->createMock(QueryBus::class);
        $queryBus->expects(self::never())->method('fetch');
        $synchronousCommandBus = $this->createMock(SynchronousCommandBus::class);
        $synchronousCommandBus->expects(self::never())->method('execute');
        $result = $this->wireResult(
            $env->invoker($queryBus, $synchronousCommandBus)->invoke($tool, (object) $arguments)
        );
        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame(0, $env->storage->agentRepository->nameWrites);
        self::assertSame([], $env->storage->eventDispatcher->events());
    }

    public function test_missing_and_revoked_targets_after_admission_share_safe_read_and_update_failure(): void
    {
        foreach ([null, 'revoked'] as $state) {
            $env = new ProfileToolEnvironment();
            $invoker = $env->invoker();
            $env->provider->resolve($env->request, $env->correlationId);
            $env->storage->agentRepository->restoreSnapshot(
                $state === null ? [] : [$env->original->revoke($env->now())]
            );
            $read = $this->wireResult($invoker->invoke('agent.profile.read', (object) []));
            $update = $this->wireResult($invoker->invoke('agent.profile.update', ['name' => 'Valid']));
            self::assertSame($read, $update);
            self::assertTrue($read['isError']);
            self::assertSame([['type' => 'text', 'text' => 'The Agent profile is unavailable.']], $read['content']);
            self::assertArrayNotHasKey('structuredContent', $read);
            self::assertSame(0, $env->storage->agentRepository->nameWrites);
            self::assertCount(0, array_filter(
                $env->storage->eventDispatcher->events(),
                static fn(Event $event): bool => $event instanceof AgentNameChanged
            ));
        }
    }

    public function test_unknown_storage_and_post_commit_publication_errors_are_publicly_redacted(): void
    {
        foreach (['read', 'write', 'publication', 'double publication', 'subclass'] as $stage) {
            $env = new ProfileToolEnvironment();
            $failure = new RuntimeException('PRIVATE fixture credential envelope / permissions / storage / stack');
            $queryBus = null;
            if ($stage === 'read') {
                $queryBus = $this->createMock(QueryBus::class);
                $queryBus->expects(self::once())->method('fetch')->willThrowException($failure);
            } elseif ($stage === 'write') {
                $env->storage->agentRepository->afterNameWrite = static fn(): never => throw $failure;
            } else {
                $env->storage->eventDispatcher = new InMemoryEventDispatcher(
                    static function (Event $event) use ($env, $failure): void {
                        self::assertFalse($env->storage->transaction->transactionActive);
                        if ($event instanceof AgentNameChanged) {
                            throw $failure;
                        }

                        throw new RuntimeException('Secondary private notification failure.');
                    }
                );
            }

            if ($stage === 'subclass') {
                $subclassFailure = new class extends StateConflict {
                };
                $env->storage->agentRepository->beforeNameWrite = static fn(): never => throw $subclassFailure;
            } elseif ($stage === 'publication') {
                $env->storage->eventDispatcher = new InMemoryEventDispatcher(
                    static function (Event $event) use ($failure): void {
                        if ($event instanceof AgentNameChanged) {
                            throw $failure;
                        }
                    }
                );
            }

            $invoker = $env->invoker($queryBus, audit: true);
            $responder = new McpResponder(new McpCapabilityRegistry(new McpServerInfo('profile-tests', '1'), [
                new McpToolInvocation($invoker),
                new McpToolDiscovery($env->registry, $env->availability, str_repeat('c', 32))
            ]));
            $response = $responder->respond(json_encode([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'tools/call',
                'params'  => [
                    'name'      => $stage === 'read' ? 'agent.profile.read' : 'agent.profile.update',
                    'arguments' => $stage === 'read' ? (object) [] : ['name' => 'Committed name'],
                    '_meta'     => [
                        McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                        McpRequestMetadata::CLIENT_CAPABILITIES_KEY => (object) []
                    ]
                ]
            ], JSON_THROW_ON_ERROR))->toArray();
            self::assertSame(['code' => -32603, 'message' => 'Internal error.'], $response['error']);
            self::assertArrayNotHasKey('result', $response);
            self::assertStringNotContainsString('PRIVATE', json_encode($response, JSON_THROW_ON_ERROR));
            $current = $env->storage->agentRepository->getById($env->original->getId());
            self::assertNotNull($current);
            $expectedName = 'Production deployment';
            if (in_array($stage, ['publication', 'double publication'], true)) {
                $expectedName = 'Committed name';
            }

            self::assertSame($expectedName, $current->getName()->toString());
            self::assertCount($expectedName === 'Committed name' ? 1 : 0, array_filter(
                $env->storage->auditEvidenceRepository->all(),
                static fn(AuditEvidence $record): bool => $record->action() === AuditAction::AGENT_NAME_CHANGED
            ));
        }
    }

    public function test_real_read_handler_rejects_wrong_repository_identity_as_internal_not_absence(): void
    {
        $env = new ProfileToolEnvironment();
        $other = new ProfileToolEnvironment();
        $repository = $this->createMock(AgentRepository::class);
        $repository->expects(self::once())->method('getById')->with($env->original->getId())
            ->willReturn($other->original);
        $router = new InMemoryQueryRouter();
        $router->registerHandler(GetAgentProfileHandler::queryRegistration(), new GetAgentProfileHandler($repository));

        $invoker = $env->invoker(new RoutingQueryBus($router));
        try {
            $invoker->invoke('agent.profile.read', (object) []);
            self::fail('An inconsistent repository identity must not return a profile.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(DomainException::class, $domainException->getPrevious());
            self::assertInstanceOf(UnexpectedValueException::class, $domainException->getPrevious()->getPrevious());
        }

        self::assertSame(1, $env->nonces->consumptionCalls());
        self::assertSame(0, $env->storage->agentRepository->nameWrites);
        self::assertSame([], $env->storage->eventDispatcher->events());
    }

    public function test_real_handler_conflict_and_cohort_refusal_keep_exact_safe_failure_bindings(): void
    {
        foreach (['name conflict', 'cohort refusal'] as $case) {
            $env = new ProfileToolEnvironment();
            if ($case === 'name conflict') {
                $env->storage->agentRepository->beforeNameWrite = static fn(): never =>
                    throw new AgentNameUpdateConflictException('Private repository diagnostic.');
            } else {
                $env->storage->operationRepository->operations = [];
            }

            $result = $this->wireResult($env->invoker()->invoke('agent.profile.update', ['name' => 'Refused']));
            self::assertSame([
                'resultType' => 'complete',
                'content'    => [[
                    'type' => 'text',
                    'text' => 'The Agent profile operation could not be completed.'
                ]],
                'isError'    => true
            ], $result);
            self::assertSame($env->original, $env->storage->agentRepository->getById($env->original->getId()));
            self::assertSame(0, $env->storage->agentRepository->nameWrites);
            $events = $env->storage->eventDispatcher->events();
            self::assertCount(1, $events);
            self::assertInstanceOf(CommandFailedEvent::class, $events[0]);
            self::assertStringNotContainsString('Private', json_encode($events[0]->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_expected_failure_bindings_are_constant_and_unknown_failures_remain_unmapped(): void
    {
        $map = AgentProfileToolFailures::create();
        $failures = [
            new AgentNameException('private'),
            new InvalidInput('private'),
            new StateConflict('private'),
            new ResourceNotFound('private')
        ];
        foreach ($failures as $failure) {
            self::assertNotNull($map->messageFor($failure));
            self::assertStringNotContainsString('private', $map->messageFor($failure));
        }

        self::assertSame('The Agent name is invalid.', $map->messageFor(new AgentNameException('private')));
        self::assertSame(
            'The Agent profile operation could not be completed.',
            $map->messageFor(new AgentOperationRejectedException(AgentOperationFailure::CONFLICT))
        );
        self::assertNull($map->messageFor(new class extends ResourceNotFound {
        }));
        self::assertNull($map->messageFor(new class extends InvalidInput {
        }));
        self::assertNull($map->messageFor(new class extends StateConflict {
        }));
        self::assertNull($map->messageFor(new RuntimeException('private')));
        self::assertNull($map->messageFor(new AgentUpdateException('private')));
    }

    /** @return array<string, mixed> */
    private function wireResult(McpResult $result): array
    {
        return json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $result */
    private function assertProfile(array $result, ProfileToolEnvironment $env, string $name): void
    {
        self::assertSame('complete', $result['resultType']);
        self::assertArrayNotHasKey('isError', $result);
        $expected = ['agent_id' => $env->original->getId()->toString(), 'name' => $name];
        self::assertSame($expected, $result['structuredContent']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame($expected, json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR));
    }
}
