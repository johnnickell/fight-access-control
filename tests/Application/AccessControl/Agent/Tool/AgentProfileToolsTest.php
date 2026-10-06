<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Tool;

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\AgentProfileToolFailures;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\GetAgentProfileTool;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\UpdateAgentProfileTool;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentUpdateException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpProgressReporter;
use Fight\Common\Application\Mcp\McpProtocolException;
use Fight\Common\Application\Mcp\McpRequestMetadata;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\McpResult;
use Fight\Common\Application\Mcp\McpServerInfo;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Messaging\Command\SynchronousCommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Exception\LookupException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        yield 'long name' => ['agent.profile.update', ['name' => str_repeat('a', 121)]];
        foreach (['agent_id', 'initiator', 'credential_id', 'permissions', 'state'] as $field) {
            yield $field => ['agent.profile.update', ['name' => 'Valid', $field => 'forbidden']];
        }
    }

    public function test_real_rename_and_noop_share_acknowledgement_but_only_real_change_writes_and_publishes(): void
    {
        $env = new ProfileToolEnvironment();
        $invoker = $env->invoker();
        $env->provider->resolve($env->request, $env->correlationId);
        $transactions = $env->storage->transaction->transactions;
        $operations = $env->storage->operations->operations;
        $before = $this->wireResult($invoker->invoke('agent.profile.read', (object) []));
        $this->assertProfile($before, $env, 'Production deployment');
        self::assertSame($transactions, $env->storage->transaction->transactions);
        self::assertSame([], $env->storage->events->events());
        $changed = $this->wireResult($invoker->invoke('agent.profile.update', ['name' => '  New worker  ']));
        $this->assertProfile($changed, $env, 'New worker');
        $after = $env->storage->agents->getById($env->original->getId());
        self::assertNotNull($after);
        self::assertSame('New worker', $after->getName()->toString());
        self::assertSame(1, $env->storage->agents->nameWrites);
        self::assertCount(1, $env->storage->events->events());
        $event = $env->storage->events->events()[0];
        self::assertInstanceOf(AgentNameChanged::class, $event);
        self::assertSame('agent:'.$env->original->getId()->toString(), $event->getInitiator()->toString());
        self::assertTrue($env->original->getId()->equals($event->getAgentId()));
        $unchanged = $this->wireResult($invoker->invoke('agent.profile.update', ['name' => 'New worker']));
        self::assertSame($changed, $unchanged);
        self::assertSame($after, $env->storage->agents->getById($env->original->getId()));
        self::assertSame(1, $env->storage->agents->nameWrites);
        self::assertCount(1, $env->storage->events->events());
        self::assertSame($operations, $env->storage->operations->operations);
        self::assertSame($env->original->getCredentialId(), $after->getCredentialId());
        self::assertSame($env->original->getPermissionIds(), $after->getPermissionIds());
        $this->assertProfile(
            $this->wireResult($invoker->invoke('agent.profile.read', (object) [])),
            $env,
            'New worker'
        );
        self::assertSame(1, $env->nonces->consumptionCalls());
        self::assertSame($transactions + 2, $env->storage->transaction->transactions);
    }

    public function test_acknowledgement_is_not_a_read_of_a_competing_later_name(): void
    {
        $env = new ProfileToolEnvironment();
        $handler = new UpdateAgentHandler(
            $env->storage->agents,
            new FixedClock($env->now()),
            $env->storage->transaction,
            $env->storage->events
        );
        $commands = $this->createMock(SynchronousCommandBus::class);
        $commands->expects(self::once())->method('execute')->willReturnCallback(
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
        $queries = $this->createMock(QueryBus::class);
        $queries->expects(self::never())->method('fetch');
        $invoker = $env->invoker($queries, $commands);
        $this->assertProfile(
            $this->wireResult($invoker->invoke('agent.profile.update', ['name' => 'My request'])),
            $env,
            'My request'
        );
        $winner = $env->storage->agents->getById($env->original->getId());
        self::assertNotNull($winner);
        self::assertSame('Competing winner', $winner->getName()->toString());
        self::assertSame(2, $env->storage->agents->nameWrites);
        self::assertCount(2, $env->storage->events->events());
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
                $permission = $env->permissions->getByName(PermissionName::fromString($name));
                self::assertNotNull($permission);
                $agent = $agent->revokePermission($permission->getId(), $env->now());
            }
        }

        $env->storage->agents->restoreSnapshot($unresolved ? [] : [$agent]);
        $queries = $this->createMock(QueryBus::class);
        $queries->expects(self::exactly(in_array(AgentProfilePermissions::READ, $granted, true) ? 1 : 0))
            ->method('fetch')->willReturn(new AgentProfileView($agent->getId(), AgentName::fromString('Visible')));
        $commands = $this->createMock(SynchronousCommandBus::class);
        $commands->expects(self::exactly(in_array(AgentProfilePermissions::UPDATE, $granted, true) ? 1 : 0))
            ->method('execute');
        $invoker = $env->invoker($queries, $commands);
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
        $agent = $env->storage->agents->getById($env->original->getId());
        self::assertNotNull($agent);
        $permission = $env->permissions->getByName(PermissionName::fromString(AgentProfilePermissions::UPDATE));
        self::assertNotNull($permission);
        $env->storage->agents->restoreSnapshot([$agent->revokePermission($permission->getId(), $env->now())]);
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
        $queries = $this->createMock(QueryBus::class);
        $queries->expects(self::never())->method('fetch');
        $commands = $this->createMock(SynchronousCommandBus::class);
        $commands->expects(self::never())->method('execute');
        $result = $this->wireResult($env->invoker($queries, $commands)->invoke($tool, (object) $arguments));
        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame(0, $env->storage->agents->nameWrites);
        self::assertSame([], $env->storage->events->events());
    }

    public function test_missing_and_revoked_targets_after_admission_share_safe_read_and_update_failure(): void
    {
        foreach ([null, 'revoked'] as $state) {
            $env = new ProfileToolEnvironment();
            $invoker = $env->invoker();
            $env->provider->resolve($env->request, $env->correlationId);
            $env->storage->agents->restoreSnapshot($state === null ? [] : [$env->original->revoke($env->now())]);
            $read = $this->wireResult($invoker->invoke('agent.profile.read', (object) []));
            $update = $this->wireResult($invoker->invoke('agent.profile.update', ['name' => 'Valid']));
            self::assertSame($read, $update);
            self::assertTrue($read['isError']);
            self::assertSame([['type' => 'text', 'text' => 'The Agent profile is unavailable.']], $read['content']);
            self::assertArrayNotHasKey('structuredContent', $read);
            self::assertSame(0, $env->storage->agents->nameWrites);
            self::assertCount(0, array_filter(
                $env->storage->events->events(),
                static fn(Event $event): bool => $event instanceof AgentNameChanged
            ));
        }
    }

    public function test_unknown_storage_and_post_commit_publication_errors_are_publicly_redacted(): void
    {
        foreach (['read', 'write', 'publication'] as $stage) {
            $env = new ProfileToolEnvironment();
            $failure = new RuntimeException('PRIVATE fixture credential envelope / permissions / storage / stack');
            $queries = null;
            if ($stage === 'read') {
                $queries = $this->createMock(QueryBus::class);
                $queries->expects(self::once())->method('fetch')->willThrowException($failure);
            } elseif ($stage === 'write') {
                $env->storage->agents->afterNameWrite = static fn() => throw $failure;
            } else {
                $env->storage->events = new InMemoryEventDispatcher(
                    static function (Event $event) use ($env, $failure): void {
                        self::assertFalse($env->storage->transaction->transactionActive);
                        if ($event instanceof AgentNameChanged) {
                            throw $failure;
                        }
                    }
                );
            }

            $invoker = $env->invoker($queries);
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
            $current = $env->storage->agents->getById($env->original->getId());
            self::assertNotNull($current);
            self::assertSame(
                $stage === 'publication' ? 'Committed name' : 'Production deployment',
                $current->getName()->toString()
            );
        }
    }

    public function test_expected_failure_bindings_are_constant_and_unknown_failures_remain_unmapped(): void
    {
        $map = AgentProfileToolFailures::create();
        $failures = [
            new AgentNameException('private'),
            new AgentUpdateException('private'),
            new LookupException('private'),
            new AgentOperationRejectedException(AgentOperationFailure::CONFLICT)
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
        self::assertNull($map->messageFor(new RuntimeException('private')));
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
