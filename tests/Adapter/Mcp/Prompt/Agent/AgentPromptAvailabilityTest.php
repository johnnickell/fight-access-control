<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Prompt\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptAvailability;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Capability\McpCapability;
use Fight\Common\Application\Mcp\Capability\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Prompt\McpPromptDiscovery;
use Fight\Common\Application\Mcp\Prompt\McpPromptInfo;
use Fight\Common\Application\Mcp\Prompt\McpPromptLimits;
use Fight\Common\Application\Mcp\Prompt\McpPromptOutput;
use Fight\Common\Application\Mcp\Protocol\Exception\McpProtocolException;
use Fight\Common\Application\Mcp\Protocol\McpProtocolError;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Resource\McpResourceAvailability;
use Fight\Common\Application\Mcp\Resource\McpResourceDiscovery;
use Fight\Common\Application\Mcp\Resource\McpResourceReadLimits;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Adapter\Mcp\Prompt\Agent\Fixture\PromptProvider;
use Fight\Test\AccessControl\Adapter\Mcp\Prompt\Agent\Fixture\ScopedAgentQueryHandler;
use Fight\Test\AccessControl\Adapter\Mcp\Resource\Agent\Fixture\ResourceProvider;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentPromptAvailability::class)]
#[CoversClass(AgentPromptPermissionBinding::class)]
#[CoversClass(AgentPromptPermissionCatalog::class)]
final class AgentPromptAvailabilityTest extends TestCase
{
    private ?Agent $agent;

    private AgentRepository $agentRepository;

    private InMemoryPermissionRepository $permissionRepository;

    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryAgentRequestNonceConsumer $nonces;

    private int $requestNumber = 0;

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function malformedRequirements(): iterable
    {
        yield 'empty' => [[]];
        yield 'map' => [['permission' => 'VIEW_DOCUMENTS']];
        yield 'object authority' => [[PermissionName::fromString('VIEW_DOCUMENTS')]];
        yield 'number' => [[42]];
        yield 'lowercase' => [['view_documents']];
        yield 'whitespace' => [[' VIEW_DOCUMENTS']];
        yield 'empty name' => [['']];
        yield 'terminal newline' => [["VIEW_DOCUMENTS\n"]];
    }

