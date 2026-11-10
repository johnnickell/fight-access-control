<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Adapter\Mcp\Notice\SodiumNoticeContinuationProtector;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeReader;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRecoveryReader;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRepository;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeSchema;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeTransaction;
use Fight\Common\Adapter\Mcp\Tool\Notice\RecoverNoticesTool;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Mcp\McpJsonResponse;
use Fight\Common\Application\Mcp\Notice\CommandHandler\RecordNoticeHandler;
use Fight\Common\Application\Mcp\Notice\CommandHandler\RemoveNoticesHandler;
use Fight\Common\Application\Mcp\Notice\Data\NoticeRecoveryBatch;
use Fight\Common\Application\Mcp\Notice\Data\NoticeRecoveryWindow;
use Fight\Common\Application\Mcp\Notice\Exception\CommittedNoticeDispatchFailed;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeReadFailed;
use Fight\Common\Application\Mcp\Notice\QueryHandler\GetNoticeHandler;
use Fight\Common\Application\Mcp\Notice\QueryHandler\ListNoticesHandler;
use Fight\Common\Application\Mcp\Notice\QueryHandler\RecoverNoticesHandler;
use Fight\Common\Application\Mcp\Notice\QueryHandler\SummarizeNoticesHandler;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAccess;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAdmission;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecorder;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecovery;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecoveryIdentity;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecoveryReader;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRemovalAdmission;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRemover;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Domain\Mcp\Notice\Command\RecordNotice;
use Fight\Common\Domain\Mcp\Notice\Command\RemoveNotices;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeRemovalSelection;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use Fight\Common\Domain\Mcp\Notice\Query\GetNotice;
use Fight\Common\Domain\Mcp\Notice\Query\ListNotices;
use Fight\Common\Domain\Mcp\Notice\Query\RecoverNotices;
use Fight\Common\Domain\Mcp\Notice\Query\SummarizeNotices;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AgentNoticeReadAdmission::class)]
final class AgentNoticeReadAdmissionTest extends TestCase
{
    private Connection $connection;

    private RoutingCommandBus $commands;

    private RoutingQueryBus $queries;

    private InMemoryAgentRepository $agents;

    private InMemoryPermissionRepository $permissions;

    private Agent $agent;

    private Permission $read;

    private CurrentAgentPrincipalProvider $provider;

    private SignedAgentRequest $request;

    private InMemoryAgentRequestNonceConsumer $nonces;

    private AgentNoticeReadPolicy $policy;

    private NoticeAccess $access;

    private ?Throwable $policyFailure = null;

    private ?Throwable $dispatchFailure = null;

    private int $policyCalls = 0;

    private int $events = 0;

    private int $requestNumber = 0;

    private string $key;

    private int $recoveryBudget = 65536;

    protected function setUp(): void
    {
        $now = $this->now();
        $this->read = Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000401'),
            PermissionName::fromString('READ_NOTICES'),
            PermissionTier::ADMIN_SAFE,
            $now
        );
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000402'),
            AgentName::fromString('Reader'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000403'),
            'encrypted:fixture',
            $now
        )->grantPermission($this->read->getId(), $now);
        $this->agents = new InMemoryAgentRepository();
        $this->agents->add($this->agent);

        $this->permissions = new InMemoryPermissionRepository();
        $this->permissions->add($this->read);

