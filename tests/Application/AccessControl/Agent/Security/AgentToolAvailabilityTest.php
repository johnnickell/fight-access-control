<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentToolAvailability;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Mcp\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\McpProgressReporter;
use Fight\Common\Application\Mcp\McpProtocolException;
use Fight\Common\Application\Mcp\McpRequest;
use Fight\Common\Application\Mcp\McpRequestMetadata;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\McpResult;
use Fight\Common\Application\Mcp\McpServerInfo;
use Fight\Common\Application\Mcp\Tool\Interaction\McpConfirmationOutcome;
use Fight\Common\Application\Mcp\Tool\Interaction\McpConfirmationStore;
use Fight\Common\Application\Mcp\Tool\Interaction\McpStateProtector;
use Fight\Common\Application\Mcp\Tool\Interaction\McpToolInteraction;
use Fight\Common\Application\Mcp\Tool\McpTool;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Application\Validation\Data\ApplicationData;
use Fight\Common\Application\Validation\ValidationService;
use Fight\Common\Application\Validation\Validator;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\InteractiveProtectedTool;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\ProtectedTool;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentToolAvailability::class)]
#[CoversClass(AgentToolPermissionCatalog::class)]
#[CoversClass(RequiresAgentPermission::class)]
final class AgentToolAvailabilityTest extends TestCase
{
    private ?Agent $agent;

    private AgentRepository $agents;

    private InMemoryPermissionRepository $permissions;

    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryAgentRequestNonceConsumer $nonces;

    private int $requestNumber = 0;

    /** @var list<FixedHmacSignedAgentRequestVerifier> */
    private array $verifiers = [];

