<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent;

use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Adapter\Mcp\Tool\Detail\GetCapturedDetailTool;
use Fight\Common\Adapter\Mcp\Tool\Detail\ListCapturedMessagesTool;
use Fight\Common\Adapter\Mcp\Tool\Observation\ListPhaseActivityTool;
use Fight\Common\Adapter\Mcp\Tool\Phase\ListExecutionPhasesTool;
use Fight\Common\Adapter\Mcp\Tool\Session\GetExecutionTool;
use Fight\Common\Adapter\Mcp\Tool\Session\ListExecutionsTool;
use Fight\Common\Adapter\Mcp\Tool\Session\SummarizeExecutionsTool;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Phase\Data\PhasePage;
use Fight\Common\Application\Mcp\Protocol\Exception\McpProtocolException;
use Fight\Common\Application\Mcp\Protocol\McpRequest;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Session\Data\ExecutionPage;
use Fight\Common\Application\Mcp\Session\Data\ExecutionSummary;
use Fight\Common\Application\Mcp\Tool\McpProgressReporter;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Application\Validation\ValidationService;
use Fight\Common\Application\Validation\Validator;
use Fight\Common\Domain\Mcp\Detail\Query\GetCapturedDetail;
use Fight\Common\Domain\Mcp\Detail\Query\ListCapturedMessages;
use Fight\Common\Domain\Mcp\Observation\Query\ListPhaseActivity;
use Fight\Common\Domain\Mcp\Phase\Query\ListExecutionPhases;
use Fight\Common\Domain\Mcp\Session\Query\GetExecution;
use Fight\Common\Domain\Mcp\Session\Query\ListExecutions;
use Fight\Common\Domain\Mcp\Session\Query\SummarizeExecutions;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ExecutionToolResults;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentToolPermissionCatalog::class)]
#[CoversClass(AgentToolAvailability::class)]
final class MixedAgentToolsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function missingAuthority(): iterable
    {
        foreach (['first', 'second', 'both', 'unresolved', 'fault'] as $missing) {
            yield $missing => [$missing];
        }
    }

    public function test_all_seven_common_tools_and_attributed_profiles_use_one_registry_and_real_provider(): void
    {
        $env = new ProfileToolEnvironment();
        $this->grant($env);
        $profileInvoker = $env->invoker();
        $queries = [];
        $results = new ExecutionToolResults();
        $queryBus = $this->createMock(QueryBus::class);
        $queryBus->expects(self::exactly(7))->method('fetch')->willReturnCallback(
            static function ($query) use (&$queries, $results): mixed {
                $queries[] = $query;

                return match ($query::class) {
                    SummarizeExecutions::class => new ExecutionSummary(
                        0,
                        ['unknown' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0],
                        0,
                        0,
                        [],
                        null
                    ),
                    ListExecutions::class => new ExecutionPage([], 0, 1, 25),
                    GetExecution::class => null,
                    ListExecutionPhases::class => new PhasePage([], 0, 1, 25),
                    ListPhaseActivity::class => $results->activity,
                    ListCapturedMessages::class => $results->messages,
                    GetCapturedDetail::class => $results->detail,
                    default => throw new RuntimeException('Unexpected Query dispatch.')
                };
            }
        );
        [$registry, $catalog] = $this->composition($env, $queryBus);
        $availability = $this->availability($env, $catalog);
        $discovery = new McpToolDiscovery($registry, $availability, str_repeat('c', 32));
        $page = $discovery->handle($this->listRequest())->toArray();
        self::assertCount(9, $page['tools']);
        self::assertSame(0, $page['ttlMs']);
        self::assertSame('private', $page['cacheScope']);
        $invoker = new McpToolInvoker($registry, $availability);
        foreach ($this->arguments() as $name => [$arguments, $queryClass]) {
            $definition = $registry->definition($name);
            self::assertNotNull($definition);
            self::assertNotNull($catalog->requirementsFor($definition));
            self::assertFalse($availability->isAvailable(clone $definition));
            $result = $invoker->invoke($name, $arguments)->toArray();
            self::assertSame('complete', $result['resultType']);
            self::assertArrayNotHasKey('isError', $result);
            self::assertInstanceOf($queryClass, $queries[array_key_last($queries)]);
            self::assertStringNotContainsString('original-test-secret', json_encode($result, JSON_THROW_ON_ERROR));
            $content = json_decode(json_encode($result['structuredContent'], JSON_THROW_ON_ERROR), true);
            if ($name === 'execution.activity') {
                self::assertSame('GetExecution', $content['activities'][0]['activity']['execution']['target']['name']);
                self::assertSame('returned', $content['activities'][0]['activity']['execution']['result']['kind']);
                self::assertNull($content['activities'][0]['activity']['execution']['timing']['ended_at']);
            }

            if ($name === 'execution.messages' || $name === 'execution.detail') {
                $detail = $name === 'execution.messages' ? $content['messages'][0] : $content;
                self::assertFalse($detail['body_permitted']);
                self::assertNull($detail['chunk']);
                self::assertSame('retained', $detail['reference']['availability']);
            }
        }

        self::assertSame('complete', $invoker->invoke('agent.profile.read', (object) [])->toArray()['resultType']);
        self::assertSame('complete', $invoker->invoke(
            'agent.profile.update',
            ['name' => 'Mixed registry']
        )->toArray()['resultType']);
        self::assertSame(1, $env->storage->agentRepository->nameWrites);
        self::assertCount(1, $env->storage->eventDispatcher->events());
        self::assertSame(1, $env->nonces->consumptionCalls());
        // Existing standalone invoker shares this same trusted request provider, never another nonce.
        self::assertSame('complete', $profileInvoker->invoke(
            'agent.profile.read',
            (object) []
        )->toArray()['resultType']);
        self::assertSame(1, $env->nonces->consumptionCalls());
    }

    #[DataProvider('missingAuthority')]
    public function test_each_missing_mapped_permission_and_authority_fault_denies_before_any_selected_work(
        string $missing
    ): void {
        $env = new ProfileToolEnvironment();
        $this->grant($env, $missing);
        if ($missing === 'fault') {
            $permissions = $this->createMock(PermissionRepository::class);
            $permissions->expects(self::once())->method('getByIds')
                ->willThrowException(new RuntimeException('Private authority diagnostic'));
            $env->provider = new CurrentAgentPrincipalProvider(
                $env->storage->agentRepository,
                $permissions,
                new FixedHmacSharedSecretDecipher('auth-envelope:'),
                new FixedHmacSignedAgentRequestVerifier($env->request, 'original-test-secret'),
                new FixedClock($env->now()),
                $env->nonces,
                $env->storage->transaction
            );
        }

        $env->invoker();
        if ($missing === 'unresolved') {
            $env->storage->agentRepository->restoreSnapshot([]);
        }

        $queryBus = $this->createMock(QueryBus::class);
        $queryBus->expects(self::never())->method('fetch');
        [$registry, $catalog] = $this->composition($env, $queryBus);
        $availability = $this->availability($env, $catalog);
        $validator = $this->createMock(Validator::class);
        $validator->expects(self::never())->method('validate');
        $validation = new ValidationService();
        $validation->addValidator($validator);

        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::never())->method('report');
        $invoker = new McpToolInvoker($registry, $availability, $validation);
        foreach ($this->arguments() as $name => [$arguments]) {
            foreach ([$arguments, ['private' => new RuntimeException('Private argument')]] as $input) {
                try {
                    $invoker->invoke($name, $input, $progress);
                    self::fail('Missing authority must deny.');
                } catch (McpProtocolException $exception) {
                    self::assertSame(
                        ['code' => -32602, 'message' => 'Unknown or unavailable tool.'],
                        $exception->protocolError()->toArray()
                    );
                }
            }
        }

        $page = new McpToolDiscovery($registry, $availability, str_repeat('c', 32))
            ->handle($this->listRequest())->toArray();
        self::assertSame([], array_filter(
            array_column($page['tools'], 'name'),
            static fn(string $name): bool => str_starts_with($name, 'execution.')
        ));
        self::assertSame(0, $env->storage->agentRepository->nameWrites);
        self::assertLessThanOrEqual(1, $env->nonces->consumptionCalls());
    }

    /** @return iterable<string, array{string}> */
    public static function authorityChanges(): iterable
    {
        foreach (['revocation', 'credential', 'permission'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('authorityChanges')]
    public function test_later_mixed_pages_and_calls_use_fresh_authority_not_the_previous_snapshot(string $change): void
    {
        $env = new ProfileToolEnvironment();
        $this->grant($env);
        $env->invoker();
        $queryBus = $this->createMock(QueryBus::class);
        $queryBus->expects(self::never())->method('fetch');
        [$registry, $catalog] = $this->composition($env, $queryBus);
        $availability = $this->availability($env, $catalog);
        $discovery = new McpToolDiscovery($registry, $availability, str_repeat('c', 32), pageSize: 1);
        $first = $discovery->handle($this->listRequest())->toArray();
        self::assertSame(['agent.profile.read'], array_column($first['tools'], 'name'));
        self::assertArrayHasKey('nextCursor', $first);
        $agent = $env->storage->agentRepository->getById($env->original->getId());
        self::assertNotNull($agent);
        $ids = $agent->getPermissionIds();
        $changed = match ($change) {
            'revocation' => $agent->revoke($env->now()),
            'credential' => $agent->rotateRecoverableCredential(
                $agent->getCredentialId(),
                $agent->getCredentialRevision(),
                AgentCredentialId::generate(),
                'auth-envelope:rotated-secret',
                $env->now()
            ),
            'permission' => $agent->revokePermission($ids[array_key_last($ids)], $env->now()),
            default => throw new RuntimeException('Unknown authority change.')
        };
        $env->storage->agentRepository->restoreSnapshot([$changed]);
        $definition = $registry->definition('execution.get');
        self::assertNotNull($definition);
        self::assertTrue($availability->isAvailable($definition));
        // Request-bound profile Tools force rebuilding both registry and catalog together.
        $env->nextRequest();
        $env->invoker();
        [$laterRegistry, $laterCatalog] = $this->composition($env, $queryBus);
        $laterAvailability = $this->availability($env, $laterCatalog);
        $later = new McpToolDiscovery($laterRegistry, $laterAvailability, str_repeat('c', 32), pageSize: 1);
        try {
            $later->handle($this->listRequest(['cursor' => $first['nextCursor']]));
            self::fail('Changed catalog must invalidate the old page cursor.');
        } catch (McpProtocolException $mcpProtocolException) {
            self::assertSame(-32602, $mcpProtocolException->protocolError()->toArray()['code']);
        }

        self::assertFalse($laterAvailability->isAvailable($laterRegistry->definition('execution.get')));
        try {
            new McpToolInvoker($laterRegistry, $laterAvailability)->invoke('execution.get', ['private' => 'invalid']);
            self::fail('Fresh authority must deny invocation.');
        } catch (McpProtocolException $mcpProtocolException) {
            self::assertSame(
                'Unknown or unavailable tool.',
                $mcpProtocolException->protocolError()->toArray()['message']
            );
        }
    }

    /** @param array<string, mixed> $parameters */
    private function listRequest(array $parameters = []): McpRequest
    {
        return new McpRequest(1, 'tools/list', $parameters, McpRequestMetadata::fromObject(StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => (object) []
        ])));
    }

    private function grant(ProfileToolEnvironment $env, string $missing = ''): void
    {
        $agent = $env->original;
        foreach (['VIEW_EXECUTIONS', 'USE_EXECUTION_TOOLS'] as $index => $name) {
            if ($missing === 'both' || $missing === ($index === 0 ? 'first' : 'second')) {
                continue;
            }

            $id = PermissionId::generate();
            $env->permissionRepository->add(Permission::define($id, PermissionName::fromString($name), $env->now()));
            $agent = $agent->grantPermission($id, $env->now());
        }

        $env->storage->agentRepository->restoreSnapshot([$agent]);
    }

    /** @return array{McpToolRegistry, AgentToolPermissionCatalog} */
    private function composition(ProfileToolEnvironment $env, QueryBus $queryBus): array
    {
        $registry = new McpToolRegistry([
            new SummarizeExecutionsTool($queryBus),
            new ListExecutionsTool($queryBus),
            new GetExecutionTool($queryBus),
            new ListExecutionPhasesTool($queryBus),
            new ListPhaseActivityTool($queryBus),
            new ListCapturedMessagesTool($queryBus),
            new GetCapturedDetailTool($queryBus),
            $env->registry->find('agent.profile.read'),
            $env->registry->find('agent.profile.update')
        ]);
        $bindings = array_fill_keys(array_keys($this->arguments()), ['VIEW_EXECUTIONS', 'USE_EXECUTION_TOOLS']);

        return [$registry, new AgentToolPermissionCatalog($registry, $bindings)];
    }

    private function availability(
        ProfileToolEnvironment $env,
        AgentToolPermissionCatalog $catalog
    ): AgentToolAvailability {
        return new AgentToolAvailability($catalog, $env->provider, $env->request, $env->correlationId);
    }

    /** @return array<string, array{array<string, mixed>, class-string}> */
    private function arguments(): array
    {
        $exact = ['project_id' => 'project', 'session_id' => 'session'];
        $activity = $exact + [
            'phase_id'    => 'phase',
            'producer_id' => 'producer',
            'activity_id' => '018f0000-0000-7000-8000-000000000001'
        ];
        $detail = $activity + [
            'detail_id' => '018f0000-0000-7000-8000-000000000002',
            'kind'      => 'message'
        ];

        return [
            'execution.summarize' => [['projects' => ['project']], SummarizeExecutions::class],
            'execution.list'      => [['projects' => ['project']], ListExecutions::class],
            'execution.get'       => [$exact, GetExecution::class],
            'execution.phases'    => [$exact, ListExecutionPhases::class],
            'execution.activity'  => [$exact + ['phase_id' => 'phase'], ListPhaseActivity::class],
            'execution.messages'  => [$activity, ListCapturedMessages::class],
            'execution.detail'    => [$detail, GetCapturedDetail::class]
        ];
    }
}