        $this->key = random_bytes(32);
        $this->grant();
        $this->policy = $this->createStub(AgentNoticeReadPolicy::class);
        $this->policy->method('accessFor')->willReturnCallback(function (AuthenticatedAgentPrincipal $principal) {
            self::assertTrue($principal->getAgentId()->equals($this->agent->getId()));
            ++$this->policyCalls;
            if ($this->policyFailure !== null) {
                throw $this->policyFailure;
            }

            return $this->access;
        });
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalNoticeSchema()->install($this->connection);
        $transaction = new DbalNoticeTransaction($this->connection);
        $repository = new DbalNoticeRepository($transaction);
        $dispatcher = $this->createStub(EventDispatcher::class);
        $dispatcher->method('trigger')->willReturnCallback(function (Event $event): void {
            self::assertFalse($this->connection->isTransactionActive());
            ++$this->events;
            if ($this->dispatchFailure !== null) {
                throw $this->dispatchFailure;
            }
        });
        $record = new RecordNoticeHandler(new NoticeRecorder(
            $repository,
            $transaction,
            $this->createStub(NoticeAdmission::class),
            $dispatcher
        ));
        $remove = new RemoveNoticesHandler(new NoticeRemover(
            $repository,
            $transaction,
            $this->createStub(NoticeRemovalAdmission::class)
        ));
        $router = new InMemoryCommandRouter();
        $router->registerHandlers([
            $record::commandRegistration() => $record,
            $remove::commandRegistration() => $remove
        ]);
        $this->commands = new RoutingCommandBus($router);
        $this->newRequest();
    }

    public function test_direct_summary_page_and_detail_protect_every_metadata_dimension_and_relationship(): void
    {
        $this->record('one');
        $this->record('two');
        $this->record('secret', 'hidden', 'secret-topic');
        $this->grant(filters: ['id' => 'one']);
        $selection = NoticeSelection::create(['project', 'hidden']);
        $summary = $this->queries->fetch(new SummarizeNotices($selection))->toArray();
        self::assertSame(1, $summary['total']);
        self::assertSame([['value' => 'updates', 'count' => 1]], $summary['topics']);
        self::assertSame(['named' => 1, 'broadcast' => 0, 'unknown' => 0], $summary['audience']);
        $page = $this->queries->fetch(new ListNotices($selection, 1, 1))->toArray();
        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['notices']);
        $detail = $this->queries->fetch(new GetNotice('project', 'producer', 'one'))->toArray();
        self::assertSame($page['notices'][0], $detail);
        self::assertSame('subject', $detail['relationships']['subject']);
        foreach ([['project', 'two'], ['hidden', 'secret'], ['project', 'absent']] as [$scope, $id]) {
            self::assertNull($this->queries->fetch(new GetNotice($scope, 'producer', $id)));
        }

        self::assertStringNotContainsString('secret', json_encode([$summary, $page, $detail], JSON_THROW_ON_ERROR));
        $this->access = NoticeAccess::create([]);
        self::assertSame(0, $this->queries->fetch(new SummarizeNotices($selection))->toArray()['total']);
        self::assertNull($this->queries->fetch(new GetNotice('project', 'producer', 'one')));
        self::assertSame(1, $this->nonces->consumptionCalls());
        self::assertSame(3, $this->events);
    }

    public function test_direct_detail_requires_no_listing_and_disappeared_detail_is_concealed(): void
    {
        $this->record('one');
        self::assertNotNull($this->queries->fetch(new GetNotice('project', 'producer', 'one')));
        $this->remove('one', 1);
        self::assertNull($this->queries->fetch(new GetNotice('project', 'producer', 'one')));
    }

    public function test_all_permissions_are_conjunctive_and_tool_availability_cannot_grant_read_scope(): void
    {
        $admission = $this->admission(['READ_NOTICES', 'OTHER_PERMISSION']);
        try {
            $admission->currentAccess(NoticeSelection::create(['project']));
            self::fail('Missing conjunct must deny');
        } catch (PermissionDenied) {
            self::assertSame(0, $this->policyCalls);
        }

        $this->record('one');
        $this->access = NoticeAccess::create([], [], new NoticeRecoveryIdentity('reader', null));
        $result = $this->tool()->invoke('notices.recover', ['scopes' => ['project', 'hidden']])->toArray();
        self::assertSame([], $result['structuredContent']->get('notices'));
        self::assertSame('uncertain', $result['structuredContent']->get('status'));
        self::assertSame(1, $this->policyCalls);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    public function test_request_snapshot_survives_change_but_next_request_denies_without_policy_work(): void
    {
        $this->record('one');
        $this->recover();
        $before = $this->agent;
        $this->agent = $this->agent->revokePermission($this->read->getId(), $this->now());
        $this->agents->replacePermissionAssignments($before, $this->agent);
        self::assertSame('complete', $this->recover()->toArray()['status']);
        $calls = $this->policyCalls;
        $this->newRequest();
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
        self::assertSame($calls, $this->policyCalls);
    }

    public function test_policy_and_storage_faults_never_become_empty_success(): void
    {
        $this->policyFailure = new RuntimeException('private-policy');
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
        $this->policyFailure = null;
        $this->record('one');
        $this->connection->executeStatement('UPDATE fight_notices SET payload = ?', ['broken-json']);
        $queries = [
            new SummarizeNotices(NoticeSelection::create(['project'])),
            new GetNotice('project', 'producer', 'one')
        ];
        foreach ($queries as $query) {
            $this->assertReadFailure(fn(): mixed => $this->queries->fetch($query));
        }

        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
        $this->connection->executeStatement('DROP TABLE fight_notices');
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
    }

    public function test_committed_publication_failure_remains_recoverable_without_live_runtime(): void
    {
        $this->dispatchFailure = new RuntimeException('private-publication');
        try {
            $this->record('one');
            self::fail('Postcommit failure expected');
        } catch (CommittedNoticeDispatchFailed) {
            self::assertSame(1, $this->events);
        }

        $before = $this->connection->fetchAllAssociative('SELECT * FROM fight_notices');
        $direct = $this->recover()->toArray();
        $tool = $this->tool()->invoke('notices.recover', ['scopes' => ['project']])->toArray();
        self::assertSame('complete', $direct['status']);
        self::assertSame($direct['notices'], json_decode(
            $tool['structuredContent']->toString(),
            true,
            flags: JSON_THROW_ON_ERROR
        )['notices']);
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM fight_notices'));
        self::assertSame(1, $this->events);
        self::assertSame(1, $this->nonces->consumptionCalls());
    }

    public function test_bounded_recovery_never_advances_past_omitted_matches_and_concurrent_append_is_seen(): void
    {
        foreach (['one', 'two', 'three'] as $id) {
            $this->record($id);
        }

        $first = $this->recover(batch: 1, scan: 2)->toArray();
        self::assertSame('incomplete', $first['status']);
        self::assertSame('one', $first['notices'][0]['id']);
        $second = $this->recover($first['continuation'], 1, 2)->toArray();
        self::assertSame('two', $second['notices'][0]['id']);
        $this->record('four');
        $third = $this->recover($second['continuation'], 1, 2)->toArray();
        self::assertSame('three', $third['notices'][0]['id']);
        $fourth = $this->recover($third['continuation'], 1, 2)->toArray();
        self::assertSame('four', $fourth['notices'][0]['id']);
        self::assertSame('complete', $fourth['status']);
        self::assertSame([], $this->recover($fourth['continuation'], 1, 2)->toArray()['notices']);
    }

    public function test_inflight_append_after_coherent_window_is_observed_by_the_next_poll(): void
    {
        $this->record('one');
        $actualReader = new DbalNoticeRecoveryReader($this->connection);
        $reader = $this->createStub(NoticeRecoveryReader::class);
        $append = true;
        $reader->method('readWindow')->willReturnCallback(function (
            $scopes,
            $position,
            $limit
        ) use (
            $actualReader,
            &$append
        ): NoticeRecoveryWindow {
            $window = $actualReader->readWindow($scopes, $position, $limit);
            if ($append) {
                $append = false;
                $this->record('two');
            }

            return $window;
        });
        $handler = new RecoverNoticesHandler($this->admission(), new NoticeRecovery(
            $reader,
            new SodiumNoticeContinuationProtector(['fixture1' => $this->key], 'fixture1')
        ));
        $router = new InMemoryQueryRouter();
        $router->registerHandlers([$handler::queryRegistration() => $handler]);

        $this->queries = new RoutingQueryBus($router);
        $first = $this->recover()->toArray();
        self::assertSame(['one'], array_column($first['notices'], 'id'));
        self::assertSame('complete', $first['status']);
        $second = $this->recover($first['continuation'])->toArray();
        self::assertSame(['two'], array_column($second['notices'], 'id'));
        self::assertSame('complete', $second['status']);
        self::assertSame(2, $this->events);
    }

    public function test_scan_budget_counts_filtered_rows_without_skipping_newly_eligible_older_data(): void
    {
        $this->record('one');
        $this->record('two');
        $this->grant(filters: ['id' => 'two']);
        $first = $this->recover(scan: 1)->toArray();
        self::assertSame([], $first['notices']);
        self::assertSame('incomplete', $first['status']);
        self::assertSame('two', $this->recover($first['continuation'], scan: 1)->toArray()['notices'][0]['id']);
        $this->grant('revision-2');
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($first['continuation'], scan: 1));
        self::assertSame('one', $this->recover()->toArray()['notices'][0]['id']);
    }

    public function test_history_loss_removal_between_batches_and_last_deletion_cannot_claim_complete(): void
    {
        $this->record('one');
        $this->record('two');
        $first = $this->recover(batch: 1)->toArray();
        $this->remove('two', 2);
        $next = $this->recover($first['continuation'], 1)->toArray();
        self::assertSame([], $next['notices']);
        self::assertSame('incomplete', $next['status']);
        $this->remove('one', 1);
        self::assertSame('incomplete', $this->recover()->toArray()['status']);
        $this->grant(null);
        self::assertSame('uncertain', $this->recover()->toArray()['status']);
        $this->grant('revision-1', evidence: false);
        self::assertSame('uncertain', $this->recover()->toArray()['status']);
    }

    public function test_tokens_reject_tampering_cross_caller_changed_selection_and_retired_namespace(): void
    {
        $this->record('one');
        $token = $this->recover()->toArray()['continuation'];
        self::assertStringNotContainsString('reader', $token);
        self::assertStringNotContainsString('project', $token);
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover('x'.$token));
        $this->grant(caller: 'different');
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($token));
        $this->grant('changed');
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($token));
        $this->grant();
        $this->assertReadFailure(fn(): mixed => $this->queries->fetch(new RecoverNotices(
            NoticeSelection::create(['hidden']),
            $token
        )));
        $this->connection->executeStatement('UPDATE fight_notice_state SET namespace = ?', [str_repeat('0', 32)]);
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($token));
    }

    public function test_retired_key_and_changed_serving_bounds_require_restart(): void
    {
        $this->record('one');
        $token = $this->recover()->toArray()['continuation'];
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($token, batch: 1));
        $this->key = random_bytes(32);
        $this->newRequest();
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($token));
        self::assertSame('complete', $this->recover()->toArray()['status']);
    }

    public function test_summary_overflow_is_a_whole_read_failure_not_partial_statistics(): void
    {
        for ($index = 0; $index < 129; ++$index) {
            $this->record('notice-'.$index, topic: 'topic-'.$index);
        }

        $this->assertReadFailure(fn(): mixed => $this->queries->fetch(new SummarizeNotices(
            NoticeSelection::create(['project'])
        )));
        $this->grant(filters: ['id' => 'notice-0']);
        self::assertSame(1, $this->queries->fetch(new SummarizeNotices(
            NoticeSelection::create(['project'])
        ))->toArray()['total']);
    }

    public function test_missing_trusted_identity_and_operational_scan_failure_are_errors_not_uncertain_success(): void
    {
        $this->access = NoticeAccess::create(['project']);
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
        $this->grant();
        $this->connection->executeStatement('DROP TABLE fight_notice_state');
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
    }

    public function test_encoded_tool_budget_failure_does_not_advance_or_mutate_recovery(): void
    {
        $this->record('one');
        $token = $this->recover()->toArray()['continuation'];
        $this->record('two', text: str_repeat('"\\', 60));
        $failure = null;
        try {
            $result = $this->tool(2048)->invoke('notices.recover', [
                'scopes' => ['project'], 'continuation' => $token
            ]);
            McpJsonResponse::success('request-id', $result->withMetadata([
                'io.modelcontextprotocol/serverInfo' => ['name' => 'notice-fixture', 'version' => '1']
            ]));
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertNotNull($failure, 'Encoded output must fail rather than truncate');
        self::assertStringNotContainsString('subject', $failure->getMessage());

        self::assertSame('two', $this->recover($token)->toArray()['notices'][0]['id']);
        self::assertSame(2, $this->events);
        $result = $this->tool()->invoke('notices.recover', ['scopes' => ['project'], 'continuation' => $token]);
        $response = McpJsonResponse::success('request-id', $result->withMetadata([
            'io.modelcontextprotocol/serverInfo' => ['name' => 'notice-fixture', 'version' => '1']
        ]));
        self::assertGreaterThan(2048, strlen($response->toJson()));
        self::assertStringContainsString('structuredContent', $response->toJson());
        self::assertStringContainsString('content', $response->toJson());
    }

    public function test_recovery_result_overflow_preserves_prior_token_for_a_smaller_retry(): void
    {
        $this->record('one');
        $this->record('two');
        $this->record('three');
        $this->recoveryBudget = 2048;
        $this->newRequest();
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
        $first = $this->recover(batch: 1)->toArray();
        self::assertSame('one', $first['notices'][0]['id']);
        self::assertSame('incomplete', $first['status']);
        self::assertSame('two', $this->recover($first['continuation'], 1)->toArray()['notices'][0]['id']);
    }

    public function test_empty_and_malformed_permission_configuration_reject_before_authentication(): void
    {
        foreach ([[], ['READ_NOTICES'], [PermissionName::fromString('READ_NOTICES'), null]] as $requirements) {
            try {
                new AgentNoticeReadAdmission($this->provider, $this->request, 'invalid', $requirements, $this->policy);
                self::fail('Malformed requirements must reject');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->policyCalls);
            }
        }
    }

    public function test_missing_agent_fails_fresh_authentication_and_does_not_reach_policy(): void
    {
        $this->recover();
        $this->agents->restoreSnapshot([]);
        $calls = $this->policyCalls;
        $this->newRequest();
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover());
        self::assertSame($calls, $this->policyCalls);
    }

    public function test_changed_grants_and_revoke_restore_access_epoch_require_restart(): void
    {
        $this->record('one');
        $token = $this->recover()->toArray()['continuation'];
        $this->access = NoticeAccess::create([], [], new NoticeRecoveryIdentity('reader', 'revision-1', true));
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($token));
        $this->grant('revision-after-restoration');
        $this->assertReadFailure(fn(): NoticeRecoveryBatch => $this->recover($token));
        self::assertSame('one', $this->recover()->toArray()['notices'][0]['id']);
    }

    public function test_actual_query_page_scan_and_tool_input_bounds_reject_instead_of_truncating(): void
    {
        $constructors = [
            static fn(): ListNotices => new ListNotices(NoticeSelection::create(['project']), 1, 33),
            static fn(): RecoverNotices => new RecoverNotices(NoticeSelection::create(['project']), batchSize: 33),
            static fn(): RecoverNotices => new RecoverNotices(NoticeSelection::create(['project']), scanLimit: 101),
            static fn(): RecoverNotices => new RecoverNotices(
                NoticeSelection::create(['project']),
                str_repeat('x', 2049)
            ),
            static fn(): NoticeSelection => NoticeSelection::create(array_fill(0, 17, 'project'))
        ];
        foreach ($constructors as $construct) {
            $caught = null;
            try {
                $construct();
            } catch (Throwable $failure) {
                $caught = $failure;
            }

            self::assertNotNull($caught);
        }

        $calls = $this->policyCalls;
        $result = $this->tool()->invoke('notices.recover', ['scopes' => ['project'], 'scan_limit' => 101]);
        self::assertTrue($result->toArray()['isError']);
        self::assertSame($calls, $this->policyCalls);
    }

    private function newRequest(): void
    {
        $unit = new InMemoryUnitOfWork();
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->agents, $unit);
        $this->request = new SignedAgentRequest(
            'POST',
            'notice.example',
            '/query',
            '',
            $this->now(),
            'nonce-'.++$this->requestNumber,
            $this->agent->getCredentialId(),
            'HMAC-SHA256',
            'signature',
            null,
            ''
        );
        $this->provider = new CurrentAgentPrincipalProvider(
            $this->agents,
            $this->permissions,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($this->request, 'fixture'),
            new FixedClock($this->now()),
            $this->nonces,
            $unit
        );
        $admission = $this->admission();
        $reader = new DbalNoticeReader($this->connection);
        $handlers = [
            new SummarizeNoticesHandler($admission, $reader),
            new ListNoticesHandler($admission, $reader),
            new GetNoticeHandler($admission, $reader),
            new RecoverNoticesHandler($admission, new NoticeRecovery(
                new DbalNoticeRecoveryReader($this->connection),
                new SodiumNoticeContinuationProtector(['fixture1' => $this->key], 'fixture1'),
                maxResultBytes: $this->recoveryBudget
            ))
        ];
        $map = [];
        foreach ($handlers as $handler) {
            $map[$handler::queryRegistration()] = $handler;
        }

        $router = new InMemoryQueryRouter();
        $router->registerHandlers($map);

        $this->queries = new RoutingQueryBus($router);
    }

    /** @param list<string> $requirements */
    private function admission(array $requirements = ['READ_NOTICES']): AgentNoticeReadAdmission
    {
        return new AgentNoticeReadAdmission(
            $this->provider,
            $this->request,
            'notice-read',
            array_map(PermissionName::fromString(...), $requirements),
            $this->policy
        );
    }

    /** @param array<string, mixed> $filters */
    private function grant(
        ?string $revision = 'revision-1',
        array $filters = [],
        bool $evidence = true,
        string $caller = 'reader'
    ): void {
        $this->access = NoticeAccess::create(
            ['project'],
            $filters,
            new NoticeRecoveryIdentity($caller, $revision, $evidence)
        );
    }

    private function recover(?string $token = null, int $batch = 32, int $scan = 100): NoticeRecoveryBatch
    {
        return $this->queries->fetch(new RecoverNotices(NoticeSelection::create(['project']), $token, $batch, $scan));
    }

    private function tool(int $budget = 1048576): McpToolInvoker
    {
        $registry = new McpToolRegistry([new RecoverNoticesTool($this->queries, $budget)]);
        $catalog = new AgentToolPermissionCatalog($registry, ['notices.recover' => ['READ_NOTICES']]);

        return new McpToolInvoker($registry, new AgentToolAvailability(
            $catalog,
            $this->provider,
            $this->request,
            'notice-tool'
        ));
    }

    private function record(
        string $id,
        string $scope = 'project',
        string $topic = 'updates',
        string $text = 'ready'
    ): void {
        $this->commands->execute(new RecordNotice(Notice::fromArray([
            'version'       => 1,
            'id'            => $id,
            'producer'      => 'producer',
            'scope'         => $scope,
            'topic'         => $topic,
            'type'          => 'changed',
            'occurred_at'   => null,
            'metadata'      => ['safe/state' => $text],
            'relationships' => [
                'subject'    => 'subject',
                'recipients' => ['recipient'],
                'author'     => 'author',
                'command'    => 'command',
                'event'      => 'event',
                'action'     => 'action'
            ]
        ])));
    }

    private function remove(string $id, int $position): void
    {
        $this->commands->execute(new RemoveNotices(NoticeRemovalSelection::fromArray([
            'version'   => 1,
            'namespace' => $this->connection->fetchOne('SELECT namespace FROM fight_notice_state'),
            'targets'   => [['scope' => 'project', 'producer' => 'producer', 'id' => $id, 'position' => $position]]
        ])));
    }

    private function assertReadFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('A read fault must not fabricate success');
        } catch (NoticeReadFailed $noticeReadFailed) {
            self::assertStringNotContainsString('private-', $noticeReadFailed->getMessage());
            self::assertNotNull($noticeReadFailed->getPrevious());
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10T12:00:00+00:00');
    }
}
