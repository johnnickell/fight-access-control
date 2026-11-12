<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Subscription\Agent;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptAvailability;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Prompt\Agent\AgentPromptPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Resource\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillPermissionBinding;
use Fight\AccessControl\Adapter\Mcp\Skill\Agent\AgentSkillPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionFamilies;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationContext;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationHandoff;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadPolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriberReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriberReadPolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriptionScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Adapter\Mcp\Http\McpEventStream;
use Fight\Common\Adapter\Mcp\Notice\SodiumNoticeContinuationProtector;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRecoveryReader;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRepository;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeSchema;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeTransaction;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriberReader;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionSchema;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionStore;
use Fight\Common\Adapter\Mcp\Tool\Notice\RecoverNoticesTool;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Capability\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Notice\Data\NoticeRecoveryBatch;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeReadFailed;
use Fight\Common\Application\Mcp\Notice\QueryHandler\RecoverNoticesHandler;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAccess;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAdmission;
use Fight\Common\Application\Mcp\Notice\Service\NoticeReadAdmission;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecorder;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecovery;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecoveryIdentity;
use Fight\Common\Application\Mcp\Prompt\McpPromptDiscovery;
use Fight\Common\Application\Mcp\Prompt\McpPromptInfo;
use Fight\Common\Application\Mcp\Prompt\McpPromptOutput;
use Fight\Common\Application\Mcp\Protocol\McpRequest;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Resource\McpReadableResourceProvider;
use Fight\Common\Application\Mcp\Resource\McpResourceContent;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Application\Mcp\Skill\McpSkillAvailability;
use Fight\Common\Application\Mcp\Skill\McpSkillResources;
use Fight\Common\Application\Mcp\Subscription\Exception\SubscriberReadFailed;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptions;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionStore;
use Fight\Common\Application\Mcp\Subscription\QueryHandler\ListSubscribersHandler;
use Fight\Common\Application\Mcp\Subscription\QueryHandler\SummarizeSubscribersHandler;
use Fight\Common\Application\Mcp\Subscription\Service\SubscriberAccess;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use Fight\Common\Domain\Mcp\Notice\Query\RecoverNotices;
use Fight\Common\Domain\Mcp\Subscription\Query\ListSubscribers;
use Fight\Common\Domain\Mcp\Subscription\Query\SummarizeSubscribers;
use Fight\Common\Domain\Mcp\Subscription\SubscriberSelection;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Adapter\Mcp\Prompt\Agent\Fixture\PromptProvider;
use Fight\Test\AccessControl\Adapter\Mcp\Resource\Agent\Fixture\ResourceProvider;
use Fight\Test\AccessControl\Adapter\Mcp\Skill\Agent\Fixture\SkillRevision;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

#[CoversClass(AgentSubscriberReadAdmission::class)]
#[CoversClass(AgentSubscriptionAdmission::class)]
#[CoversClass(AgentSubscriptionFamilies::class)]
final class AgentSubscriptionAdmissionTest extends TestCase
{
    private Agent $agent;

    private InMemoryAgentRepository $agentRepository;

    private InMemoryPermissionRepository $permissionRepository;

    private AgentContinuationBinding $binding;

    private InMemoryAgentRequestNonceConsumer $nonces;

    private FixedClock $clock;

    private Connection $connection;

    private Connection $tracking;

    private RoutingQueryBus $queryBus;

    private NoticeRecorder $recorder;

    private AgentSubscriptionScope $scope;

    private bool $scopeAllowed = true;

    private bool $promptScopeAllowed = true;

    private ?Throwable $scopeFailure = null;

    private bool $continuity = true;

    private ?Throwable $sourceFailure = null;

    /** @var list<Throwable> */
    private array $failures = [];

    private McpDiagnostics $diagnostics;

    /** @var array<string, Permission> */
    private array $permissions = [];

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-11-11T12:00:00Z');
        $this->clock = new FixedClock($now);
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000501'),
            AgentName::fromString('Live fixture'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000502'),
            'encrypted:fixture',
            $now
        );
        $this->permissionRepository = new InMemoryPermissionRepository();
        foreach (['LISTEN', 'SIGNAL', 'TOOL', 'PROMPT', 'PROMPT_SIGNAL'] as $index => $name) {
            $permission = Permission::defineManaged(
                PermissionId::fromString('018f0000-0000-7000-8000-'.sprintf('%012d', 510 + $index)),
                PermissionName::fromString($name),
                PermissionTier::ADMIN_SAFE,
                $now
            );
            $this->permissions[$name] = $permission;
            $this->permissionRepository->add($permission);
            $this->agent = $this->agent->grantPermission($permission->getId(), $now);
        }

        $this->agentRepository = new InMemoryAgentRepository();
        $this->agentRepository->add($this->agent);