    /** @return iterable<string, array{string}> */
    public static function missingAuthority(): iterable
    {
        foreach (['unresolved', 'view', 'update', 'both'] as $missing) {
            yield $missing => [$missing];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function authorityChanges(): iterable
    {
        foreach (['revocation', 'credential', 'permission'] as $change) {
            yield $change => [$change];
        }
    }

    public function test_conjunctive_permission_checks_share_one_lazy_principal_and_do_not_repeat_resolution(): void
    {
        $registry = new McpToolRegistry([new ProtectedTool($this->dispatch(0))]);
        $availability = $this->availability(new AgentToolPermissionCatalog($registry));
        self::assertSame(0, $this->nonces->consumptionCalls());
        $definition = $registry->definition('agents.inspect');
        self::assertNotNull($definition);
        self::assertFalse($availability->isAvailable(clone $definition));
        self::assertSame(0, $this->nonces->consumptionCalls());
        self::assertTrue($availability->isAvailable($definition));
        self::assertTrue($availability->isAvailable($definition));
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame(1, $this->verifiers[0]->calls());
        self::assertSame(1, $this->unitOfWork->transactions);
    }

    #[DataProvider('missingAuthority')]
    public function test_each_missing_permission_and_unresolved_authority_conceal_discovery_and_invocation(
        string $missing
    ): void {
        $tool = new ProtectedTool($this->dispatch(0));
        $registry = new McpToolRegistry([$tool]);
        $this->removeAuthority($missing);
        $availability = $this->availability(new AgentToolPermissionCatalog($registry));
        $discovery = new McpToolDiscovery($registry, $availability, str_repeat('c', 32), pageSize: 1);
        $page = $discovery->handle($this->request('tools/list'))->toArray();
        self::assertSame([], $page['tools']);
        self::assertSame(0, $page['ttlMs']);
        self::assertSame('private', $page['cacheScope']);
        self::assertArrayNotHasKey('nextCursor', $page);
        $validator = $this->createMock(Validator::class);
        $validator->expects(self::never())->method('validate');
        $validation = new ValidationService();
        $validation->addValidator($validator);

        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::never())->method('report');
        $invoker = new McpToolInvoker($registry, $availability, $validation);
        foreach ([null, ['label' => ''], ['label' => 'valid'], new RuntimeException('Not JSON')] as $arguments) {
            self::assertSame(
                $this->rejection(fn(): McpResult => $invoker->invoke('unknown', $arguments, $progress)),
                $this->rejection(fn(): McpResult => $invoker->invoke('agents.inspect', $arguments, $progress))
            );
        }

        self::assertSame(0, $tool->calls);
        self::assertLessThanOrEqual(1, $this->nonces->consumptionCalls());
    }

    public function test_discovery_filters_before_sorting_and_pagination_without_cross_request_results(): void
    {
        $later = new class implements McpTool {
            #[McpToolInfo('z.visible', 'Later permitted fixture', ['type' => 'object'], [])]
            #[RequiresAgentPermission('VIEW_AGENTS')]
            public function handle(ApplicationData $input, McpProgressReporter $progress): never
            {
                throw new RuntimeException('Discovery cannot invoke Tools.');
            }
        };
        $earlier = new class implements McpTool {
            #[McpToolInfo('a.visible', 'Earlier permitted fixture', ['type' => 'object'], [])]
            #[RequiresAgentPermission('VIEW_AGENTS')]
            public function handle(ApplicationData $input, McpProgressReporter $progress): never
            {
                throw new RuntimeException('Discovery cannot invoke Tools.');
            }
        };
        $registry = new McpToolRegistry([$later, new ProtectedTool($this->dispatch(0)), $earlier]);
        $catalog = new AgentToolPermissionCatalog($registry);
        $this->removeAuthority('update');
        $discovery = new McpToolDiscovery($registry, $this->availability($catalog), str_repeat('c', 32), pageSize: 1);
        $first = $discovery->handle($this->request('tools/list'))->toArray();
        self::assertSame(['a.visible'], array_column($first['tools'], 'name'));
        self::assertSame(0, $first['ttlMs']);
        self::assertSame('private', $first['cacheScope']);
        $second = $discovery->handle($this->request('tools/list', ['cursor' => $first['nextCursor']]))->toArray();
        self::assertSame(['z.visible'], array_column($second['tools'], 'name'));
        self::assertArrayNotHasKey('nextCursor', $second);
        self::assertSame(1, $this->nonces->consumptionCalls());
        $this->removeAuthority('view');
        $next = new McpToolDiscovery($registry, $this->availability($catalog), str_repeat('c', 32), pageSize: 1);
        self::assertSame([], $next->handle($this->request('tools/list'))->toArray()['tools']);
        self::assertSame(2, $this->nonces->consumptionCalls());
    }

    public function test_permitted_invocation_runs_validation_buses_and_progress_after_valid_input_only(): void
    {
        $tool = new ProtectedTool($this->dispatch(1));
        $registry = new McpToolRegistry([$tool]);
        $availability = $this->availability(new AgentToolPermissionCatalog($registry));
        $validator = $this->createMock(Validator::class);
        $validator->expects(self::once())->method('validate')->willReturn(true);
        $validation = new ValidationService();
        $validation->addValidator($validator);

        $invoker = new McpToolInvoker($registry, $availability, $validation);
        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::once())->method('report')->with(1.0, 1.0, 'Complete');
        self::assertTrue($invoker->invoke('agents.inspect', ['label' => 42], $progress)->toArray()['isError']);
        $result = $invoker->invoke('agents.inspect', ['label' => 'permitted'], $progress)->toArray();
        self::assertSame('complete', $result['resultType']);
        self::assertSame('permitted', $result['structuredContent']->get('label'));
        self::assertSame(1, $tool->calls);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    #[DataProvider('authorityChanges')]
    public function test_same_request_snapshot_survives_change_but_a_later_invocation_observes_current_authority(
        string $change
    ): void {
        $tool = new ProtectedTool($this->dispatch(1));
        $registry = new McpToolRegistry([$tool]);
        $catalog = new AgentToolPermissionCatalog($registry);
        $availability = $this->availability($catalog);
        $before = new McpToolDiscovery($registry, $availability, str_repeat('c', 32));
        self::assertCount(1, $before->handle($this->request('tools/list'))->toArray()['tools']);
        $this->changeAuthority($change);
        $sameRequest = new McpToolInvoker($registry, $availability);
        $admitted = $sameRequest->invoke('agents.inspect', ['label' => 'admitted'])->toArray();
        self::assertSame('complete', $admitted['resultType']);
        $later = new McpToolInvoker($registry, $this->availability($catalog));
        self::assertSame(
            ['code' => -32602, 'message' => 'Unknown or unavailable tool.'],
            $this->rejection(fn(): McpResult => $later->invoke('agents.inspect', ['label' => 'later']))
        );
        self::assertSame(1, $tool->calls);
    }

    public function test_rejected_resolution_is_cached_for_this_request_only(): void
    {
        $registry = new McpToolRegistry([new ProtectedTool($this->dispatch(0))]);
        $catalog = new AgentToolPermissionCatalog($registry);
        $availability = $this->availability($catalog, invalidSignature: true);
        $definition = $registry->definition('agents.inspect');
        self::assertNotNull($definition);
        self::assertFalse($availability->isAvailable($definition));
        self::assertFalse($availability->isAvailable($definition));
        self::assertSame(1, $this->verifiers[0]->calls());
        self::assertSame(0, $this->nonces->consumptionCalls());
        self::assertTrue($this->availability($catalog)->isAvailable($definition));
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    public function test_permission_storage_failure_is_neutral_and_does_not_repeat_resolution_in_one_request(): void
    {
        $reads = 0;
        $this->permissions = new InMemoryPermissionRepository(getByIdsResult: static function () use (&$reads): array {
            ++$reads;

            throw new RuntimeException('Private storage diagnostic');
        });
        $registry = new McpToolRegistry([new ProtectedTool($this->dispatch(0))]);
        $availability = $this->availability(new AgentToolPermissionCatalog($registry));
        $definition = $registry->definition('agents.inspect');
        self::assertNotNull($definition);
        self::assertFalse($availability->isAvailable($definition));
        self::assertFalse($availability->isAvailable($definition));
        self::assertSame(1, $reads);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    public function test_even_a_failed_diagnostic_construction_cannot_escape_the_neutral_availability_boundary(): void
    {
        $this->agent = null;
        $registry = new McpToolRegistry([new ProtectedTool($this->dispatch(0))]);
        $availability = $this->availability(new AgentToolPermissionCatalog($registry), correlationId: '');
        $definition = $registry->definition('agents.inspect');
        self::assertNotNull($definition);
        self::assertFalse($availability->isAvailable($definition));
        self::assertFalse($availability->isAvailable($definition));
        self::assertSame(0, $this->nonces->consumptionCalls());
    }

    public function test_new_credential_and_regranted_permissions_work_only_in_new_request_scopes(): void
    {
        $registry = new McpToolRegistry([new ProtectedTool($this->dispatch(0))]);
        $catalog = new AgentToolPermissionCatalog($registry);
        $definition = $registry->definition('agents.inspect');
        self::assertNotNull($definition);
        $this->changeAuthority('permission');
        $denied = $this->availability($catalog);
        self::assertFalse($denied->isAvailable($definition));
        self::assertNotNull($this->agent);
        $this->agent = $this->agent->grantPermission($this->permissionId(1), $this->now());
        self::assertFalse($denied->isAvailable($definition));
        self::assertTrue($this->availability($catalog)->isAvailable($definition));
        $this->changeAuthority('credential');
        self::assertNotNull($this->agent);
        self::assertSame(1, $this->agent->getCredentialRevision());
        self::assertFalse($this->availability($catalog)->isAvailable($definition));
        self::assertTrue($this->availability(
            $catalog,
            credential: $this->agent->getCredentialId(),
            secret: 'rotated-secret'
        )->isAvailable($definition));
    }

    public function test_public_unknown_and_unavailable_errors_do_not_emit_protected_diagnostics(): void
    {
        $tool = new ProtectedTool($this->dispatch(0));
        $registry = new McpToolRegistry([$tool]);
        $this->removeAuthority('unresolved');
        $availability = $this->availability(new AgentToolPermissionCatalog($registry));
        $invoker = new McpToolInvoker($registry, $availability);
        $diagnostics = $this->createMock(McpDiagnostics::class);
        $diagnostics->expects(self::never())->method('record');
        $responder = new McpResponder(new McpCapabilityRegistry(
            new McpServerInfo('access-control-conformance', '1'),
            [
                new McpToolInvocation($invoker),
                new McpToolDiscovery($registry, $availability, str_repeat('c', 32))
            ]
        ), diagnostics: $diagnostics);
        $responses = [];
        foreach (['unknown', 'agents.inspect'] as $name) {
            $responses[] = $responder->respond(json_encode([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'tools/call',
                'params'  => [
                    'name'      => $name,
                    'arguments' => ['label' => 'private argument'],
                    '_meta'     => $this->metadataObject()
                ]
            ], JSON_THROW_ON_ERROR))->toArray();
        }

        self::assertSame($responses[0], $responses[1]);
        self::assertSame('Unknown or unavailable tool.', $responses[1]['error']['message']);
        self::assertStringNotContainsString('private argument', json_encode($responses, JSON_THROW_ON_ERROR));
        self::assertSame(0, $tool->calls);
    }

    #[DataProvider('authorityChanges')]
    public function test_protected_retry_rechecks_authority_before_restore_responses_consumption_or_resume(
        string $change
    ): void {
        $this->exerciseInteraction($change);
    }

    public function test_authorized_fresh_request_can_restore_and_complete_the_protected_interaction(): void
    {
        $this->exerciseInteraction(null);
    }

    protected function setUp(): void
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->permissions = new InMemoryPermissionRepository();
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentName::fromString('MCP conformance Agent'),
            $this->credentialId(),
            'encrypted:fixture-secret',
            $this->now()
        );
        foreach (['VIEW_AGENTS', 'UPDATE_AGENTS'] as $index => $name) {
            $id = $this->permissionId($index);
            $this->permissions->add(Permission::define($id, PermissionName::fromString($name), $this->now()));
            $this->agent = $this->agent->grantPermission($id, $this->now());
        }

        // Mutable authoritative read fixture: models committed snapshots, not actual database writer races.
        $this->agents = $this->createStub(AgentRepository::class);
        $this->agents->method('getByCredentialId')->willReturnCallback($this->agentByCredential(...));
        $this->agents->method('getById')->willReturnCallback($this->currentAgent(...));
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->agents, $this->unitOfWork);
    }

