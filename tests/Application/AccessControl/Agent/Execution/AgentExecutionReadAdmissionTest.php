<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Execution;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Execution\AgentExecutionReadAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Detail\Dbal\DbalProtectedContentRepository;
use Fight\Common\Adapter\Mcp\Persistence\Execution\Dbal\DbalObservationSchema;
use Fight\Common\Adapter\Mcp\Persistence\Execution\Dbal\DbalRecordingTransaction;
use Fight\Common\Adapter\Mcp\Persistence\Observation\Dbal\DbalActivityReader;
use Fight\Common\Adapter\Mcp\Persistence\Observation\Dbal\DbalObservationRepository;
use Fight\Common\Adapter\Mcp\Persistence\Phase\Dbal\DbalPhaseReader;
use Fight\Common\Adapter\Mcp\Persistence\Phase\Dbal\DbalPhaseRepository;
use Fight\Common\Adapter\Mcp\Persistence\Session\Dbal\DbalExecutionReader;
use Fight\Common\Adapter\Mcp\Persistence\Session\Dbal\DbalSessionRepository;
use Fight\Common\Adapter\Mcp\Tool\Observation\ListPhaseActivityTool;
use Fight\Common\Adapter\Mcp\Tool\Phase\ListExecutionPhasesTool;
use Fight\Common\Adapter\Mcp\Tool\Session\GetExecutionTool;
use Fight\Common\Adapter\Mcp\Tool\Session\ListExecutionsTool;
use Fight\Common\Adapter\Mcp\Tool\Session\SummarizeExecutionsTool;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Capability\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Observation\QueryHandler\ListPhaseActivityHandler;
use Fight\Common\Application\Mcp\Observation\Service\ObservationAdmission;
use Fight\Common\Application\Mcp\Observation\Service\ObservationRecorder;
use Fight\Common\Application\Mcp\Phase\QueryHandler\ListExecutionPhasesHandler;
use Fight\Common\Application\Mcp\Protocol\Exception\McpProtocolException;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Session\Exception\ExecutionReadFailed;
use Fight\Common\Application\Mcp\Session\QueryHandler\GetExecutionHandler;
use Fight\Common\Application\Mcp\Session\QueryHandler\ListExecutionsHandler;
use Fight\Common\Application\Mcp\Session\QueryHandler\SummarizeExecutionsHandler;
use Fight\Common\Application\Mcp\Session\Service\ExecutionAccess;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Mcp\Activity\ActivityExecution;
use Fight\Common\Domain\Mcp\Activity\ActivityResult;
use Fight\Common\Domain\Mcp\Activity\ActivityTarget;
use Fight\Common\Domain\Mcp\Activity\ActivityTiming;
use Fight\Common\Domain\Mcp\Activity\PhaseActivity;
use Fight\Common\Domain\Mcp\Detail\CaptureCompleteness;
use Fight\Common\Domain\Mcp\Detail\DetailCapture;
use Fight\Common\Domain\Mcp\Detail\DetailKind;
use Fight\Common\Domain\Mcp\Detail\ProtectedContent;
use Fight\Common\Domain\Mcp\Observation\Observation;
use Fight\Common\Domain\Mcp\Observation\ObservationId;
use Fight\Common\Domain\Mcp\Observation\ObservationOccurrence;
use Fight\Common\Domain\Mcp\Observation\Query\ListPhaseActivity;
use Fight\Common\Domain\Mcp\Phase\PhaseReference;
use Fight\Common\Domain\Mcp\Phase\Query\ListExecutionPhases;
use Fight\Common\Domain\Mcp\Session\ExecutionSelection;
use Fight\Common\Domain\Mcp\Session\Query\GetExecution;
use Fight\Common\Domain\Mcp\Session\Query\ListExecutions;
use Fight\Common\Domain\Mcp\Session\Query\SummarizeExecutions;
use Fight\Common\Domain\Mcp\Session\SessionReference;
use Fight\Common\Domain\Mcp\Usage\UsageCompleteness;
use Fight\Common\Domain\Mcp\Usage\UsageMeasurement;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Detail\DetailSqlTrace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentExecutionReadAdmission::class)]
final class AgentExecutionReadAdmissionTest extends TestCase
{
    private ProfileToolEnvironment $env;