        $unit = new InMemoryUnitOfWork();
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->agentRepository, $unit);
        $request = new SignedAgentRequest(
            'POST',
            'live.example',
            '/mcp',
            '',
            $now,
            'live-nonce',
            $this->agent->getCredentialId(),
            'HMAC-SHA256',
            'signature',
            null,
            ''
        );
        $provider = new CurrentAgentPrincipalProvider(
            $this->agentRepository,
            $this->permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, 'fixture'),
            $this->clock,
            $this->nonces,
            $unit
        );
        $this->binding = AgentContinuationBinding::fromRequest($provider, $request, 'live');
        $this->scope = $this->createStub(AgentSubscriptionScope::class);
        $this->scope->method('allows')->willReturnCallback(function (
            AuthenticatedAgentPrincipal $principal,
            string $filter
        ): bool {
            if ($this->scopeFailure !== null) {
                throw $this->scopeFailure;
            }

            return $this->scopeAllowed && ($filter !== 'promptsListChanged' || (
                $this->promptScopeAllowed && $principal->hasPermission(PermissionName::fromString('PROMPT_SIGNAL'))
            ));
        });
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalNoticeSchema()->install($this->connection);
        $transaction = new DbalNoticeTransaction($this->connection);
        $this->recorder = new NoticeRecorder(
            new DbalNoticeRepository($transaction),
            $transaction,
            $this->createStub(NoticeAdmission::class),
            $this->createStub(EventDispatcher::class)
        );
        $this->tracking = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalSubscriptionSchema()->install($this->tracking);
        $source = $this->createStub(NoticeReadAdmission::class);
        $source->method('currentAccess')->willReturnCallback(function (): NoticeAccess {
            if ($this->sourceFailure !== null) {
                throw $this->sourceFailure;
            }

            // Independent trusted producer context; the listener has no public READ_NOTICES grant.
            return NoticeAccess::create(
                ['project'],
                [],
                new NoticeRecoveryIdentity('source', 'epoch-1', $this->continuity)
            );
        });
        $handler = new RecoverNoticesHandler($source, new NoticeRecovery(
            new DbalNoticeRecoveryReader($this->connection),
            new SodiumNoticeContinuationProtector(['fixture1' => random_bytes(32)], 'fixture1')
        ));
        $router = new InMemoryQueryRouter();
        $router->registerHandlers([$handler::queryRegistration() => $handler]);

        $this->queryBus = new RoutingQueryBus($router);
        $this->diagnostics = $this->createStub(McpDiagnostics::class);
        $this->diagnostics->method('record')->willReturnCallback(function (Throwable $failure): void {
            $this->failures[] = $failure;
        });
    }

    public function test_first_ack_and_real_frames_precede_closure_with_exact_correlation_and_retained_source(): void
    {
        $body = $this->body(id: '007');
        $ack = $body->read(8192);
        self::assertStringContainsString('notifications\\/subscriptions\\/acknowledged', $ack);
        self::assertStringContainsString('"io.modelcontextprotocol\\/subscriptionId":"007"', $ack);
        self::assertStringNotContainsString('promptsListChanged', $ack);
        self::assertFalse($body->eof());
        $this->record('one');
        $frame = $body->read(8192);
        self::assertStringContainsString('notifications\\/tools\\/list_changed', $frame);
        self::assertStringNotContainsString('private', $frame);
        self::assertFalse($body->eof());
        self::assertFalse($this->connection->isTransactionActive());
        $body->close();
        self::assertTrue($body->eof());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame([], $this->failures);
    }

    public function test_queued_permission_and_scope_denial_skip_signal_without_replaying_nonce(): void
    {
        foreach (['SIGNAL', 'LISTEN', 'TOOL', 'scope'] as $change) {
            $body = $this->body();
            self::assertStringContainsString('acknowledged', $body->read(8192));
            $this->record($change);
            if ($change === 'scope') {
                $this->scopeAllowed = false;
            } else {
                $this->save($this->agent->revokePermission($this->permissions[$change]->getId(), $this->clock->now()));
            }

            self::assertSame(": keepalive\n\n", $body->read(8192));
            $body->close();
            $this->scopeAllowed = true;
            if ($change !== 'scope') {
                $this->save($this->agent->grantPermission($this->permissions[$change]->getId(), $this->clock->now()));
            }
        }

        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame([], $this->failures);
    }

    public function test_original_credential_revocation_denies_and_reconnect_is_not_a_lease(): void
    {
        $body = $this->body();
        self::assertStringContainsString('acknowledged', $body->read(8192));
        $this->record('one');
        $this->save($this->agent->revoke($this->clock->now()));
        self::assertSame(": keepalive\n\n", $body->read(8192));
        $body->close();
        $next = $this->body();
        self::assertStringContainsString('"notifications":{}', $next->read(8192));
        $next->close();
    }

    public function test_concurrent_same_request_ids_have_independent_owned_tracking_and_policy(): void
    {
        $other = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000801'),
            AgentName::fromString('Other listener'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000802'),
            'encrypted:fixture',
            $this->clock->now()
        );
        foreach ($this->permissions as $permission) {
            $other = $other->grantPermission($permission->getId(), $this->clock->now());
        }

        $this->agentRepository->add($other);
        $handoff = $this->createStub(AgentContinuationHandoff::class);
        $handoff->method('verify')->willReturn(new AgentContinuationContext(
            $other->getId(),
            $other->getCredentialId(),
            $other->getCredentialRevision(),
            $this->clock->now()
        ));
        $authority = new AgentNoticeAuthority(
            AgentContinuationBinding::fromHandoff($handoff),
            $this->agentRepository,
            $this->permissionRepository,
            $this->clock,
            [PermissionName::fromString('LISTEN'), PermissionName::fromString('SIGNAL')]
        );
        $scope = $this->createStub(AgentSubscriptionScope::class);
        $scope->method('allows')->willReturnCallback(
            static function (
                AuthenticatedAgentPrincipal $principal,
                string $filter,
                ?string $uri,
                ?Notice $notice
            ) use ($other): bool {
                self::assertTrue($principal->getAgentId()->equals($other->getId()));

                return $notice === null;
            }
        );
        $registry = new McpToolRegistry([new RecoverNoticesTool($this->queryBus)]);
        $admission = new AgentSubscriptionAdmission(
            'other-subscriber',
            $authority,
            $authority,
            $scope,
            new AgentSubscriptionFamilies(
                $registry,
                new AgentToolPermissionCatalog($registry, ['notices.recover' => ['TOOL']])
            )
        );
        $one = $this->body(id: 7);
        $two = $this->body(id: 7, admission: $admission);
        self::assertStringContainsString('subscriptionId":7', $one->read(8192));
        self::assertStringContainsString('subscriptionId":7', $two->read(8192));
        self::assertSame(2, (int) $this->tracking->fetchOne(
            'SELECT COUNT(DISTINCT registration_id) FROM fight_mcp_subscriptions'
        ));
        $this->record('one');
        self::assertStringContainsString('notifications\\/tools\\/list_changed', $one->read(8192));
        $one->close();
        self::assertSame(": keepalive\n\n", $two->read(8192));
        $two->close();
        self::assertSame([], $this->failures);
    }

    public function test_source_uncertainty_fault_and_tracking_failure_never_fabricate_ack_or_completion(): void
    {
        $this->continuity = false;
        $body = $this->body();
        self::assertSame('', $body->read(8192));
        self::assertTrue($body->eof());
        self::assertCount(1, $this->failures);
        $this->continuity = true;
        $this->sourceFailure = new RuntimeException('private-source');
        $body = $this->body();
        self::assertSame('', $body->read(8192));
        self::assertCount(2, $this->failures);
        $this->sourceFailure = null;
        $store = $this->createStub(McpSubscriptionStore::class);
        $store->method('open')->willThrowException(new RuntimeException('private-tracking'));
        $body = $this->body(store: $store);
        self::assertSame('', $body->read(8192));
        self::assertCount(3, $this->failures);
    }

    public function test_operational_scope_fault_after_ack_closes_without_success_or_diagnostic_wire_leak(): void
    {
        $body = $this->body();
        self::assertStringContainsString('acknowledged', $body->read(8192));
        $this->record('one');
        $this->scopeFailure = new RuntimeException('private-scope');
        self::assertSame('', $body->read(8192));
        self::assertTrue($body->eof());
        self::assertCount(1, $this->failures);
    }

    public function test_subscriber_and_disabled_family_checks_do_not_use_protocol_metadata_as_authority(): void
    {
        $admission = $this->admission();
        self::assertSame('subscriber', $admission->subscriber());
        self::assertFalse($admission->allows('forged', 'toolsListChanged', null, null));
        self::assertFalse($admission->allows('subscriber', 'promptsListChanged', null, null));
        self::assertFalse($admission->allows('subscriber', 'toolsListChanged', 'docs:a', null));
        self::assertFalse($admission->allows('subscriber', 'resourceSubscriptions', null, null));
        self::assertFalse($admission->allows('subscriber', 'resourcesListChanged', null, null));
        self::assertFalse(new AgentSubscriptionFamilies()->allows($this->principal(), 'toolsListChanged', null));
    }

    public function test_selected_skill_requires_whole_and_every_member_permission_without_open(): void
    {
        $revision = SkillRevision::create();
        $skills = new AgentSkillPermissionCatalog([new AgentSkillPermissionBinding($revision, ['SIGNAL'])]);
        $availability = $this->createMock(McpSkillAvailability::class);
        $availability->expects(self::never())->method('isAvailable');
        $streams = $this->createMock(StreamFactoryInterface::class);
        $streams->expects(self::never())->method('createStream');
        $provider = new McpSkillResources($skills->revisions(), $availability, $streams);
        $bindings = [];
        foreach ($revision->resources() as $uri => $resource) {
            $bindings[] = new AgentResourcePermissionBinding($provider, $uri, ['TOOL']);
        }

        $resources = new AgentResourcePermissionCatalog($bindings);
        $families = new AgentSubscriptionFamilies(resourceCatalog: $resources, skillCatalog: $skills);
        $uri = array_key_first($revision->resources());
        self::assertTrue($families->allows($this->principal(), 'resourceSubscriptions', $uri));
        self::assertTrue($families->allows($this->principal(), 'resourcesListChanged', null));
        self::assertFalse($families->allows($this->principal(), 'resourceSubscriptions', 'skill://unknown/SKILL.md'));
        self::assertFalse($families->allows($this->principal(), 'unknown', null));
        self::assertFalse(new AgentSubscriptionFamilies(resourceCatalog: $resources)->allows(
            $this->principal(),
            'resourceSubscriptions',
            $uri
        ));
        $unrelatedSkills = new AgentSkillPermissionCatalog([
            new AgentSkillPermissionBinding(SkillRevision::create('v2'), ['SIGNAL'])
        ]);
        self::assertFalse(new AgentSubscriptionFamilies(
            resourceCatalog: $resources,
            skillCatalog: $unrelatedSkills
        )->allows($this->principal(), 'resourceSubscriptions', $uri));
        $partial = new AgentResourcePermissionCatalog(array_slice($bindings, 0, 1));
        self::assertFalse(new AgentSubscriptionFamilies(resourceCatalog: $partial, skillCatalog: $skills)->allows(
            $this->principal(),
            'resourceSubscriptions',
            $uri
        ));
        $this->save($this->agent->revokePermission($this->permissions['SIGNAL']->getId(), $this->clock->now()));
        self::assertFalse($families->allows($this->principal(), 'resourceSubscriptions', $uri));
        $this->save($this->agent->grantPermission($this->permissions['SIGNAL']->getId(), $this->clock->now()));
        $this->save($this->agent->revokePermission($this->permissions['TOOL']->getId(), $this->clock->now()));
        self::assertFalse($families->allows($this->principal(), 'resourceSubscriptions', $uri));
        self::assertFalse($families->allows($this->principal(), 'resourcesListChanged', null));
    }

    public function test_ordinary_metadata_only_resource_and_unknown_protected_provider_have_separate_admission(): void
    {
        $resource = McpResourceInfo::fromArray(['uri' => 'docs:saved', 'name' => 'Saved']);
        $provider = $this->createMock(McpReadableResourceProvider::class);
        $provider->method('find')->willReturnCallback(
            static fn(string $uri): ?McpResourceInfo => $uri === $resource->uri() ? $resource : null
        );
        $provider->method('resources')->willReturn([$resource]);
        $provider->expects(self::never())->method('open');
        $catalog = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($provider, 'docs:saved', ['TOOL'])
        ]);
        $families = new AgentSubscriptionFamilies(resourceCatalog: $catalog);
        self::assertTrue($families->allows($this->principal(), 'resourceSubscriptions', 'docs:saved'));
        self::assertTrue($families->allows($this->principal(), 'resourcesListChanged', null));
        self::assertFalse($families->allows($this->principal(), 'resourceSubscriptions', 'docs:absent'));
        $unknown = new ResourceProvider(['docs:saved' => $resource->toArray()], static function (): McpResourceContent {
            throw new RuntimeException('Must not open');
        });
        $catalog = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($unknown, 'docs:saved', ['TOOL'])
        ]);
        self::assertFalse(new AgentSubscriptionFamilies(resourceCatalog: $catalog)->allows(
            $this->principal(),
            'resourceSubscriptions',
            'docs:saved'
        ));
    }

    public function test_tracking_wait_cannot_reuse_an_earlier_notification_allow(): void
    {
        $store = new ControlledSubscriptionStore(new DbalSubscriptionStore($this->tracking));
        $body = $this->body(store: $store);
        self::assertStringContainsString('acknowledged', $body->read(8192));
        $this->record('one');
        $store->beforeHeartbeat = function (int $count): void {
            if ($count === 4) {
                $this->scopeAllowed = false;
            }
        };
        self::assertSame(": keepalive\n\n", $body->read(8192));
        self::assertGreaterThanOrEqual(4, $store->heartbeats);
        $body->close();
        self::assertSame([], $this->failures);
    }

    public function test_native_emit_transport_fault_and_idle_lifetime_preserve_committed_source(): void
    {
        $body = $this->body();
        $body->read(8192);
        $this->record('one');
        try {
            $body->emitNext(static function (): void {
                throw new RuntimeException('private-transport');
            });
            self::fail('Transport must propagate to its caller');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('private-transport', $runtimeException->getMessage());
        } finally {
            $body->close();
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
        $idle = $this->body();
        self::assertStringContainsString('acknowledged', $idle->read(8192));
        $rest = $idle->getContents();
        self::assertStringContainsString(': keepalive', $rest);
        self::assertStringContainsString('"resultType":"complete"', $rest);
        self::assertTrue($idle->eof());
    }

    public function test_slow_reader_loses_technical_freshness_without_healthy_completion(): void
    {
        $body = $this->body();
        self::assertStringContainsString('acknowledged', $body->read(8192));
        sleep(6);
        self::assertSame('', $body->read(8192));
        self::assertTrue($body->eof());
        self::assertCount(1, $this->failures);
    }

    public function test_resource_metadata_budget_and_mismatched_tool_composition_fail_explicitly(): void
    {
        $resource = McpResourceInfo::fromArray(['uri' => 'docs:saved', 'name' => 'Saved']);
        $provider = $this->createStub(McpReadableResourceProvider::class);
        $provider->method('find')->willReturn($resource);
        $provider->method('resources')->willReturnCallback(static function (): iterable {
            for ($index = 0; $index < 10001; ++$index) {
                yield McpResourceInfo::fromArray(['uri' => 'docs:'.$index, 'name' => 'Metadata']);
            }
        });
        $catalog = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($provider, 'docs:saved', ['TOOL'])
        ]);
        try {
            new AgentSubscriptionFamilies(toolRegistry: new McpToolRegistry([]));
            self::fail('Mismatched composition must fail');
        } catch (LogicException $logicException) {
            self::assertSame(
                'Subscription Tool composition must share a registry and catalog.',
                $logicException->getMessage()
            );
        }

        $this->expectException(LogicException::class);
        new AgentSubscriptionFamilies(resourceCatalog: $catalog)->allows(
            $this->principal(),
            'resourcesListChanged',
            null
        );
    }

    public function test_independently_composed_disclosure_cannot_switch_original_agent_binding(): void
    {
        $other = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000601'),
            AgentName::fromString('Other fixture'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000602'),
            'encrypted:fixture',
            $this->clock->now()
        )->grantPermission($this->permissions['SIGNAL']->getId(), $this->clock->now());
        $this->agentRepository->add($other);
        $handoff = $this->createStub(AgentContinuationHandoff::class);
        $handoff->method('verify')->willReturn(new AgentContinuationContext(
            $other->getId(),
            $other->getCredentialId(),
            $other->getCredentialRevision(),
            $this->clock->now()
        ));
        $admission = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority('LISTEN'),
            new AgentNoticeAuthority(
                AgentContinuationBinding::fromHandoff($handoff),
                $this->agentRepository,
                $this->permissionRepository,
                $this->clock,
                [PermissionName::fromString('SIGNAL')]
            ),
            $this->scope,
            new AgentSubscriptionFamilies()
        );
        $this->expectException(LogicException::class);
        $admission->allows('subscriber', 'toolsListChanged', null, Notice::fromArray([
            'version'       => 1,
            'id'            => 'one',
            'producer'      => 'producer',
            'scope'         => 'project',
            'topic'         => 'catalog',
            'type'          => 'mcp.tools.list_changed',
            'occurred_at'   => null,
            'metadata'      => [],
            'relationships' => [
                'subject'    => null,
                'recipients' => [],
                'author'     => null,
                'command'    => null,
                'event'      => null,
                'action'     => null
            ]
        ]));
    }

    public function test_disclosure_observing_changed_assignment_between_authority_reads_denies(): void
    {
        $repository = new InMemoryAgentRepository();
        $repository->add($this->agent->revokePermission($this->permissions['LISTEN']->getId(), $this->clock->now()));

        $admission = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority('LISTEN'),
            new AgentNoticeAuthority(
                $this->binding,
                $repository,
                $this->permissionRepository,
                $this->clock,
                [PermissionName::fromString('SIGNAL')]
            ),
            $this->scope,
            new AgentSubscriptionFamilies()
        );
        $this->record('one');
        $notice = $this->queryBus->fetch(new RecoverNotices(
            NoticeSelection::create(['project'])
        ))->notices()[0]->notice();
        self::assertFalse($admission->allows('subscriber', 'toolsListChanged', null, $notice));
    }

    public function test_real_skill_resource_notifications_map_only_exact_selected_members_without_opening(): void
    {
        $revision = SkillRevision::create();
        $skills = new AgentSkillPermissionCatalog([new AgentSkillPermissionBinding($revision, ['SIGNAL'])]);
        $availability = $this->createMock(McpSkillAvailability::class);
        $availability->expects(self::never())->method('isAvailable');
        $streams = $this->createMock(StreamFactoryInterface::class);
        $streams->expects(self::never())->method('createStream');
        $provider = new McpSkillResources($skills->revisions(), $availability, $streams);
        $bindings = [];
        foreach (array_keys($revision->resources()) as $uri) {
            $bindings[] = new AgentResourcePermissionBinding($provider, $uri, ['TOOL']);
        }

        $resources = new AgentResourcePermissionCatalog($bindings);
        $families = new AgentSubscriptionFamilies(resourceCatalog: $resources, skillCatalog: $skills);
        $admission = new AgentSubscriptionAdmission(
            'subscriber',
            $this->authority('LISTEN'),
            $this->authority('SIGNAL'),
            $this->scope,
            $families
        );
        $uri = array_key_first($revision->resources());
        $request = new McpRequest(5, 'subscriptions/listen', ['notifications' => StrictJson::fromObject([
            'resourcesListChanged'  => true,
            'resourceSubscriptions' => [$uri, 'skill://absent/SKILL.md']
        ])], McpRequestMetadata::fromObject(StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => StrictJson::fromObject([])
        ])));
        $subscriptions = new McpSubscriptions(
            $this->queryBus,
            new DbalSubscriptionStore($this->tracking),
            $admission,
            NoticeSelection::create(['project']),
            tools: false,
            lifetimeSeconds: 1,
            waitMicroseconds: 10000
        );
        $responder = new McpResponder(new McpCapabilityRegistry(new McpServerInfo('fixture', '1'), []));
        $body = new McpEventStream($request, $responder, $this->diagnostics, $subscriptions->execution($request));
        $ack = $body->read(8192);
        self::assertStringNotContainsString('absent', $ack);
        self::assertStringContainsString('resourceSubscriptions', $ack);
        $this->record('list', 'mcp.resources.list_changed');
        self::assertStringContainsString('notifications\\/resources\\/list_changed', $body->read(8192));
        $this->record('update', 'mcp.resources.updated', $uri);
        $bytes = $body->read(8192);
        if ($bytes === ": keepalive\n\n") {
            $bytes = $body->read(8192);
        }

        $frame = json_decode(substr($bytes, 6), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('notifications/resources/updated', $frame['method']);
        self::assertSame($uri, $frame['params']['uri']);
        $this->save($this->agent->revokePermission($this->permissions['TOOL']->getId(), $this->clock->now()));
        $this->record('denied-member', 'mcp.resources.updated', $uri);
        self::assertSame(": keepalive\n\n", $body->read(8192));
        $body->close();
        self::assertSame([], $this->failures);
    }

    public function test_interrupted_delivery_retains_notices_but_listening_never_grants_recovery(): void
    {
        $body = $this->body();
        $body->read(8192);
        $this->record('retained');
        $body->close();
        try {
            $this->recovery('denied');
            self::fail('Listen must not imply public notice recovery');
        } catch (NoticeReadFailed $noticeReadFailed) {
            self::assertNotNull($noticeReadFailed->getPrevious());
        }

        $read = Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000799'),
            PermissionName::fromString('READ_NOTICES'),
            PermissionTier::ADMIN_SAFE,
            $this->clock->now()
        );
        $this->permissionRepository->add($read);
        $this->save($this->agent->grantPermission($read->getId(), $this->clock->now()));
        $batch = $this->recovery('allowed');
        self::assertSame('complete', $batch->status());
        self::assertSame('retained', $batch->notices()[0]->notice()->toArray()['id']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
    }

    public function test_real_opening_to_independent_inspection_protects_full_selection_and_live_pages(): void
    {
        $bodies = [];
        foreach (['same', 'same', 'other', 'CONCEALED'] as $subscriber) {
            $body = $this->body(id: 7, subscriber: $subscriber);
            self::assertStringContainsString('acknowledged', $body->read(8192));
            $bodies[] = $body;
        }

        $selection = SubscriberSelection::create(['project']);
        $policy = $this->createStub(AgentSubscriberReadPolicy::class);
        $ids = $this->tracking->fetchFirstColumn(
            'SELECT registration_id FROM fight_mcp_subscriptions WHERE context NOT LIKE ?',
            ['%CONCEALED%']
        );
        $access = SubscriberAccess::create(['project'], $ids);
        $policy->method('accessFor')->willReturnCallback(function (
            AuthenticatedAgentPrincipal $principal
        ) use (&$access) {
            self::assertTrue($principal->getAgentId()->equals($this->agent->getId()));

            return $access;
        });
        $this->grantInspection();
        $queryBus = $this->inspection($policy, 'inspect-one');
        $before = $this->trackingRows();
        $summary = $queryBus->fetch(new SummarizeSubscribers($selection))->toArray();
        self::assertSame(3, $summary['subscriptions']);
        self::assertSame(2, $summary['subscriber_ids']);
        self::assertSame('live', $summary['scope']['consistency']);
        $seen = [];
        for ($page = 1; $page <= 3; ++$page) {
            $data = $queryBus->fetch(new ListSubscribers($selection, $page, 1, ['opened_at' => 'ASC']))->toArray();
            self::assertSame(3, $data['total']);
            self::assertCount(1, $data['subscribers']);
            $seen[] = $data['subscribers'][0]['registration_id'];
            self::assertTrue($data['subscribers'][0]['requested']->get('promptsListChanged'));
            self::assertNull($data['subscribers'][0]['accepted']->get('promptsListChanged'));
            self::assertStringNotContainsString('CONCEALED', json_encode($data, JSON_THROW_ON_ERROR));
        }

        self::assertCount(3, array_unique($seen));
        $orderedIds = $this->tracking->fetchFirstColumn(
            <<<SQL
            SELECT registration_id FROM fight_mcp_subscriptions WHERE context NOT LIKE ?
            ORDER BY opened_at ASC, registration_id ASC
            SQL,
            ['%CONCEALED%']
        );
        self::assertSame($orderedIds, $seen);
        self::assertSame($before, $this->trackingRows());
        self::assertSame(1, $this->nonces->consumptionCalls());
        // Consumer scope is consulted again, not copied from the previous page.
        $access = SubscriberAccess::create(['project'], [$ids[0]]);
        self::assertSame(1, $queryBus->fetch(new ListSubscribers($selection, 2, 1))->toArray()['total']);
        self::assertSame([], $queryBus->fetch(new ListSubscribers($selection, 2, 1))->toArray()['subscribers']);
        $access = SubscriberAccess::create([]);
        self::assertSame(0, $this->inspection($policy, 'inspect-two')->fetch(
            new SummarizeSubscribers($selection)
        )->toArray()['subscriptions']);
        foreach ($bodies as $body) {
            $body->close();
        }
    }

    public function test_listen_signal_and_tool_grants_cannot_authorize_direct_inspection_or_forged_labels(): void
    {
        $body = $this->body();
        $body->read(8192);

        $policy = $this->createMock(AgentSubscriberReadPolicy::class);
        $policy->expects(self::never())->method('accessFor');
        foreach (
            [new SummarizeSubscribers(SubscriberSelection::create(['project'])), new ListSubscribers(
                SubscriberSelection::create(['project'], ['subscriber' => 'SUPER_ADMIN'])
            )] as $query
        ) {
            try {
                $this->inspection($policy, bin2hex(random_bytes(8)))->fetch($query);
                self::fail('Listen, identity labels and reused protocol IDs cannot grant inspection');
            } catch (SubscriberReadFailed $failure) {
                self::assertSame('Subscriber inspection unavailable.', $failure->getMessage());
                self::assertSame(
                    'Subscriber inspection permission is required.',
                    $failure->getPrevious()->getMessage()
                );
            }
        }

        $body->close();
    }

    public function test_later_requests_recheck_permission_but_resolved_request_keeps_ordinary_snapshot(): void
    {
        $this->grantInspection();
        $policy = $this->createStub(AgentSubscriberReadPolicy::class);
        $policy->method('accessFor')->willReturn(SubscriberAccess::create(['project']));
        $queryBus = $this->inspection($policy, 'snapshot');
        $query = new SummarizeSubscribers(SubscriberSelection::create(['project']));
        self::assertSame(0, $queryBus->fetch($query)->toArray()['subscriptions']);
        $this->save($this->agent->revokePermission($this->permissions['INSPECT']->getId(), $this->clock->now()));
        self::assertSame(0, $queryBus->fetch($query)->toArray()['subscriptions']);
        $this->expectException(SubscriberReadFailed::class);
        $this->inspection($policy, 'fresh')->fetch($query);
    }

    public function test_real_clean_disconnect_expiry_and_cleanup_are_observations_not_read_effects(): void
    {
        $this->grantInspection();
        $policy = $this->createStub(AgentSubscriberReadPolicy::class);
        $policy->method('accessFor')->willReturn(SubscriberAccess::create(['project']));
        $one = $this->body();
        $one->read(8192);

        $two = $this->body();
        $two->read(8192);

        $one->close();
        $query = new SummarizeSubscribers(SubscriberSelection::create(['project']));
        $queryBus = $this->inspection($policy, 'lifecycle');
        self::assertSame(
            ['recently_observed' => 1, 'disconnected' => 1, 'stale_uncertain' => 0],
            $queryBus->fetch($query)->toArray()['states']
        );
        $rows = $this->trackingRows();
        $expired = $this->inspection($policy, 'expiry', time() + 6);
        self::assertSame(
            ['recently_observed' => 0, 'disconnected' => 1, 'stale_uncertain' => 1],
            $expired->fetch($query)->toArray()['states']
        );
        self::assertSame($rows, $this->trackingRows());
        // Only the owning lifecycle cleans; a late close/heartbeat cannot resurrect a removed registration.
        self::assertSame(2, new DbalSubscriptionStore($this->tracking)->cleanup(time() + 6, 256));
        self::assertSame(0, $expired->fetch($query)->toArray()['subscriptions']);
        $two->close();
        self::assertSame([], $this->trackingRows());
    }

    public function test_complete_row_scope_constrains_filters_references_freshness_and_every_total(): void
    {
        $this->grantInspection();
        $bodies = [];
        foreach ([['project'], ['hidden'], ['project', 'hidden']] as $scopes) {
            $body = $this->body(subscriber: implode('-', $scopes), selection: NoticeSelection::create(
                $scopes,
                ['topic' => 'topic-'.implode('-', $scopes)]
            ));
            self::assertStringContainsString('acknowledged', $body->read(8192));
            $bodies[] = $body;
        }

        $policy = $this->createStub(AgentSubscriberReadPolicy::class);
        $policy->method('accessFor')->willReturn(SubscriberAccess::create(['project']));
        $queryBus = $this->inspection($policy, 'whole-metadata');
        $selection = SubscriberSelection::create(['project', 'hidden']);
        $summary = $queryBus->fetch(new SummarizeSubscribers($selection))->toArray();
        self::assertSame(1, $summary['subscriptions']);
        self::assertSame(1, $summary['subscriber_ids']);
        $page = $queryBus->fetch(new ListSubscribers($selection))->toArray();
        self::assertSame(1, $page['total']);
        self::assertStringNotContainsString('hidden', json_encode([$summary, $page], JSON_THROW_ON_ERROR));
        self::assertSame(
            ['docs:requested-private'],
            $page['subscribers'][0]['requested']->get('resourceSubscriptions')
        );
        foreach (
            [
            ['requested_resource' => 'docs:requested-private', 'expected' => 1],
            ['accepted_resource' => 'docs:requested-private', 'expected' => 0],
            ['requested_filter' => 'promptsListChanged', 'expected' => 1],
            ['accepted_filter' => 'promptsListChanged', 'expected' => 0],
            ['topic' => 'topic-hidden', 'expected' => 0],
            ['subscriber' => 'hidden', 'expected' => 0]
            ] as $case
        ) {
            $expected = $case['expected'];
            unset($case['expected']);
            $selected = SubscriberSelection::create(['project', 'hidden'], $case);
            self::assertSame(
                $expected,
                $queryBus->fetch(new SummarizeSubscribers($selected))->toArray()['subscriptions']
            );
            self::assertSame($expected, $queryBus->fetch(new ListSubscribers($selected))->toArray()['total']);
        }

        foreach ($bodies as $body) {
            $body->close();
        }
    }

    public function test_policy_and_storage_faults_are_explicit_safe_failures_not_empty_success(): void
    {
        $this->grantInspection();
        $policy = $this->createStub(AgentSubscriberReadPolicy::class);
        $policy->method('accessFor')->willThrowException(new RuntimeException('private-credential-filter'));
        $selection = SubscriberSelection::create(['project']);
        foreach ([new SummarizeSubscribers($selection), new ListSubscribers($selection)] as $query) {
            try {
                $this->inspection($policy, bin2hex(random_bytes(8)))->fetch($query);
                self::fail('Policy faults cannot become denial or empty success');
            } catch (SubscriberReadFailed $failure) {
                self::assertSame('Subscriber inspection unavailable.', $failure->getMessage());
                self::assertNotNull($failure->getPrevious());
            }
        }

        $policy = $this->createStub(AgentSubscriberReadPolicy::class);
        $policy->method('accessFor')->willReturn(SubscriberAccess::create(['project']));
        $this->tracking->executeStatement('DROP TABLE fight_mcp_subscriptions');
        $this->expectException(SubscriberReadFailed::class);
        $this->inspection($policy, 'storage')->fetch(new ListSubscribers($selection));
    }

    private function grantInspection(): void
    {
        $permission = Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000798'),
            PermissionName::fromString('INSPECT'),
            PermissionTier::ADMIN_SAFE,
            $this->clock->now()
        );
        $this->permissions['INSPECT'] = $permission;
        $this->permissionRepository->add($permission);
        $this->save($this->agent->grantPermission($permission->getId(), $this->clock->now()));
    }

    private function inspection(
        AgentSubscriberReadPolicy $policy,
        string $nonce,
        ?int $observedAt = null
    ): RoutingQueryBus {
        $unit = new InMemoryUnitOfWork();
        $request = new SignedAgentRequest(
            'POST',
            'live.example',
            '/inspect',
            '',
            $this->clock->now(),
            $nonce,
            $this->agent->getCredentialId(),
            'HMAC-SHA256',
            'signature',
            null,
            ''
        );
        $provider = new CurrentAgentPrincipalProvider(
            $this->agentRepository,
            $this->permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, 'fixture'),
            $this->clock,
            new InMemoryAgentRequestNonceConsumer($this->agentRepository, $unit),
            $unit
        );
        $admission = new AgentSubscriberReadAdmission(
            $provider,
            $request,
            'inspection',
            [PermissionName::fromString('INSPECT')],
            $policy
        );
        $reader = new DbalSubscriberReader(
            $this->tracking,
            'controlled-sqlite',
            static fn(): int => $observedAt ?? time()
        );
        $summary = new SummarizeSubscribersHandler($admission, $reader);
        $list = new ListSubscribersHandler($admission, $reader);
        $router = new InMemoryQueryRouter();
        $router->registerHandlers([$summary::queryRegistration() => $summary, $list::queryRegistration() => $list]);

        return new RoutingQueryBus($router);
    }

    /** @return list<array<string, mixed>> */
    private function trackingRows(): array
    {
        return $this->tracking->fetchAllAssociative('SELECT * FROM fight_mcp_subscriptions ORDER BY registration_id');
    }

    private function recovery(string $nonce): NoticeRecoveryBatch
    {
        $unit = new InMemoryUnitOfWork();
        $nonces = new InMemoryAgentRequestNonceConsumer($this->agentRepository, $unit);
        $request = new SignedAgentRequest(
            'POST',
            'live.example',
            '/recover',
            '',
            $this->clock->now(),
            $nonce,
            $this->agent->getCredentialId(),
            'HMAC-SHA256',
            'signature',
            null,
            ''
        );
        $provider = new CurrentAgentPrincipalProvider(
            $this->agentRepository,
            $this->permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, 'fixture'),
            $this->clock,
            $nonces,
            $unit
        );
        $policy = $this->createStub(AgentNoticeReadPolicy::class);
        $policy->method('accessFor')->willReturn(NoticeAccess::create(
            ['project'],
            [],
            new NoticeRecoveryIdentity('reader', 'epoch-1', true)
        ));
        $handler = new RecoverNoticesHandler(new AgentNoticeReadAdmission(
            $provider,
            $request,
            'recovery',
            [PermissionName::fromString('READ_NOTICES')],
            $policy
        ), new NoticeRecovery(
            new DbalNoticeRecoveryReader($this->connection),
            new SodiumNoticeContinuationProtector(['fixture1' => random_bytes(32)], 'fixture1')
        ));
        $router = new InMemoryQueryRouter();
        $router->registerHandlers([$handler::queryRegistration() => $handler]);

        return new RoutingQueryBus($router)->fetch(new RecoverNotices(NoticeSelection::create(['project'])));
    }

    /** @return iterable<string, array{string}> */
    public static function storageFaults(): iterable
    {
        yield 'source' => ['source'];
        yield 'tracking' => ['tracking'];
    }

    #[DataProvider('storageFaults')]
    public function test_real_storage_faults_after_ack_are_not_empty_success(string $fault): void
    {
        $body = $this->body();
        self::assertStringContainsString('acknowledged', $body->read(8192));
        if ($fault === 'source') {
            $this->connection->executeStatement('DROP TABLE fight_notices');
        } else {
            $this->tracking->executeStatement('DROP TABLE fight_mcp_subscriptions');
        }

        $wire = $body->getContents();
        self::assertStringNotContainsString('complete', $wire);
        self::assertStringNotContainsString('SQL', $wire);
        self::assertTrue($body->eof());
        self::assertNotEmpty($this->failures);
    }

    /** @return iterable<string, array{string}> */
    public static function resourceFilters(): iterable
    {
        yield 'catalog' => ['resourcesListChanged'];
        yield 'selected' => ['resourceSubscriptions'];
    }

    #[DataProvider('resourceFilters')]
    public function test_ambiguous_resource_owners_fault_instead_of_borrowing_a_binding(string $filter): void
    {
        $one = McpResourceInfo::fromArray(['uri' => 'docs:one', 'name' => 'One']);
        $two = McpResourceInfo::fromArray(['uri' => 'docs:two', 'name' => 'Two']);
        $first = $this->createStub(McpReadableResourceProvider::class);
        $first->method('find')->willReturnCallback(
            static fn(string $uri): ?McpResourceInfo => $uri === 'docs:one' ? $one : null
        );
        $first->method('resources')->willReturn([$one]);
        $second = $this->createStub(McpReadableResourceProvider::class);
        $second->method('find')->willReturnCallback(
            static fn(string $uri): McpResourceInfo => $uri === 'docs:one' ? $one : $two
        );
        $second->method('resources')->willReturn([$one, $two]);
        $catalog = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($first, 'docs:one', ['TOOL']),
            new AgentResourcePermissionBinding($second, 'docs:two', ['TOOL'])
        ]);
        $this->expectException(LogicException::class);
        new AgentSubscriptionFamilies(resourceCatalog: $catalog)->allows(
            $this->principal(),
            $filter,
            $filter === 'resourceSubscriptions' ? 'docs:one' : null
        );
    }

    public function test_real_bounded_source_batch_refuses_incomplete_live_queue_without_deleting_notices(): void
    {
        $body = $this->body();
        self::assertStringContainsString('acknowledged', $body->read(8192));
        for ($index = 0; $index < 33; ++$index) {
            $this->record('queued-'.$index);
        }

        self::assertSame('', $body->read(8192));
        self::assertTrue($body->eof());
        self::assertCount(1, $this->failures);
        self::assertSame(33, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
    }

    public function test_real_tracking_capacity_does_not_replace_an_existing_same_id_stream(): void
    {
        $one = $this->body(store: new DbalSubscriptionStore($this->tracking, maxActive: 1));
        self::assertStringContainsString('acknowledged', $one->read(8192));
        $two = $this->body(store: new DbalSubscriptionStore($this->tracking, maxActive: 1));
        self::assertSame('', $two->read(8192));
        self::assertTrue($two->eof());
        self::assertCount(1, $this->failures);
        $this->record('one');
        self::assertStringContainsString('notifications\\/tools\\/list_changed', $one->read(8192));
        $one->close();
    }

    public function test_resolver_fault_after_open_is_not_boolean_denial_or_success(): void
    {
        $body = $this->body();
        self::assertStringContainsString('acknowledged', $body->read(8192));
        $this->record('one');
        self::assertTrue($this->permissionRepository->remove($this->permissions['TOOL']));
        self::assertSame('', $body->read(8192));
        self::assertTrue($body->eof());
        self::assertCount(1, $this->failures);
    }

    public function test_prompt_optional_enabled_disabled_absent_empty_and_unrequested_subsets(): void
    {
        [$catalog, $provider] = $this->prompts();
        foreach (['enabled', 'disabled', 'absent', 'empty', 'unrequested', 'scope'] as $mode) {
            $this->promptScopeAllowed = $mode !== 'scope';
            $selected = match ($mode) {
                'absent' => null,
                'empty' => new AgentPromptPermissionCatalog([]),
                default => $catalog
            };
            $body = $this->body(
                admission: $this->admission(prompts: $selected),
                prompts: $mode !== 'disabled',
                requestedPrompts: $mode !== 'unrequested'
            );
            $ack = json_decode(substr($body->read(8192), 6), true, flags: JSON_THROW_ON_ERROR);
            $expected = ['toolsListChanged' => true];
            if ($mode === 'enabled') {
                $expected['promptsListChanged'] = true;
            }

            self::assertSame($expected, $ack['params']['notifications'], $mode);
            $this->record('prompt-'.$mode, 'mcp.prompts.list_changed');
            $wire = $body->read(8192);
            if ($mode === 'enabled') {
                self::assertStringContainsString('notifications\\/prompts\\/list_changed', $wire);
            } else {
                self::assertSame(": keepalive\n\n", $wire, $mode);
            }

            $body->close();
        }

        self::assertSame(1, $provider->definitions);
        self::assertSame(0, $provider->calls);
        self::assertSame([], $provider->arguments);
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame([], $this->failures);
        $families = new AgentSubscriptionFamilies(promptCatalog: $catalog);
        self::assertFalse($families->allows($this->principal(), 'promptsListChanged', 'docs:forged'));
    }

    public function test_prompt_advertisement_derives_only_from_actual_optional_common_registration(): void
    {
        [$catalog, $provider] = $this->prompts();
        $discovery = $this->promptDiscovery($catalog, 'advertisement');
        $subscriptions = new McpSubscriptions(
            $this->queryBus,
            new DbalSubscriptionStore($this->tracking),
            $this->admission(prompts: $catalog),
            NoticeSelection::create(['project']),
            tools: false,
            resources: false,
            prompts: true
        );
        self::assertSame(['promptsListChanged'], $subscriptions->supported());
        $server = new McpServerInfo('fixture', '1');
        self::assertSame(['listChanged' => false], new McpCapabilityRegistry(
            $server,
            [$discovery]
        )->advertisedCapabilities()['prompts']);
        self::assertSame(['listChanged' => true], new McpCapabilityRegistry(
            $server,
            [$discovery],
            $subscriptions
        )->advertisedCapabilities()['prompts']);
        self::assertSame(0, $provider->calls);
        self::assertSame(1, $provider->definitions);
    }

    public function test_same_id_prompt_streams_keep_independent_scope_and_generic_tracking(): void
    {
        [$catalog, $provider] = $this->prompts();
        $scope = $this->createStub(AgentSubscriptionScope::class);
        $scope->method('allows')->willReturnCallback(
            static fn(AuthenticatedAgentPrincipal $principal, string $filter, ?string $uri, ?Notice $notice): bool =>
                $notice === null
        );
        $other = new AgentSubscriptionAdmission(
            'other',
            $this->authority('LISTEN'),
            $this->authority('SIGNAL'),
            $scope,
            new AgentSubscriptionFamilies(promptCatalog: $catalog)
        );
        $one = $this->body(id: 7, admission: $this->admission(prompts: $catalog), prompts: true);
        $two = $this->body(id: 7, admission: $other, prompts: true);
        self::assertStringContainsString('promptsListChanged', $one->read(8192));
        self::assertStringContainsString('promptsListChanged', $two->read(8192));
        self::assertSame(2, (int) $this->tracking->fetchOne(
            'SELECT COUNT(DISTINCT registration_id) FROM fight_mcp_subscriptions'
        ));
        $this->record('prompt-isolation', 'mcp.prompts.list_changed');
        self::assertStringContainsString('notifications\\/prompts\\/list_changed', $one->read(8192));
        $one->close();
        self::assertSame(": keepalive\n\n", $two->read(8192));
        $two->close();
        self::assertSame(0, $provider->calls);
        self::assertSame([], $this->failures);
    }

    public function test_prompt_final_check_after_tracking_wait_does_not_reuse_earlier_scope(): void
    {
        [$catalog, $provider] = $this->prompts();
        $store = new ControlledSubscriptionStore(new DbalSubscriptionStore($this->tracking));
        $body = $this->body(store: $store, admission: $this->admission(prompts: $catalog), prompts: true);
        self::assertStringContainsString('promptsListChanged', $body->read(8192));
        $this->record('queued-prompt', 'mcp.prompts.list_changed');
        $store->beforeHeartbeat = function (int $count): void {
            if ($count === 4) {
                $this->promptScopeAllowed = false;
            }
        };
        self::assertSame(": keepalive\n\n", $body->read(8192));
        self::assertGreaterThanOrEqual(4, $store->heartbeats);
        $body->close();
        self::assertSame(0, $provider->calls);
        self::assertSame([], $this->failures);
    }

    /** @return iterable<string, array{string}> */
    public static function promptChanges(): iterable
    {
        $changes = ['LISTEN', 'SIGNAL', 'PROMPT_SIGNAL', 'PROMPT', 'scope', 'revocation', 'rotation', 'absent'];
        foreach ($changes as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('promptChanges')]
    public function test_queued_prompt_rechecks_current_original_authority_and_scope(string $change): void
    {
        [$catalog, $provider] = $this->prompts();
        $body = $this->body(admission: $this->admission(prompts: $catalog), prompts: true);
        self::assertStringContainsString('promptsListChanged', $body->read(8192));
        $this->record('queued-prompt', 'mcp.prompts.list_changed');
        if ($change === 'scope') {
            $this->promptScopeAllowed = false;
        } elseif ($change === 'revocation') {
            $this->save($this->agent->revoke($this->clock->now()));
        } elseif ($change === 'rotation') {
            $this->save($this->agent->rotateRecoverableCredential(
                $this->agent->getCredentialId(),
                $this->agent->getCredentialRevision(),
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000999'),
                'encrypted:successor',
                $this->clock->now()
            ));
        } elseif ($change === 'absent') {
            $this->agentRepository->restoreSnapshot([]);
        } else {
            $this->save($this->agent->revokePermission($this->permissions[$change]->getId(), $this->clock->now()));
        }

        self::assertSame(": keepalive\n\n", $body->read(8192));
        $body->close();
        self::assertSame(0, $provider->calls);
        self::assertSame([], $provider->arguments);
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame([], $this->failures);
    }

    public function test_prompt_visibility_is_not_signal_scope_and_signal_does_not_grant_later_get(): void
    {
        [$catalog, $provider] = $this->prompts();
        $this->save($this->agent->revokePermission($this->permissions['PROMPT_SIGNAL']->getId(), $this->clock->now()));
        $discovery = $this->promptDiscovery($catalog, 'visible');
        $metadata = McpRequestMetadata::fromObject(StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => StrictJson::fromObject([])
        ]));
        $visible = $discovery->handle(new McpRequest(1, 'prompts/list', [], $metadata))->toArray();
        self::assertCount(1, $visible['prompts']);
        $body = $this->body(admission: $this->admission(prompts: $catalog), prompts: true);
        self::assertStringNotContainsString('promptsListChanged', $body->read(8192));
        $body->close();
        $this->save($this->agent->grantPermission($this->permissions['PROMPT_SIGNAL']->getId(), $this->clock->now()));
        $body = $this->body(admission: $this->admission(prompts: $catalog), prompts: true);
        self::assertStringContainsString('promptsListChanged', $body->read(8192));
        $this->record('allowed-prompt', 'mcp.prompts.list_changed');
        self::assertStringContainsString('notifications\\/prompts\\/list_changed', $body->read(8192));
        $body->close();
        $this->save($this->agent->revokePermission($this->permissions['PROMPT']->getId(), $this->clock->now()));
        $discovery = $this->promptDiscovery($catalog, 'denied-later');
        $hidden = $discovery->handle(new McpRequest(2, 'prompts/list', [], $metadata))->toArray();
        self::assertSame([], $hidden['prompts']);
        $responder = new McpResponder(new McpCapabilityRegistry(new McpServerInfo('fixture', '1'), [$discovery]));
        $response = $responder->respond(json_encode([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => [
                'name'  => 'protected_prompt',
                '_meta' => [
                    McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                    McpRequestMetadata::CLIENT_CAPABILITIES_KEY => StrictJson::fromObject([])
                ]
            ]
        ], JSON_THROW_ON_ERROR))->toArray();
        self::assertArrayHasKey('error', $response);
        self::assertSame(0, $provider->calls);
        self::assertSame([], $provider->arguments);
    }

    /** @return iterable<string, array{string}> */
    public static function promptFaults(): iterable
    {
        foreach (['scope', 'resolver', 'source', 'tracking', 'transport'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('promptFaults')]
    public function test_prompt_fault_closure_retains_committed_recovery_without_content_or_wire_diagnostics(
        string $fault
    ): void {
        [$catalog, $provider] = $this->prompts();
        $body = $this->body(admission: $this->admission(prompts: $catalog), prompts: true);
        self::assertStringContainsString('promptsListChanged', $body->read(8192));
        $this->record('retained-prompt', 'mcp.prompts.list_changed');
        if ($fault === 'scope') {
            $this->scopeFailure = new RuntimeException('private-prompt-body-secret');
        } elseif ($fault === 'resolver') {
            $this->permissionRepository->remove($this->permissions['PROMPT']);
        } elseif ($fault === 'source') {
            $this->sourceFailure = new RuntimeException('private-source-credential');
        } elseif ($fault === 'tracking') {
            $this->tracking->executeStatement('DROP TABLE fight_mcp_subscriptions');
        }

        if ($fault === 'transport') {
            try {
                $body->emitNext(static function (): void {
                    throw new RuntimeException('private-transport');
                });
                self::fail('Transport failure must propagate');
            } catch (RuntimeException $failure) {
                self::assertSame('private-transport', $failure->getMessage());
            }
        } else {
            self::assertSame('', $body->read(8192));
            self::assertTrue($body->eof());
            self::assertNotEmpty($this->failures);
        }

        $body->close();
        self::assertSame(0, $provider->calls);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
        // Restore the independent read fixture; delivery faults never remove committed source notices.
        if ($fault === 'resolver') {
            $this->permissionRepository->add($this->permissions['PROMPT']);
        }

        $read = Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000799'),
            PermissionName::fromString('READ_NOTICES'),
            PermissionTier::ADMIN_SAFE,
            $this->clock->now()
        );
        $this->permissionRepository->add($read);
        $this->save($this->agent->grantPermission($read->getId(), $this->clock->now()));
        foreach (['first', 'repeat'] as $nonce) {
            $batch = $this->recovery($nonce);
            self::assertSame('complete', $batch->status());
            self::assertSame('retained-prompt', $batch->notices()[0]->notice()->toArray()['id']);
        }
    }

    /** @return array{AgentPromptPermissionCatalog, PromptProvider} */
    private function prompts(): array
    {
        $provider = new PromptProvider(McpPromptInfo::fromArray([
            'name'      => 'protected_prompt',
            'arguments' => [['name' => 'required_argument', 'required' => true]]
        ]), static function (): McpPromptOutput {
            throw new RuntimeException('private-prompt-body-must-not-be-constructed');
        });

        return [new AgentPromptPermissionCatalog([
            new AgentPromptPermissionBinding($provider, ['PROMPT'])
        ]), $provider];
    }

    private function promptDiscovery(AgentPromptPermissionCatalog $catalog, string $nonce): McpPromptDiscovery
    {
        $unit = new InMemoryUnitOfWork();
        $request = new SignedAgentRequest(
            'POST',
            'live.example',
            '/mcp',
            '',
            $this->clock->now(),
            $nonce,
            $this->agent->getCredentialId(),
            'HMAC-SHA256',
            'signature',
            null,
            ''
        );
        $provider = new CurrentAgentPrincipalProvider(
            $this->agentRepository,
            $this->permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($request, 'fixture'),
            $this->clock,
            new InMemoryAgentRequestNonceConsumer($this->agentRepository, $unit),
            $unit
        );

        return new McpPromptDiscovery(
            $catalog->prompts(),
            new AgentPromptAvailability($catalog, $provider, $request, $nonce),
            str_repeat('k', 32),
            'prompt-live-fixture'
        );
    }

    private function principal(): AuthenticatedAgentPrincipal
    {
        return $this->authority('LISTEN')->resolve();
    }

    private function authority(string $requirement): AgentNoticeAuthority
    {
        return new AgentNoticeAuthority(
            $this->binding,
            $this->agentRepository,
            $this->permissionRepository,
            $this->clock,
            [PermissionName::fromString($requirement)]
        );
    }

    private function admission(
        string $subscriber = 'subscriber',
        ?AgentPromptPermissionCatalog $prompts = null
    ): AgentSubscriptionAdmission {
        $registry = new McpToolRegistry([new RecoverNoticesTool($this->queryBus)]);

        return new AgentSubscriptionAdmission(
            $subscriber,
            $this->authority('LISTEN'),
            $this->authority('SIGNAL'),
            $this->scope,
            new AgentSubscriptionFamilies(
                $registry,
                new AgentToolPermissionCatalog($registry, ['notices.recover' => ['TOOL']]),
                promptCatalog: $prompts
            )
        );
    }

    private function body(
        int|string $id = 7,
        string $subscriber = 'subscriber',
        ?McpSubscriptionStore $store = null,
        ?AgentSubscriptionAdmission $admission = null,
        ?NoticeSelection $selection = null,
        bool $prompts = false,
        bool $requestedPrompts = true
    ): McpEventStream {
        $request = new McpRequest($id, 'subscriptions/listen', ['notifications' => StrictJson::fromObject([
            'toolsListChanged'      => true,
            'promptsListChanged'    => $requestedPrompts,
            'resourcesListChanged'  => true,
            'resourceSubscriptions' => ['docs:requested-private']
        ])], McpRequestMetadata::fromObject(StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => StrictJson::fromObject([])
        ])));
        $subscriptions = new McpSubscriptions(
            $this->queryBus,
            $store ?? new DbalSubscriptionStore($this->tracking),
            $admission ?? $this->admission($subscriber),
            $selection ?? NoticeSelection::create(['project']),
            resources: false,
            lifetimeSeconds: 1,
            waitMicroseconds: 10000,
            prompts: $prompts
        );
        $responder = new McpResponder(new McpCapabilityRegistry(new McpServerInfo('fixture', '1'), []));

        return new McpEventStream($request, $responder, $this->diagnostics, $subscriptions->execution($request));
    }

    private function record(string $id, string $type = 'mcp.tools.list_changed', ?string $uri = null): void
    {
        $this->recorder->record(Notice::fromArray([
            'version'       => 1,
            'id'            => $id,
            'producer'      => 'producer',
            'scope'         => 'project',
            'topic'         => 'catalog',
            'type'          => $type,
            'occurred_at'   => null,
            'metadata'      => $uri === null ? ['safe/state' => 'private-metadata'] : ['mcp/uri' => $uri],
            'relationships' => [
                'subject'    => 'private-subject',
                'recipients' => ['private-recipient'],
                'author'     => null,
                'command'    => null,
                'event'      => null,
                'action'     => null
            ]
        ]));
    }

    private function save(Agent $agent): void
    {
        $this->agent = $agent;
        $this->agentRepository->restoreSnapshot([$agent]);
    }
}