    private function exerciseInteraction(?string $change): void
    {
        $tool = new InteractiveProtectedTool($this->dispatch($change === null ? 1 : 0));
        $registry = new McpToolRegistry([$tool]);
        $catalog = new AgentToolPermissionCatalog($registry);
        // Opaque-state port fixture, not cryptographic-provider qualification.
        $plaintext = '';
        $protector = $this->createMock(McpStateProtector::class);
        $protector->expects(self::once())->method('seal')->willReturnCallback(
            static function (string $state) use (&$plaintext): string {
                $plaintext = $state;

                return 'opaque-fixture-token';
            }
        );
        $protector->expects(self::atLeastOnce())->method('open')->with('opaque-fixture-token')->willReturnCallback(
            static function () use (&$plaintext): string {
                return $plaintext;
            }
        );
        $confirmations = $this->createMock(McpConfirmationStore::class);
        $confirmations->expects(self::once())->method('issue');
        $confirmations->expects(self::exactly($change === null ? 1 : 0))->method('consume')
            ->willReturn(McpConfirmationOutcome::CONSUMED);
        $interaction = new McpToolInteraction($protector, 'neutral-fixture-binding', confirmations: $confirmations);
        $first = new McpToolInvoker($registry, $this->availability($catalog), interaction: $interaction);
        $issued = $first->invokeRequest($this->request('tools/call', ['name' => 'agents.confirm']))->toArray();
        self::assertSame('input_required', $issued['resultType']);
        self::assertSame('opaque-fixture-token', $issued['requestState']);
        self::assertArrayNotHasKey('structuredContent', $issued);
        self::assertStringNotContainsString('Private retained label', json_encode($issued, JSON_THROW_ON_ERROR));
        $parameters = [
            'name'           => 'agents.confirm',
            'requestState'   => $issued['requestState'],
            'inputResponses' => ['confirm' => ['action' => 'accept', 'content' => ['label' => 'confirmed']]]
        ];
        $progress = $this->createMock(McpProgressReporter::class);
        $progress->expects(self::exactly($change === null ? 1 : 0))->method('report');
        if ($change !== null) {
            $this->changeAuthority($change);
        }

        $later = new McpToolInvoker($registry, $this->availability($catalog), interaction: $interaction);
        if ($change === null) {
            $result = $later->invokeRequest($this->request('tools/call', $parameters, 2), $progress)->toArray();
            self::assertSame('confirmed', $result['structuredContent']->get('label'));
            self::assertSame(1, $tool->resumes);
            self::assertSame(2, $this->nonces->consumptionCalls());
        } else {
            $unknownRegistry = new McpToolRegistry([]);
            $unknown = new McpToolInvoker(
                $unknownRegistry,
                $this->availability(new AgentToolPermissionCatalog($unknownRegistry)),
                interaction: $interaction
            );
            $request = $this->request('tools/call', $parameters, 2);
            $expected = $this->rejection(fn(): McpResult => $unknown->invokeRequest($request, $progress));
            self::assertSame(['code' => -32602, 'message' => 'Unknown or unavailable tool.'], $expected);
            self::assertSame(
                $expected,
                $this->rejection(fn(): McpResult => $later->invokeRequest($request, $progress))
            );
            // Distinguishes deny-before-restore from ordinary invalid-params response validation.
            $parameters['inputResponses'] = 'malformed';
            $parameters['arguments'] = 'replaced';
            $wrongBinding = new McpToolInvoker(
                $registry,
                $this->availability($catalog),
                interaction: new McpToolInteraction($protector, 'wrong-binding', confirmations: $confirmations)
            );
            self::assertSame($expected, $this->rejection(fn(): McpResult => $wrongBinding->invokeRequest(
                $this->request('tools/call', $parameters, 3),
                $progress
            )));
            self::assertSame(0, $tool->resumes);
        }

        self::assertSame(1, $tool->calls);
    }