    private Connection $db;

    private DetailSqlTrace $trace;

    private ObservationRecorder $recorder;

    private Observation $visible;

    private Observation $hidden;

    private Observation $detail;

    private ControlledExecutionScope $scope;

    private RoutingQueryBus $queryBus;

    private McpToolInvoker $invoker;

    private McpResponder $responder;

    protected function setUp(): void
    {
        $this->env = new ProfileToolEnvironment();
        $this->grant(['USE_EXECUTION_TOOLS', 'READ_TIMELINE', 'READ_REFERENCE']);
        $this->trace = new DetailSqlTrace();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->trace)]);

        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        new DbalObservationSchema()->install($this->db);
        $transaction = new DbalRecordingTransaction($this->db);
        $this->recorder = new ObservationRecorder(
            $transaction,
            new DbalSessionRepository($transaction),
            new DbalPhaseRepository($transaction),
            new DbalObservationRepository($transaction),
            new class implements ObservationAdmission {
                public function admit(Observation $observation): void
                {
                }
            },
            new SimpleEventDispatcher(),
            new DbalProtectedContentRepository($transaction)
        );
        $this->visible = $this->seed('project', 'session', 'phase', 'visible', 'visible-model', '3');
        $this->hidden = $this->seed('project', 'session', 'phase', 'hidden', 'HIDDEN-MODEL', '999');
        $this->seed('project', 'session', 'other-phase', 'HIDDEN-PHASE', 'OTHER-MODEL', '900');
        $this->seed('project', 'other-session', 'other', 'HIDDEN-SESSION', 'OTHER-MODEL', '800');
        $this->seed('foreign', 'session', 'phase', 'FOREIGN', 'FOREIGN-MODEL', '700');
        $this->detail = $this->seedDetail($this->visible);
        $this->seedDetail($this->hidden);
        $this->scope = new ControlledExecutionScope($this->access());
        $this->compose();
        $this->trace->queries = [];
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function test_supplier_summary_and_derived_inspection_exclude_ungranted_activity(): void
    {
        $activity = $this->queryBus->fetch(new ListPhaseActivity('project', 'session', 'phase'))->toArray();
        self::assertSame(1, $activity['total']);
        self::assertSame($this->visible->id()->toString(), $activity['activities'][0]['observation_id']);
        $summary = $this->queryBus->fetch(new SummarizeExecutions($this->selection()))->toArray();
        self::assertSame(1, $summary['activities'], 'Common v1.5.0 counted both same-phase activities here.');
        self::assertSame(1, $summary['executions']);
        self::assertSame('3', $summary['usage'][0]['measured']);
        self::assertSame('unknown', $summary['capture_completeness']);
        $get = $this->queryBus->fetch(new GetExecution('project', 'session'))->toArray();
        self::assertSame($summary, $get['summary']);
        $phases = $this->queryBus->fetch(new ListExecutionPhases('project', 'session'))->toArray();
        self::assertSame(1, $phases['total']);
        self::assertStringNotContainsString('HIDDEN-MODEL', json_encode($phases));
        self::assertStringContainsString('visible-model', json_encode($phases));
        self::assertSame(0, $this->trace->bodyReads());
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function grants(): iterable
    {
        foreach ([false, true] as $read) {
            foreach ([false, true] as $reference) {
                foreach ([false, true] as $scope) {
                    yield implode('-', array_map(intval(...), [$read, $reference, $scope]))
                        => [$read, $reference, $scope];
                }
            }
        }
    }

    #[DataProvider('grants')]
    public function test_real_tools_and_direct_queries_use_independent_scope(
        bool $read,
        bool $reference,
        bool $scope
    ): void {
        $permissions = ['USE_EXECUTION_TOOLS'];
        if ($read) {
            $permissions[] = 'READ_TIMELINE';
        }

        if ($reference) {
            $permissions[] = 'READ_REFERENCE';
        }

        $this->grant($permissions);
        $this->scope->access = $scope ? $this->access() : ExecutionAccess::create([]);
        $this->freshRequest();
        $state = $this->state();
        $events = $this->env->storage->eventDispatcher->events();
        $agent = $this->env->storage->agentRepository->all();
        foreach ($this->queries() as $name => $query) {
            $tool = $this->invoker->invoke($name, $this->arguments($name))->toArray();
            self::assertSame('complete', $tool['resultType']);
            self::assertArrayNotHasKey('isError', $tool);
            $direct = $this->queryBus->dispatch(QueryMessage::arrayDeserialize(
                QueryMessage::create($query)->arraySerialize()
            ));
            $wire = json_decode(json_encode($tool['structuredContent'], JSON_THROW_ON_ERROR), true);
            self::assertSame($direct?->toArray(), $wire);
            self::assertStringNotContainsString('PROTECTED-BODY-SENTINEL', json_encode($tool));
            self::assertStringNotContainsString(base64_encode('PROTECTED-BODY-SENTINEL'), json_encode($tool));
            $concealed = ['HIDDEN-MODEL', 'HIDDEN-PHASE', 'HIDDEN-SESSION', 'FOREIGN', $this->hidden->id()->toString()];
            foreach ($concealed as $secret) {
                self::assertStringNotContainsString($secret, json_encode($tool));
            }

            if (!$read || !$scope) {
                if ($name === 'execution.summarize') {
                    self::assertSame(0, $wire['executions']);
                    self::assertSame(0, $wire['activities']);
                } elseif ($name === 'execution.list') {
                    self::assertSame(0, $wire['total']);
                    self::assertSame([], $wire['executions']);
                } else {
                    self::assertNull($wire);
                }
            } elseif ($name === 'execution.activity') {
                $row = $wire['activities'][0];
                self::assertSame([
                    'producer_id', 'observation_id', 'occurred_at', 'recorded_at', 'agent_id', 'activity', 'details'
                ], array_keys($row));
                self::assertSame($this->visible->activity()->toArray(), $row['activity']);
                self::assertCount($reference ? 1 : 0, $row['details']);
                if ($reference) {
                    self::assertSame($this->detail->id()->toString(), $row['details'][0]['observation_id']);
                }
            }
        }

        self::assertSame($read ? 10 : 0, $this->scope->calls);
        self::assertSame(1, $this->env->nonces->consumptionCalls());
        self::assertSame($state, $this->state());
        self::assertSame($events, $this->env->storage->eventDispatcher->events());
        self::assertSame($agent, $this->env->storage->agentRepository->all());
        self::assertSame(0, $this->env->storage->agentRepository->nameWrites);
        self::assertSame(0, $this->trace->bodyReads());
        self::assertFalse($this->db->isTransactionActive());
    }

    public function test_empty_activity_grants_conceal_all_activity_contributions_without_hiding_state(): void
    {
        $this->scope->access = ExecutionAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => []],
            ['project' => null]
        );
        $summary = $this->queryBus->fetch(new SummarizeExecutions($this->selection()))->toArray();
        self::assertSame(1, $summary['executions']);
        self::assertSame(0, $summary['activities']);
        self::assertSame([], $summary['usage']);
        $activity = $this->queryBus->fetch(new ListPhaseActivity('project', 'session', 'phase'))->toArray();
        self::assertSame(0, $activity['total']);
        self::assertSame([], $activity['activities']);
        $phase = $this->queryBus->fetch(new ListExecutionPhases('project', 'session'))->toArray();
        self::assertSame(1, $phase['total']);
        self::assertStringNotContainsString('visible-model', json_encode($phase));
    }

    public function test_sql_scope_excludes_hidden_activity_before_decoding_and_aggregating(): void
    {
        $this->db->executeStatement(
            'UPDATE fight_execution_observations SET version = 999 WHERE observation_id = ?',
            [$this->hidden->id()->toString()]
        );
        $summary = $this->queryBus->fetch(new SummarizeExecutions($this->selection()))->toArray();
        self::assertSame(1, $summary['activities']);
        self::assertSame('3', $summary['usage'][0]['measured']);
        $this->scope->access = ExecutionAccess::create(['project' => ['session']]);
        $this->expectException(ExecutionReadFailed::class);
        $this->queryBus->fetch(new SummarizeExecutions($this->selection()));
    }

    public function test_malformed_authority_fault_is_not_a_direct_query_data_denial(): void
    {
        $agent = $this->env->storage->agentRepository->all()[0];
        $this->env->storage->agentRepository->restoreSnapshot([
            $agent->grantPermission(PermissionId::generate(), $this->env->now())
        ]);
        $this->freshRequest();
        $this->expectException(CurrentAgentPrincipalResolutionRejectedException::class);
        try {
            $this->queryBus->fetch(new SummarizeExecutions($this->selection()));
        } finally {
            self::assertSame(0, $this->scope->calls);
            self::assertSame([], $this->trace->queries);
        }
    }

    public function test_reference_permission_cannot_overwrite_consumer_denial_or_foreign_reference_grant(): void
    {
        $this->scope->access = ExecutionAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => ['visible/'.$this->visible->id()]],
            ['project' => ['hidden/'.$this->hidden->id()]]
        );
        $page = $this->queryBus->fetch(new ListPhaseActivity('project', 'session', 'phase'))->toArray();
        self::assertSame([], $page['activities'][0]['details']);
        self::assertSame(1, $page['total']);
    }

    public function test_forged_selection_and_query_metadata_never_supply_authority(): void
    {
        $forged = [
            ['foreign', 'session', 'phase'],
            ['project', 'other-session', 'other'],
            ['project', 'session', 'other-phase']
        ];
        foreach ($forged as [$project, $session, $phase]) {
            $query = new ListPhaseActivity($project, $session, $phase);
            self::assertNull($this->queryBus->fetch($query));
            $result = $this->invoker->invoke('execution.activity', [
                'project_id' => $project, 'session_id' => $session, 'phase_id' => $phase
            ])->toArray();
            self::assertNull($result['structuredContent']);
        }

        $this->scope->access = ExecutionAccess::create([]);
        $result = $this->queryBus->dispatch(QueryMessage::create(new SummarizeExecutions(
            ExecutionSelection::create(['project', 'foreign'])
        ))->withMeta(Meta::create(['permissions' => ['READ_TIMELINE'], 'role' => 'administrator'])));
        self::assertSame(0, $result->toArray()['executions']);
        self::assertSame(0, $this->trace->bodyReads());
    }

    public function test_later_pages_recheck_scope_and_permissions_but_resolved_request_keeps_principal(): void
    {
        $this->scope->access = ExecutionAccess::create(['project' => ['session', 'other-session']]);
        $first = $this->queryBus->fetch(new ListExecutions($this->selection(), 1, 1))->toArray();
        self::assertSame(2, $first['total']);
        self::assertCount(1, $first['executions']);
        $this->scope->access = $this->access();
        $this->freshRequest();
        $later = $this->queryBus->fetch(new ListExecutions($this->selection(), 2, 1))->toArray();
        self::assertSame(1, $later['total']);
        self::assertSame([], $later['executions']);
        $this->grant(['USE_EXECUTION_TOOLS']);
        $current = $this->queryBus->fetch(new SummarizeExecutions($this->selection()))->toArray();
        self::assertSame(1, $current['activities']);
        $this->freshRequest();
        $fresh = $this->queryBus->fetch(new SummarizeExecutions($this->selection()))->toArray();
        self::assertSame(0, $fresh['activities']);
        self::assertSame(3, $this->env->nonces->consumptionCalls());
    }

    public function test_activity_later_page_has_current_total_and_unknown_partial_facts_stay_unknown(): void
    {
        $this->scope->access = ExecutionAccess::create(['project' => ['session']], ['project' => ['phase']]);
        $first = $this->queryBus->fetch(new ListPhaseActivity('project', 'session', 'phase', 1, 1))->toArray();
        self::assertSame(2, $first['total']);
        $this->scope->access = $this->access();
        $this->freshRequest();
        $later = $this->queryBus->fetch(new ListPhaseActivity('project', 'session', 'phase', 2, 1))->toArray();
        self::assertSame(1, $later['total']);
        self::assertSame([], $later['activities']);
        $row = $this->queryBus->fetch(new ListPhaseActivity('project', 'session', 'phase'))->toArray()['activities'][0];
        self::assertSame([
            'target' => null,
            'timing' => ['started_at' => '2026-10-01T12:00:00Z', 'ended_at' => null, 'elapsed' => null],
            'result' => ['kind' => 'unknown', 'failure_kind' => null, 'exit_code' => null]
        ], $row['activity']['execution']);
        self::assertSame('partial', $row['activity']['usage'][0]['completeness']);
    }

    public function test_coarse_gate_denies_before_policy_and_queries_but_direct_query_has_its_own_admission(): void
    {
        $this->grant(['READ_TIMELINE', 'READ_REFERENCE']);
        $this->freshRequest();
        try {
            $this->invoker->invoke('execution.summarize', $this->arguments('execution.summarize'));
            self::fail('Tool must be unavailable.');
        } catch (McpProtocolException $mcpProtocolException) {
            self::assertSame(
                'Unknown or unavailable tool.',
                $mcpProtocolException->protocolError()->toArray()['message']
            );
        }

        self::assertSame(0, $this->scope->calls);
        self::assertSame([], $this->trace->queries);
        $result = $this->queryBus->fetch(new SummarizeExecutions($this->selection()))->toArray();
        self::assertSame(1, $result['activities']);
        self::assertSame(1, $this->env->nonces->consumptionCalls());
    }

    public function test_policy_runs_before_storage_and_fault_remains_safe_not_empty_success(): void
    {
        $this->scope->beforeReturn = function (): void {
            self::assertSame([], $this->trace->queries);
        };
        $this->queryBus->fetch(new SummarizeExecutions($this->selection()));
        $this->trace->queries = [];
        $this->scope->fail = true;
        $this->assertSafeFailure();
        self::assertSame([], $this->trace->queries);
    }

    public function test_storage_failure_preserves_direct_failure_and_safe_protocol_boundary(): void
    {
        $this->db->executeStatement('DROP TABLE fight_execution_observations');
        try {
            $this->queryBus->fetch(new SummarizeExecutions($this->selection()));
            self::fail('Storage failure must not become empty success.');
        } catch (ExecutionReadFailed $executionReadFailed) {
            self::assertNotNull($executionReadFailed->getPrevious());
        }

        $this->assertSafeFailure();
    }

    public function test_invalid_bounds_and_supplied_grants_cannot_open_data(): void
    {
        $invalid = [
            ['page' => 0], ['per_page' => 101], ['page' => 1000002],
            ['direction' => 'invalid'], ['permissions' => ['READ_TIMELINE']]
        ];
        foreach ($invalid as $change) {
            try {
                $result = $this->invoker->invoke(
                    'execution.list',
                    $change + $this->arguments('execution.list')
                )->toArray();
                self::assertTrue($result['isError']);
            } catch (McpProtocolException) {
                self::assertSame(0, $this->scope->calls);
            }
        }

        self::assertSame([], $this->trace->queries);
    }

    public function test_typed_operation_facts_are_safe_evidence_not_reference_or_business_grants(): void
    {
        $facts = ActivityExecution::fromParts(
            ActivityTarget::fromStrings('tool', 'inspect'),
            ActivityTiming::fromParts(null, ObservationOccurrence::fromString('2026-10-01T12:00:01.12Z'), null),
            ActivityResult::returned()
        );
        $observation = Observation::forActivity(
            ObservationId::generate(),
            'visible',
            $this->visible->session(),
            $this->visible->phase(),
            ObservationOccurrence::fromString('2026-10-01T12:00:01Z'),
            PhaseActivity::create('consumer/tool', 'Safe returned evidence', null, [], $facts)
        );
        $this->recorder->record($observation);
        $this->scope->access = ExecutionAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => ['visible/'.$observation->id()]]
        );
        $result = $this->invoker->invoke('execution.activity', $this->arguments('execution.activity'))->toArray();
        $wire = json_decode(json_encode($result['structuredContent'], JSON_THROW_ON_ERROR), true);
        self::assertSame($facts->toArray(), $wire['activities'][0]['activity']['execution']);
        self::assertSame([], $wire['activities'][0]['details']);
        self::assertSame(1, $wire['total']);
        self::assertSame(
            ['unknown' => 1, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0],
            $this->queryBus->fetch(new SummarizeExecutions($this->selection()))->toArray()['outcomes']
        );
        self::assertSame(0, $this->trace->bodyReads());
    }

    public function test_reference_retention_and_missing_execution_remain_truthful_without_body_opening(): void
    {
        $this->db->executeStatement('DELETE FROM fight_execution_contents');
        $page = $this->queryBus->fetch(new ListPhaseActivity('project', 'session', 'phase'))->toArray();
        self::assertSame('missing', $page['activities'][0]['details'][0]['availability']);
        self::assertSame('partial', $page['activities'][0]['details'][0]['capture']['completeness']);
        self::assertNull($this->queryBus->fetch(new GetExecution('project', 'absent')));
        self::assertSame(0, $this->trace->bodyReads());
    }

    public function test_ordering_is_live_and_encoded_projection_overflow_fails_closed(): void
    {
        $usage = [];
        for ($i = 0; $i < 32; ++$i) {
            $usage[] = UsageMeasurement::create(
                'consumer/metric'.$i,
                str_repeat('u', 128),
                str_repeat('p', 128),
                UsageCompleteness::PARTIAL,
                '12.5'
            );
        }

        for ($i = 0; $i < 40; ++$i) {
            $this->recorder->record(Observation::forActivity(
                ObservationId::generate(),
                'bulk',
                $this->visible->session(),
                $this->visible->phase(),
                ObservationOccurrence::fromString('2026-10-01T12:00:00Z'),
                PhaseActivity::create('consumer/tool', str_repeat('"', 512), null, $usage)
            ));
        }

        $this->scope->access = ExecutionAccess::create(['project' => ['session']], ['project' => ['phase']]);
        $query = new ListPhaseActivity('project', 'session', 'phase', 1, 100, [], ['recorded_at' => 'ASC']);
        $page = $this->queryBus->fetch($query)->toArray();
        self::assertSame(42, $page['total']);
        self::assertSame($this->visible->id()->toString(), $page['activities'][0]['observation_id']);
        $this->assertSafeFailure('execution.activity', ['per_page' => 100]);
        self::assertSame(0, $this->trace->bodyReads());
    }

    private function compose(): void
    {
        $admission = new AgentExecutionReadAdmission(
            $this->env->provider,
            $this->env->request,
            $this->env->correlationId,
            $this->scope,
            [PermissionName::fromString('READ_TIMELINE')],
            [PermissionName::fromString('READ_REFERENCE')]
        );
        $router = new InMemoryQueryRouter();
        $reader = new DbalExecutionReader($this->db);
        $handlers = [
            new SummarizeExecutionsHandler($admission, $reader),
            new ListExecutionsHandler($admission, $reader),
            new GetExecutionHandler($admission, $reader),
            new ListExecutionPhasesHandler($admission, new DbalPhaseReader($this->db)),
            new ListPhaseActivityHandler($admission, new DbalActivityReader($this->db))
        ];
        foreach ($handlers as $handler) {
            $router->registerHandler($handler::queryRegistration(), $handler);
        }

        $this->queryBus = new RoutingQueryBus($router);
        $registry = new McpToolRegistry([
            new SummarizeExecutionsTool($this->queryBus), new ListExecutionsTool($this->queryBus),
            new GetExecutionTool($this->queryBus), new ListExecutionPhasesTool($this->queryBus),
            new ListPhaseActivityTool($this->queryBus)
        ]);
        $catalog = new AgentToolPermissionCatalog(
            $registry,
            array_fill_keys(array_keys($this->queries()), ['USE_EXECUTION_TOOLS'])
        );
        $availability = new AgentToolAvailability(
            $catalog,
            $this->env->provider,
            $this->env->request,
            $this->env->correlationId
        );
        $this->invoker = new McpToolInvoker($registry, $availability);
        $this->responder = new McpResponder(new McpCapabilityRegistry(
            new McpServerInfo('execution-fixture', '1.0.0'),
            [new McpToolDiscovery($registry, $availability, str_repeat('c', 32)), new McpToolInvocation($this->invoker)]
        ));
    }

    private function freshRequest(): void
    {
        $this->env->nextRequest();
        $this->compose();
    }

    /** @param list<string> $names */
    private function grant(array $names): void
    {
        $agent = $this->env->storage->agentRepository->all()[0];
        foreach ($agent->getPermissionIds() as $id) {
            $agent = $agent->revokePermission($id, $this->env->now());
        }

        foreach ($names as $name) {
            $id = PermissionId::generate();
            $this->env->permissionRepository->add(Permission::define(
                $id,
                PermissionName::fromString($name),
                $this->env->now()
            ));
            $agent = $agent->grantPermission($id, $this->env->now());
        }

        $this->env->storage->agentRepository->restoreSnapshot([$agent]);
    }

    private function seed(
        string $project,
        string $session,
        string $phase,
        string $producer,
        string $model,
        string $usage
    ): Observation {
        $reference = SessionReference::fromStrings($project, $session, 'consumer:'.$session);
        $phaseReference = PhaseReference::fromStrings($session, $phase, null, 'agent', 'work', 1);
        $this->recorder->record(Observation::create(
            ObservationId::generate(),
            'state',
            $reference,
            null,
            $this->env->now()
        ));
        $this->recorder->record(Observation::create(
            ObservationId::generate(),
            'state',
            $reference,
            $phaseReference,
            $this->env->now()
        ));
        $activity = Observation::forActivity(
            ObservationId::generate(),
            $producer,
            $reference,
            $phaseReference,
            ObservationOccurrence::fromString('2026-10-01T12:00:00Z'),
            PhaseActivity::create('consumer/tool', 'Safe activity', $model, [
                UsageMeasurement::create('consumer/tokens', 'token', 'consumer', UsageCompleteness::PARTIAL, $usage)
            ], ActivityExecution::fromParts(
                null,
                ActivityTiming::fromParts(ObservationOccurrence::fromString('2026-10-01T12:00:00Z'), null, null),
                ActivityResult::unknown()
            ))
        );
        $this->recorder->record($activity);

        return $activity;
    }

    private function seedDetail(Observation $activity): Observation
    {
        $detail = Observation::forDetail(
            ObservationId::generate(),
            $activity->producerId(),
            $activity->session(),
            $activity->phase(),
            ObservationOccurrence::fromString('2026-10-01T12:00:00Z'),
            DetailCapture::create($activity->id(), DetailKind::MESSAGE, 'text/plain', CaptureCompleteness::PARTIAL)
        );
        $this->recorder->record($detail, ProtectedContent::create($detail->detail(), 'PROTECTED-BODY-SENTINEL'));

        return $detail;
    }

    private function access(): ExecutionAccess
    {
        return ExecutionAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => ['visible/'.$this->visible->id()]],
            ['project' => ['visible/'.$this->detail->id()]]
        );
    }

    private function selection(): ExecutionSelection
    {
        return ExecutionSelection::create(['project']);
    }

    /** @return array<string, Query> */
    private function queries(): array
    {
        return [
            'execution.summarize' => new SummarizeExecutions($this->selection()),
            'execution.list'      => new ListExecutions($this->selection()),
            'execution.get'       => new GetExecution('project', 'session'),
            'execution.phases'    => new ListExecutionPhases('project', 'session'),
            'execution.activity'  => new ListPhaseActivity('project', 'session', 'phase')
        ];
    }

    /** @return array<string, mixed> */
    private function arguments(string $name): array
    {
        if (in_array($name, ['execution.summarize', 'execution.list'], true)) {
            return ['projects' => ['project']];
        }

        $arguments = ['project_id' => 'project', 'session_id' => 'session'];
        if ($name === 'execution.activity') {
            $arguments['phase_id'] = 'phase';
        }

        return $arguments;
    }

    /** @return list<list<array<string, mixed>>> */
    private function state(): array
    {
        return array_map(fn(string $table): array => $this->db->fetchAllAssociative('SELECT * FROM '.$table), [
            'fight_execution_schema', 'fight_execution_sessions', 'fight_execution_phases',
            'fight_execution_observations', 'fight_execution_contents'
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function assertSafeFailure(string $name = 'execution.summarize', array $extra = []): void
    {
        try {
            $this->invoker->invoke($name, $extra + $this->arguments($name));
            self::fail('Fault must fail safely.');
        } catch (DomainException $domainException) {
            self::assertSame('Tool execution failed.', $domainException->getMessage());
        }

        $response = $this->responder->respond(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => $name,
                'arguments' => $extra + $this->arguments($name),
                '_meta'     => [
                    'io.modelcontextprotocol/protocolVersion'    => McpResponder::PROTOCOL_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => (object) []
                ]
            ]
        ], JSON_THROW_ON_ERROR))->toArray();
        self::assertSame(-32603, $response['error']['code']);
        self::assertLessThanOrEqual(1048576, strlen(json_encode($response, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString('SENTINEL', json_encode($response));
    }
}