    /** @param array<array-key, mixed> $permissions */
    #[DataProvider('malformedRequirements')]
    public function test_requirements_reject_at_composition_without_building_bodies(array $permissions): void
    {
        $provider = $this->prompt('one');
        try {
            new AgentPromptPermissionBinding($provider, $permissions);
            self::fail('Malformed requirements must reject.');
        } catch (DomainException) {
            self::assertSame(0, $provider->calls);
            self::assertSame(0, $provider->definitions);
            self::assertSame(0, $this->nonces->consumptionCalls());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCatalogs(): iterable
    {
        foreach (['map', 'untyped', 'duplicate', 'conflict'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('malformedCatalogs')]
    public function test_catalog_rejects_missing_types_and_conflicting_definition_associations(string $kind): void
    {
        $provider = $this->prompt('one');
        $binding = $this->binding($provider);
        $bindings = match ($kind) {
            'map' => ['one' => $binding],
            'untyped' => [$provider],
            'duplicate' => [$binding, $binding],
            'conflict' => [$binding, new AgentPromptPermissionBinding($provider, ['OTHER_PERMISSION'])],
            default => throw new RuntimeException('Unknown fixture.')
        };
        $this->expectException(DomainException::class);
        new AgentPromptPermissionCatalog($bindings);
    }

    public function test_exact_definition_identity_denies_foreign_unknown_and_unmapped_without_authentication(): void
    {
        $provider = $this->prompt('one');
        $catalog = new AgentPromptPermissionCatalog([$this->binding($provider)]);
        $availability = $this->availability($catalog);
        self::assertFalse($availability->isAvailable(McpPromptInfo::fromArray(['name' => 'one'])));
        self::assertFalse($availability->isAvailable(McpPromptInfo::fromArray(['name' => 'unknown'])));
        $foreign = $this->prompt('one');
        $discovery = new McpPromptDiscovery([$foreign], $availability, str_repeat('k', 32), 'prompts');
        self::assertSame([], $this->response($discovery, 'prompts/list')['result']['prompts']);
        self::assertSame(-32602, $this->response($discovery, 'prompts/get', ['name' => 'one'])['error']['code']);
        self::assertSame(0, $foreign->calls);
        self::assertSame(0, $this->nonces->consumptionCalls());
        self::assertSame([], new AgentPromptPermissionCatalog([])->prompts());
    }

    public function test_real_list_filters_before_paging_and_never_constructs_content(): void
    {
        $hidden = $this->prompt('a_hidden');
        $one = $this->prompt('b_one');
        $two = $this->prompt('c_two');
        $catalog = new AgentPromptPermissionCatalog([
            new AgentPromptPermissionBinding($hidden, ['OTHER_PERMISSION']), $this->binding($one), $this->binding($two)
        ]);
        $discovery = $this->discovery($catalog, $this->availability($catalog, preResolve: true));
        $first = $this->response($discovery, 'prompts/list')['result'];
        self::assertSame(['b_one'], array_column($first['prompts'], 'name'));
        self::assertSame(0, $first['ttlMs']);
        self::assertSame('private', $first['cacheScope']);
        self::assertSame(48, strlen($first['nextCursor']));
        $second = $this->response($discovery, 'prompts/list', ['cursor' => $first['nextCursor']])['result'];
        self::assertSame(['c_two'], array_column($second['prompts'], 'name'));
        self::assertArrayNotHasKey('nextCursor', $second);
        foreach ([$hidden, $one, $two] as $provider) {
            self::assertSame(0, $provider->calls);
            self::assertSame(1, $provider->definitions);
        }

        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame(1, $this->unitOfWork->transactions);
        self::assertStringNotContainsString('hidden', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function test_direct_get_needs_no_listing_and_registration_metadata_is_immutable(): void
    {
        $one = $this->prompt('one', required: true);
        $two = $this->prompt('two');
        $catalog = new AgentPromptPermissionCatalog([$this->binding($one), $this->binding($two)]);
        $one->definition = McpPromptInfo::fromArray(['name' => 'changed']);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        $result = $this->response($discovery, 'prompts/get', [
            'name' => 'one', 'arguments' => StrictJson::fromObject(['subject' => 'é\r\n<script>inert</script>'])
        ])['result'];
        self::assertSame('Complete instructions', $result['messages'][0]['content']['text']);
        self::assertSame('assistant', $result['messages'][1]['role']);
        self::assertSame('Complete reply', $result['messages'][1]['content']['text']);
        self::assertSame(0, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
        self::assertSame([['subject' => 'é\r\n<script>inert</script>']], $one->arguments);
        self::assertSame(0, $two->calls);
        self::assertSame(1, $one->definitions);
        $changed = $this->response($discovery, 'prompts/get', ['name' => 'changed']);
        self::assertSame(-32602, $changed['error']['code']);
    }

    /** @return iterable<string, array{string}> */
    public static function missingAuthority(): iterable
    {
        foreach (['first', 'second', 'both', 'absent', 'signature', 'nonce race'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('missingAuthority')]
    public function test_every_missing_permission_denies_before_declared_arguments_or_provider_work(string $kind): void
    {
        self::assertNotNull($this->agent);
        if (in_array($kind, ['first', 'both'], true)) {
            $this->agent = $this->agent->revokePermission($this->permissionId(0), $this->now());
        }

        if (in_array($kind, ['second', 'both'], true)) {
            $this->agent = $this->agent->revokePermission($this->permissionId(1), $this->now());
        }

        if ($kind === 'absent') {
            $this->agent = null;
        }

        if ($kind === 'nonce race') {
            $this->nonces = new InMemoryAgentRequestNonceConsumer(
                $this->agentRepository,
                $this->unitOfWork,
                afterConsume: function (): void {
                    self::assertNotNull($this->agent);
                    $this->agent = $this->agent->revoke($this->now());
                }
            );
        }

        $provider = $this->prompt('one', required: true);
        $catalog = new AgentPromptPermissionCatalog([$this->binding($provider)]);
        $availability = $this->availability($catalog, invalidSignature: $kind === 'signature');
        $discovery = $this->discovery($catalog, $availability);
        $listed = $this->response($discovery, 'prompts/list');
        self::assertSame([], $listed['result']['prompts']);
        $unknown = $this->response($discovery, 'prompts/get', ['name' => 'unknown']);
        self::assertSame($unknown, $this->response($discovery, 'prompts/get', ['name' => 'one']));
        self::assertSame($unknown, $this->response($discovery, 'prompts/get', [
            'name'      => 'one',
            'arguments' => StrictJson::fromObject(['permissions' => ['VIEW_DOCUMENTS', 'READ_DOCUMENTS']])
        ]));
        self::assertSame(0, $provider->calls);
        self::assertLessThanOrEqual(1, $this->nonces->consumptionCalls());
    }

    /** @return iterable<string, array{string}> */
    public static function changes(): iterable
    {
        foreach (['permission', 'revocation', 'credential'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('changes')]
    public function test_later_pages_gets_and_saved_names_recheck_but_resolved_request_retains_snapshot(
        string $kind
    ): void {
        $one = $this->prompt('one');
        $two = $this->prompt('two');
        $catalog = new AgentPromptPermissionCatalog([$this->binding($one), $this->binding($two)]);
        $original = $this->discovery($catalog, $this->availability($catalog));
        $first = $this->response($original, 'prompts/list')['result'];
        self::assertNotNull($this->agent);
        $this->agent = match ($kind) {
            'permission' => $this->agent->revokePermission($this->permissionId(1), $this->now()),
            'revocation' => $this->agent->revoke($this->now()),
            'credential' => $this->agent->rotateRecoverableCredential(
                $this->credentialId(),
                $this->agent->getCredentialRevision(),
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000099'),
                'encrypted:rotated-secret',
                $this->now()
            ),
            default => throw new RuntimeException('Unknown fixture.')
        };
        self::assertArrayHasKey('result', $this->response($original, 'prompts/get', ['name' => 'one']));
        $later = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame([], $this->response($later, 'prompts/list')['result']['prompts']);
        $laterGet = $this->response($later, 'prompts/get', ['name' => 'one']);
        self::assertSame(-32602, $laterGet['error']['code']);
        self::assertSame(-32602, $this->response($later, 'prompts/list', [
            'cursor' => $first['nextCursor']
        ])['error']['code']);
        self::assertSame(1, $one->calls);
        self::assertSame(0, $two->calls);
        if ($kind === 'permission') {
            $this->agent = $this->agent->grantPermission($this->permissionId(1), $this->now());
            self::assertSame([], $this->response($later, 'prompts/list')['result']['prompts']);
            $fresh = $this->discovery($catalog, $this->availability($catalog));
            self::assertArrayHasKey('result', $this->response($fresh, 'prompts/get', ['name' => 'one']));
        } elseif ($kind === 'credential') {
            $fresh = $this->discovery($catalog, $this->availability(
                $catalog,
                credential: $this->agent->getCredentialId(),
                secret: 'rotated-secret'
            ));
            self::assertArrayHasKey('result', $this->response($fresh, 'prompts/get', ['name' => 'one']));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        foreach (['storage', 'snapshot', 'unexpected', 'provider', 'provider protocol'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('faults')]
    public function test_faults_are_safe_internal_failures_not_empty_or_partial_success_and_are_request_local(
        string $kind
    ): void {
        $provider = $this->prompt('one');
        $healthy = $this->permissionRepository;
        if ($kind === 'storage' || $kind === 'snapshot') {
            $this->permissionRepository = new InMemoryPermissionRepository(getByIdsResult: static function () use (
                $kind
            ): array {
                if ($kind === 'storage') {
                    throw new RuntimeException('Private database secret');
                }

                return [];
            });
        } elseif ($kind === 'unexpected') {
            $this->agent = null;
        } else {
            $provider->content = static function () use ($kind): never {
                if ($kind === 'provider protocol') {
                    throw new McpProtocolException(McpProtocolError::invalidParams(), 1);
                }

                throw new RuntimeException('Private provider secret');
            };
        }

        $catalog = new AgentPromptPermissionCatalog([$this->binding($provider)]);
        $availability = $this->availability($catalog, correlationId: $kind === 'unexpected' ? '' : 'fault');
        $discovery = $this->discovery($catalog, $availability);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $this->response($discovery, 'prompts/get', ['name' => 'one'], failure: true);
            self::assertSame(['code' => -32603, 'message' => 'Internal error.'], $response['error']);
            self::assertArrayNotHasKey('result', $response);
            self::assertStringNotContainsString('Private', json_encode($response, JSON_THROW_ON_ERROR));
            if (in_array($kind, ['storage', 'snapshot', 'unexpected'], true)) {
                $listed = $this->response($discovery, 'prompts/list', failure: true);
                self::assertSame(['code' => -32603, 'message' => 'Internal error.'], $listed['error']);
                self::assertArrayNotHasKey('result', $listed);
            }
        }

        if ($kind === 'storage' || $kind === 'snapshot') {
            self::assertSame(1, $this->nonces->consumptionCalls());
            self::assertSame(0, $provider->calls);
            $this->permissionRepository = $healthy;
            $fresh = $this->discovery($catalog, $this->availability($catalog));
            self::assertArrayHasKey('result', $this->response($fresh, 'prompts/get', ['name' => 'one']));
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidArguments(): iterable
    {
        yield 'missing required' => [[]];
        yield 'unknown' => [['subject' => 'ok', 'unknown' => 'private']];
        yield 'non-string' => [['subject' => 1]];
        yield 'oversized' => [['subject' => str_repeat('x', 16385)]];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function test_common_owns_declared_argument_validation_after_admission(array $arguments): void
    {
        $provider = $this->prompt('one', required: true);
        $catalog = new AgentPromptPermissionCatalog([$this->binding($provider)]);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        $response = $this->response($discovery, 'prompts/get', [
            'name' => 'one', 'arguments' => StrictJson::fromObject($arguments)
        ]);
        self::assertSame(-32602, $response['error']['code']);
        self::assertSame(0, $provider->calls);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    public function test_outer_shape_validation_precedes_authority_resolution(): void
    {
        $provider = $this->prompt('one');
        $catalog = new AgentPromptPermissionCatalog([$this->binding($provider)]);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame(-32602, $this->response($discovery, 'prompts/get', [
            'name' => 'one', 'arguments' => ['not an object']
        ])['error']['code']);
        self::assertSame(0, $this->nonces->consumptionCalls());
        self::assertSame(0, $provider->calls);
    }

    public function test_common_rejects_duplicate_names_including_concealed_definitions(): void
    {
        $catalog = new AgentPromptPermissionCatalog([
            $this->binding($this->prompt('one')),
            new AgentPromptPermissionBinding($this->prompt('one'), ['OTHER_PERMISSION'])
        ]);
        $this->expectException(DomainException::class);
        $this->discovery($catalog, $this->availability($catalog));
    }

    /** @return iterable<string, array{string}> */
    public static function budgets(): iterable
    {
        foreach (['count', 'catalog', 'result', 'envelope', 'output'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('budgets')]
    public function test_common_budgets_reject_atomically_including_concealed_registration(string $kind): void
    {
        $provider = $this->prompt('one');
        $catalog = new AgentPromptPermissionCatalog([
            $this->binding($provider), new AgentPromptPermissionBinding($this->prompt('two'), ['OTHER_PERMISSION'])
        ]);
        $limits = match ($kind) {
            'count' => new McpPromptLimits(maxPrompts: 1),
            'catalog' => new McpPromptLimits(maxCatalogBytes: 1),
            'result' => new McpPromptLimits(maxResultBytes: 1),
            'envelope' => new McpPromptLimits(maxResponseBytes: 1),
            default => new McpPromptLimits()
        };
        if ($kind === 'count' || $kind === 'catalog') {
            $this->expectException(DomainException::class);
            $this->discovery($catalog, $this->availability($catalog), $limits);

            return;
        }

        if ($kind === 'output') {
            $provider->content = static fn(): McpPromptOutput => McpPromptOutput::fromArray([
                'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => str_repeat('x', 65537)]]]
            ]);
        }

        $discovery = $this->discovery($catalog, $this->availability($catalog), $limits);
        $response = $this->response($discovery, 'prompts/get', ['name' => 'one'], failure: true);
        self::assertSame(-32603, $response['error']['code']);
        self::assertArrayNotHasKey('result', $response);
    }

    public function test_actual_central_metadata_and_envelope_are_included_in_exact_encoded_limits(): void
    {
        $catalog = new AgentPromptPermissionCatalog([$this->binding($this->prompt('one'))]);
        $availability = $this->availability($catalog);
        $ordinary = $this->discovery($catalog, $availability);
        $response = $this->response($ordinary, 'prompts/get', ['name' => 'one']);
        self::assertSame(
            ['name' => 'prompt-conformance', 'version' => '1'],
            $response['result']['_meta']['io.modelcontextprotocol/serverInfo']
        );
        $resultBytes = strlen(json_encode($response['result'], JSON_THROW_ON_ERROR));
        $responseBytes = strlen(json_encode($response, JSON_THROW_ON_ERROR));
        $exact = $this->discovery($catalog, $availability, new McpPromptLimits(
            maxResultBytes: $resultBytes,
            maxResponseBytes: $responseBytes
        ));
        self::assertSame($response, $this->response($exact, 'prompts/get', ['name' => 'one']));
        foreach (['result', 'envelope'] as $budget) {
            $limits = new McpPromptLimits(
                maxResultBytes: $budget === 'result' ? $resultBytes - 1 : $resultBytes,
                maxResponseBytes: $budget === 'envelope' ? $responseBytes - 1 : $responseBytes
            );
            $bounded = $this->discovery($catalog, $availability, $limits);
            $failed = $this->response($bounded, 'prompts/get', ['name' => 'one'], failure: true);
            self::assertSame(-32603, $failed['error']['code']);
            self::assertArrayNotHasKey('result', $failed);
        }
    }

    public function test_query_backed_content_has_independent_current_business_admission(): void
    {
        $target = AgentId::fromString('018f0000-0000-7000-8000-000000000088');
        $handler = new ScopedAgentQueryHandler($target);
        $router = new InMemoryQueryRouter();
        $router->registerHandler($handler::queryRegistration(), $handler);

        $bus = new RoutingQueryBus($router);
        $provider = $this->prompt('business');
        $provider->content = static function () use ($bus, $target): McpPromptOutput {
            /** @var string $text */
            $text = $bus->fetch(new GetAgentById($target));

            return McpPromptOutput::fromArray(['messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]
            ]]);
        };
        $catalog = new AgentPromptPermissionCatalog([$this->binding($provider)]);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        $this->response($discovery, 'prompts/list');
        self::assertSame(0, $handler->calls);
        $result = $this->response($discovery, 'prompts/get', ['name' => 'business'])['result'];
        self::assertSame('Current permitted business content', $result['messages'][0]['content']['text']);
        $handler->allowed = false;
        $denied = $this->response($discovery, 'prompts/get', ['name' => 'business'], failure: true);
        self::assertSame(-32603, $denied['error']['code']);
        self::assertArrayNotHasKey('result', $denied);
        self::assertSame(2, $handler->calls);
        self::assertSame(1, $handler->protectedReads);
        self::assertNotNull($this->agent);
        $this->agent = $this->agent->revokePermission($this->permissionId(0), $this->now());
        $fresh = $this->discovery($catalog, $this->availability($catalog));
        self::assertSame(-32602, $this->response($fresh, 'prompts/get', ['name' => 'business'])['error']['code']);
        self::assertSame(2, $handler->calls);
    }

    public function test_resource_and_skill_links_are_inert_and_later_target_reads_are_independently_denied(): void
    {
        $links = [
            ['type' => 'resource_link', 'uri' => 'docs:guide', 'name' => 'Public guide reference'],
            ['type' => 'resource_link', 'uri' => 'skill:guide', 'name' => 'Public Skill reference']
        ];
        $provider = $this->prompt('links');
        $provider->content = static fn(): McpPromptOutput => McpPromptOutput::fromArray(['messages' => array_map(
            static fn(array $link): array => ['role' => 'user', 'content' => $link],
            $links
        )]);
        $metadata = [];
        foreach ($links as $link) {
            $metadata[$link['uri']] = ['uri' => $link['uri'], 'name' => $link['name']];
        }

        $target = new ResourceProvider($metadata, static fn(): never => throw new RuntimeException('Must not open'));
        $target->allowed = false;

        $targetAvailability = $this->createStub(McpResourceAvailability::class);
        $targetAvailability->method('isAvailable')->willReturn(true);
        $resources = new McpResourceDiscovery(
            [$target],
            $targetAvailability,
            str_repeat('k', 32),
            'linked-targets',
            readLimits: new McpResourceReadLimits()
        );
        $catalog = new AgentPromptPermissionCatalog([$this->binding($provider)]);
        $discovery = $this->discovery($catalog, $this->availability($catalog));
        $result = $this->response($discovery, 'prompts/get', ['name' => 'links'])['result'];
        self::assertSame($links, array_column($result['messages'], 'content'));
        self::assertSame([], $target->lookedUp);
        self::assertSame([], $target->opened);
        foreach ($links as $link) {
            self::assertSame(-32602, $this->response($resources, 'resources/read', [
                'uri' => $link['uri']
            ])['error']['code']);
        }

        self::assertSame([], $target->opened);
    }

    protected function setUp(): void
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->permissionRepository = new InMemoryPermissionRepository();
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentName::fromString('Prompt conformance Agent'),
            $this->credentialId(),
            'encrypted:fixture-secret',
            $this->now()
        );
        foreach (['VIEW_DOCUMENTS', 'READ_DOCUMENTS'] as $index => $name) {
            $id = $this->permissionId($index);
            $this->permissionRepository->add(Permission::define($id, PermissionName::fromString($name), $this->now()));
            $this->agent = $this->agent->grantPermission($id, $this->now());
        }

        $this->agentRepository = $this->createStub(AgentRepository::class);
        $this->agentRepository->method('getByCredentialId')->willReturnCallback(
            $this->agentByCredential(...)
        );
        $this->agentRepository->method('getById')->willReturnCallback($this->currentAgent(...));
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->agentRepository, $this->unitOfWork);
    }

    private function availability(
        AgentPromptPermissionCatalog $catalog,
        bool $invalidSignature = false,
        bool $preResolve = false,
        ?AgentCredentialId $credential = null,
        string $secret = 'fixture-secret',
        string $correlationId = 'prompt-conformance'
    ): AgentPromptAvailability {
        ++$this->requestNumber;
        $request = $this->signedRequest('valid', $credential);
        $provider = new CurrentAgentPrincipalProvider(
            $this->agentRepository,
            $this->permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, $secret),
            new FixedClock($this->now()),
            $this->nonces,
            $this->unitOfWork
        );
        if ($preResolve) {
            $provider->resolve($request, $correlationId);
        }

        return new AgentPromptAvailability(
            $catalog,
            $provider,
            $invalidSignature ? $this->signedRequest('invalid', $credential) : $request,
            $correlationId
        );
    }

    private function signedRequest(string $signature, ?AgentCredentialId $credential): SignedAgentRequest
    {
        return new SignedAgentRequest(
            'POST',
            'mcp.example',
            '/mcp',
            '',
            $this->now(),
            'prompt-nonce-'.$this->requestNumber,
            $credential ?? $this->credentialId(),
            'HMAC-SHA256',
            $signature,
            null,
            ''
        );
    }

    private function binding(PromptProvider $provider): AgentPromptPermissionBinding
    {
        return new AgentPromptPermissionBinding($provider, ['VIEW_DOCUMENTS', 'READ_DOCUMENTS']);
    }

    private function prompt(string $name, bool $required = false): PromptProvider
    {
        $definition = ['name' => $name, 'description' => 'Recipient-safe instructions'];
        if ($required) {
            $definition['arguments'] = [['name' => 'subject', 'required' => true]];
        }

        return new PromptProvider(
            McpPromptInfo::fromArray($definition),
            static fn(): McpPromptOutput => McpPromptOutput::fromArray(['messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Complete instructions']],
                ['role' => 'assistant', 'content' => ['type' => 'text', 'text' => 'Complete reply']]
            ]])
        );
    }

    private function discovery(
        AgentPromptPermissionCatalog $catalog,
        AgentPromptAvailability $availability,
        ?McpPromptLimits $limits = null
    ): McpPromptDiscovery {
        return new McpPromptDiscovery(
            $catalog->prompts(),
            $availability,
            str_repeat('k', 32),
            'prompt-tests',
            $limits ?? new McpPromptLimits(pageSize: 1)
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function response(
        McpCapability $discovery,
        string $method,
        array $parameters = [],
        bool $failure = false
    ): array {
        $diagnostics = $this->createMock(McpDiagnostics::class);
        $diagnostics->expects(self::exactly($failure ? 1 : 0))->method('record');
        $registry = new McpCapabilityRegistry(new McpServerInfo('prompt-conformance', '1'), [$discovery]);
        $responder = new McpResponder($registry, diagnostics: $diagnostics);

        $response = $responder->respond(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $method,
            'params'  => [...$parameters, '_meta' => [
                McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                McpRequestMetadata::CLIENT_CAPABILITIES_KEY => StrictJson::fromObject([])
            ]]
        ], JSON_THROW_ON_ERROR))->toArray();

        return json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
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
        return new DateTimeImmutable('2026-11-10T12:00:00+00:00');
    }
}