    private function availability(
        AgentToolPermissionCatalog $catalog,
        bool $invalidSignature = false,
        ?AgentCredentialId $credential = null,
        string $secret = 'fixture-secret',
        ?string $correlationId = null
    ): AgentToolAvailability {
        ++$this->requestNumber;
        $request = $this->signedRequest('valid', $credential);
        $verifier = new FixedHmacSignedAgentRequestVerifier($request, $secret);
        $this->verifiers[] = $verifier;
        $provider = new CurrentAgentPrincipalProvider(
            $this->agents,
            $this->permissions,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $verifier,
            new FixedClock($this->now()),
            $this->nonces,
            $this->unitOfWork
        );

        return new AgentToolAvailability(
            $catalog,
            $provider,
            $invalidSignature ? $this->signedRequest('invalid', $credential) : $request,
            $correlationId ?? 'mcp-fixture-'.$this->requestNumber
        );
    }

    private function signedRequest(string $signature, ?AgentCredentialId $credential = null): SignedAgentRequest
    {
        return new SignedAgentRequest(
            'POST',
            'mcp.example',
            '/mcp',
            '',
            $this->now(),
            'mcp-fixture-nonce-'.$this->requestNumber,
            $credential ?? $this->credentialId(),
            'HMAC-SHA256',
            $signature,
            null,
            ''
        );
    }

    private function dispatch(int $calls): Closure
    {
        $commands = $this->createMock(CommandBus::class);
        $queries = $this->createMock(QueryBus::class);
        $commands->expects(self::exactly($calls))->method('execute')->with(self::isInstanceOf(UpdateAgent::class));
        $commands->expects(self::never())->method('dispatch');
        $queries->expects(self::exactly($calls))->method('fetch')->with(self::isInstanceOf(GetAgentById::class));
        $id = AgentId::fromString('018f0000-0000-7000-8000-000000000001');

        return static function () use ($commands, $queries, $id): void {
            $queries->fetch(new GetAgentById($id));
            $commands->execute(new UpdateAgent(new AgentUpdateInitiator($id), $id, 'Fixture action'));
        };
    }

    private function removeAuthority(string $missing): void
    {
        if ($missing === 'unresolved') {
            $this->agent = null;

            return;
        }

        self::assertNotNull($this->agent);
        foreach (['view', 'update'] as $index => $permission) {
            if ($missing === $permission || $missing === 'both') {
                $this->agent = $this->agent->revokePermission($this->permissionId($index), $this->now());
            }
        }
    }

    private function changeAuthority(string $change): void
    {
        self::assertNotNull($this->agent);
        $this->agent = match ($change) {
            'revocation' => $this->agent->revoke($this->now()),
            'credential' => $this->agent->rotateRecoverableCredential(
                $this->agent->getCredentialId(),
                $this->agent->getCredentialRevision(),
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000099'),
                'encrypted:rotated-secret',
                $this->now()
            ),
            'permission' => $this->agent->revokePermission($this->permissionId(1), $this->now()),
            default      => throw new RuntimeException('Unknown authority-change scenario.')
        };
    }

    /** @param array<string, mixed> $parameters */
    private function request(string $method, array $parameters = [], int $id = 1): McpRequest
    {
        return new McpRequest($id, $method, $parameters, McpRequestMetadata::fromObject($this->metadataObject()));
    }

    private function metadataObject(): StrictJson
    {
        return StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => ['elicitation' => ['form' => (object) []]]
        ]);
    }

    /** @return array<string, mixed> */
    private function rejection(Closure $operation): array
    {
        try {
            $operation();
            self::fail('Protected execution must be rejected.');
        } catch (McpProtocolException $mcpProtocolException) {
            return $mcpProtocolException->protocolError()->toArray();
        }
    }

    private function agentByCredential(AgentCredentialId $id): ?Agent
    {
        return $this->agent?->getCredentialId()->equals($id) ? $this->agent : null;
    }

    private function currentAgent(): ?Agent
    {
        return $this->agent;
    }

    private function permissionId(int $index): PermissionId
    {
        return PermissionId::fromString(sprintf('018f0000-0000-7000-8000-%012d', $index + 10));
    }

    private function credentialId(): AgentCredentialId
    {
        return AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T12:00:00+00:00');
    }
}
