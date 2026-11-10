<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Detail;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Detail\AgentDetailReadAdmission;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Detail\Dbal\DbalDetailReader;
use Fight\Common\Adapter\Mcp\Persistence\Detail\Dbal\DbalProtectedContentRepository;
use Fight\Common\Adapter\Mcp\Persistence\Execution\Dbal\DbalObservationSchema;
use Fight\Common\Adapter\Mcp\Persistence\Execution\Dbal\DbalRecordingTransaction;
use Fight\Common\Adapter\Mcp\Persistence\Observation\Dbal\DbalObservationRepository;
use Fight\Common\Adapter\Mcp\Persistence\Phase\Dbal\DbalPhaseRepository;
use Fight\Common\Adapter\Mcp\Persistence\Session\Dbal\DbalSessionRepository;
use Fight\Common\Adapter\Mcp\Tool\Detail\GetCapturedDetailTool;
use Fight\Common\Adapter\Mcp\Tool\Detail\ListCapturedMessagesTool;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Detail\QueryHandler\GetCapturedDetailHandler;
use Fight\Common\Application\Mcp\Detail\QueryHandler\ListCapturedMessagesHandler;
use Fight\Common\Application\Mcp\Detail\Service\DetailAccess;
use Fight\Common\Application\Mcp\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\McpServerInfo;
use Fight\Common\Application\Mcp\Observation\Service\ObservationAdmission;
use Fight\Common\Application\Mcp\Observation\Service\ObservationRecorder;
use Fight\Common\Application\Mcp\Protocol\Exception\McpProtocolException;
use Fight\Common\Application\Mcp\Session\Exception\ExecutionReadFailed;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Mcp\Activity\PhaseActivity;
use Fight\Common\Domain\Mcp\Detail\CaptureCompleteness;
use Fight\Common\Domain\Mcp\Detail\DetailCapture;
use Fight\Common\Domain\Mcp\Detail\DetailKind;
use Fight\Common\Domain\Mcp\Detail\DetailSelection;
use Fight\Common\Domain\Mcp\Detail\OutputSegment;
use Fight\Common\Domain\Mcp\Detail\ProtectedContent;
use Fight\Common\Domain\Mcp\Detail\Query\GetCapturedDetail;
use Fight\Common\Domain\Mcp\Detail\Query\ListCapturedMessages;
use Fight\Common\Domain\Mcp\Observation\Observation;
use Fight\Common\Domain\Mcp\Observation\ObservationId;
use Fight\Common\Domain\Mcp\Observation\ObservationOccurrence;
use Fight\Common\Domain\Mcp\Phase\PhaseReference;
use Fight\Common\Domain\Mcp\Session\SessionReference;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture\ProfileToolEnvironment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentDetailReadAdmission::class)]
final class AgentDetailReadAdmissionTest extends TestCase
{
    private ProfileToolEnvironment $env;

    private Connection $db;

    private DetailSqlTrace $trace;

    private ObservationRecorder $recorder;

    private Observation $activity;

    private Observation $detail;

    private ControlledDetailScope $scope;

    private RoutingQueryBus $queryBus;

    private McpToolInvoker $invoker;

    private McpResponder $responder;

    protected function setUp(): void
    {
        $this->env = new ProfileToolEnvironment();
        $this->grant(['READ_DETAIL_METADATA', 'READ_DETAIL_BODY', 'USE_DETAIL_TOOLS']);
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
        $this->activity = $this->seedActivity('project', 'session', 'phase', 'producer');
        $this->detail = $this->seedDetail($this->activity, 'PROTECTED-BODY-SENTINEL');
        $this->scope = new ControlledDetailScope($this->access());
        $this->compose();
        $this->trace->queries = [];
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    /** @return iterable<string, array{bool, bool, bool, bool}> */
    public static function permissionMatrix(): iterable
    {
        foreach ([false, true] as $metadata) {
            foreach ([false, true] as $body) {
                foreach ([false, true] as $scope) {
                    foreach ([false, true] as $bodyScope) {
                        yield implode('-', array_map(intval(...), [$metadata, $body, $scope, $bodyScope]))
                            => [$metadata, $body, $scope, $bodyScope];
                    }
                }
            }
        }
    }

    #[DataProvider('permissionMatrix')]
    public function test_real_tools_and_direct_queries_intersect_independent_grants(
        bool $metadata,
        bool $body,
        bool $scope,
        bool $bodyScope
    ): void {
        $names = ['USE_DETAIL_TOOLS'];
        if ($metadata) {
            $names[] = 'READ_DETAIL_METADATA';
        }

        if ($body) {
            $names[] = 'READ_DETAIL_BODY';
        }

        $this->grant($names);
        $this->scope->access = $scope ? $this->access($bodyScope) : DetailAccess::create([]);
        $this->freshRequest();
        $state = $this->state();
        $agent = $this->env->storage->agentRepository->all();
        $eventCount = count($this->env->storage->eventDispatcher->events());
        $this->trace->queries = [];
        $reads = 0;
        foreach (['execution.detail', 'execution.messages'] as $name) {
            $toolResult = $this->invoker->invoke($name, $this->arguments($name))->toArray();
            self::assertSame('complete', $toolResult['resultType']);
            self::assertArrayNotHasKey('isError', $toolResult);
            $direct = $this->queryBus->dispatch(QueryMessage::arrayDeserialize(QueryMessage::create(
                $name === 'execution.detail' ? $this->query() : new ListCapturedMessages($this->selection())
            )->arraySerialize()));
            $wire = json_decode(json_encode($toolResult['structuredContent'], JSON_THROW_ON_ERROR), true);
            self::assertSame($direct?->toArray(), $wire);
            if (!$metadata || !$scope) {
                self::assertNull($wire);
                continue;
            }

            $result = $name === 'execution.detail' ? $wire : $wire['messages'][0];
            self::assertSame($body && $bodyScope, $result['body_permitted']);
            self::assertSame('retained', $result['reference']['availability']);
            if ($body && $bodyScope) {
                self::assertSame('PROTECTED-BODY-SENTINEL', base64_decode($result['chunk']['data'], true));
                $reads += 2;
            } else {
                self::assertNull($result['chunk']);
                self::assertStringNotContainsString('PROTECTED-BODY-SENTINEL', json_encode($toolResult));
                self::assertStringNotContainsString(base64_encode('PROTECTED-BODY-SENTINEL'), json_encode($toolResult));
            }
        }

        self::assertSame($reads, $this->trace->bodyReads());
        self::assertSame($metadata ? 4 : 0, $this->scope->calls);
        self::assertSame(1, $this->env->nonces->consumptionCalls());
        self::assertSame($state, $this->state());
        self::assertSame($agent, $this->env->storage->agentRepository->all());
        self::assertCount($eventCount, $this->env->storage->eventDispatcher->events());
        self::assertSame(0, $this->env->storage->agentRepository->nameWrites);
        self::assertFalse($this->db->isTransactionActive());
        // No timeline Permission is assigned in any matrix case.
        self::assertFalse($this->env->provider->resolve($this->env->request, 'same')->hasPermission(
            PermissionName::fromString('READ_TIMELINE')
        ));
    }

    public function test_coarse_availability_is_not_direct_query_authority_or_body_permission(): void
    {
        $this->grant(['READ_DETAIL_METADATA', 'READ_DETAIL_BODY']);
        $this->freshRequest();
        try {
            $this->invoker->invoke('execution.detail', $this->arguments('execution.detail'));
            self::fail('Coarse gate must reject.');
        } catch (McpProtocolException $mcpProtocolException) {
            self::assertSame(
                'Unknown or unavailable tool.',
                $mcpProtocolException->protocolError()->toArray()['message']
            );
        }

        self::assertSame(0, $this->scope->calls);
        self::assertSame(0, $this->trace->bodyReads());
        self::assertNotNull($this->queryBus->fetch($this->query()));
        self::assertSame(1, $this->trace->bodyReads());
        self::assertSame(1, $this->env->nonces->consumptionCalls());
    }

    public function test_complete_relationships_and_reference_kind_are_concealed_on_both_paths(): void
    {
        $otherActivity = $this->seedActivity('project', 'other-session', 'other-phase', 'producer');
        $other = $this->seedDetail($otherActivity, 'OTHER-SESSION-BODY');
        $foreign = $this->seedDetail(
            $this->seedActivity('foreign', 'foreign-session', 'foreign-phase', 'other'),
            'FOREIGN'
        );
        $samePhase = Observation::forActivity(
            ObservationId::generate(),
            'producer',
            $this->activity->session(),
            $this->activity->phase(),
            ObservationOccurrence::fromString('2026-10-01T12:00:00Z'),
            PhaseActivity::create('consumer/tool', 'Other safe activity')
        );
        $this->recorder->record($samePhase);
        $unrelated = $this->seedDetail($samePhase, 'OTHER-ACTIVITY');
        $this->trace->queries = [];
        foreach (
            [
            ['project_id' => 'foreign'],
            ['session_id' => 'other-session'],
            ['phase_id' => 'other-phase'],
            ['producer_id' => 'other'],
            ['activity_id' => $samePhase->id()->toString()]
            ] as $change
        ) {
            $selection = DetailSelection::fromArray(array_replace($this->selection()->toArray(), $change));
            self::assertNull($this->queryBus->fetch(new GetCapturedDetail(
                $selection,
                $this->detail->id(),
                DetailKind::MESSAGE
            )));
            $wire = $this->invoker->invoke('execution.detail', array_replace(
                $this->arguments('execution.detail'),
                $change
            ))->toArray();
            self::assertNull($wire['structuredContent']);
            if (!isset($change['activity_id'])) {
                self::assertNull($this->queryBus->fetch(new ListCapturedMessages($selection)));
            }
        }

        foreach ([$other, $foreign, $unrelated] as $detail) {
            self::assertNull($this->queryBus->fetch(new GetCapturedDetail(
                $this->selection(),
                $detail->id(),
                DetailKind::MESSAGE
            )));
        }

        self::assertNull($this->queryBus->fetch(new GetCapturedDetail(
            $this->selection(),
            $this->detail->id(),
            DetailKind::TOOL_RESULT
        )));
        self::assertSame(0, $this->trace->bodyReads());
        foreach (
            [
            DetailAccess::create(['project' => ['not-session']], metadataGrants: ['project' => null]),
            DetailAccess::create(['project' => null], ['project' => []], ['project' => null]),
            DetailAccess::create(['project' => null], metadataGrants: ['project' => ['other/'.$this->detail->id()]])
            ] as $access
        ) {
            $this->scope->access = $access;
            self::assertNull($this->queryBus->fetch($this->query()));
        }
    }

    public function test_metadata_selection_constrains_totals_pages_and_body_reads_before_disclosure(): void
    {
        $details = [$this->detail];
        for ($i = 0; $i < 103; ++$i) {
            $details[] = $this->seedDetail($this->activity, 'message-'.$i);
        }

        $this->seedDetail($this->activity, 'arguments', DetailKind::TOOL_ARGUMENTS);
        $keys = array_map(static fn(Observation $detail): string => $detail->producerId().'/'.$detail->id(), $details);
        $this->scope->access = DetailAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => [$keys[0], $keys[1]]],
            ['project' => [$keys[1]]]
        );
        $this->trace->queries = [];
        $page = $this->queryBus->fetch(new ListCapturedMessages($this->selection(), 1, 1))->toArray();
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['messages']);
        $second = $this->queryBus->fetch(new ListCapturedMessages($this->selection(), 2, 1))->toArray();
        self::assertSame(2, $second['total']);
        self::assertCount(1, $second['messages']);
        self::assertSame(1, $this->trace->bodyReads());
        self::assertNotSame(
            $page['messages'][0]['reference']['observation_id'],
            $second['messages'][0]['reference']['observation_id']
        );
        self::assertSame([], $this->queryBus->fetch(
            new ListCapturedMessages($this->selection(), 3, 1)
        )->toArray()['messages']);
        $this->scope->access = $this->access(false);
        $all = $this->queryBus->fetch(new ListCapturedMessages($this->selection(), 4, 32))->toArray();
        self::assertSame(104, $all['total']);
        self::assertCount(8, $all['messages']);
        self::assertSame(1, $this->trace->bodyReads());
        self::assertLessThan(262144, strlen(json_encode($all, JSON_THROW_ON_ERROR)));
    }

    /** @return iterable<string, array{string}> */
    public static function authorityChanges(): iterable
    {
        foreach (['metadata', 'body', 'revoke', 'rotate', 'scope', 'body-scope'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('authorityChanges')]
    public function test_later_chunks_recheck_authority_and_scope_without_nonce_per_decision(string $change): void
    {
        $first = $this->queryBus->fetch(new GetCapturedDetail(
            $this->selection(),
            $this->detail->id(),
            DetailKind::MESSAGE,
            0,
            3
        ));
        self::assertSame(3, $first->toArray()['chunk']['next_offset']);
        $agent = $this->env->storage->agentRepository->all()[0];
        if ($change === 'metadata' || $change === 'body') {
            $names = ['READ_DETAIL_BODY', 'USE_DETAIL_TOOLS'];
            if ($change === 'body') {
                $names = ['READ_DETAIL_METADATA', 'USE_DETAIL_TOOLS'];
            }

            $this->grant($names);
        } elseif ($change === 'revoke') {
            $this->env->storage->agentRepository->restoreSnapshot([$agent->revoke($this->env->now())]);
        } elseif ($change === 'rotate') {
            $this->env->storage->agentRepository->restoreSnapshot([$agent->rotateRecoverableCredential(
                $agent->getCredentialId(),
                $agent->getCredentialRevision(),
                AgentCredentialId::generate(),
                'auth-envelope:rotated-secret',
                $this->env->now()
            )]);
        }

        if (in_array($change, ['metadata', 'body', 'revoke', 'rotate'], true)) {
            // Resolved ordinary requests retain their documented immutable Permission/credential snapshot.
            self::assertNotNull($this->queryBus->fetch($this->query())->toArray()['chunk']);
        }

        if ($change === 'scope') {
            $this->scope->access = DetailAccess::create([]);
        } elseif ($change === 'body-scope') {
            $this->scope->access = $this->access(false);
        }

        self::assertSame(1, $this->env->nonces->consumptionCalls());
        $this->freshRequest();
        $this->trace->queries = [];
        try {
            $next = $this->queryBus->fetch(new GetCapturedDetail(
                $this->selection(),
                $this->detail->id(),
                DetailKind::MESSAGE,
                3,
                3
            ));
            self::assertNotContains($change, ['revoke', 'rotate']);
            if ($change === 'metadata' || $change === 'scope') {
                self::assertNull($next);
            } else {
                self::assertFalse($next->toArray()['body_permitted']);
                self::assertNull($next->toArray()['chunk']);
            }
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertContains($change, ['revoke', 'rotate']);
        }

        self::assertSame(0, $this->trace->bodyReads());
        self::assertSame(in_array($change, ['revoke', 'rotate'], true) ? 1 : 2, $this->env->nonces->consumptionCalls());
    }

    public function test_all_kinds_utf8_byte_chunks_empty_partial_and_retention_keep_actual_common_projection(): void
    {
        foreach (DetailKind::cases() as $kind) {
            $body = str_repeat("α🙂\r\n", 700);
            $detail = $this->seedDetail($this->activity, $body, $kind, CaptureCompleteness::PARTIAL);
            $actual = '';
            $offset = 0;
            do {
                $result = $this->queryBus->fetch(new GetCapturedDetail(
                    $this->selection(),
                    $detail->id(),
                    $kind,
                    $offset,
                    4093
                ))->toArray();
                self::assertSame('partial', $result['reference']['capture']['completeness']);
                self::assertSame('base64', $result['chunk']['encoding']);
                self::assertSame($offset, $result['chunk']['offset']);
                self::assertLessThanOrEqual(4093, $result['chunk']['bytes']);
                self::assertLessThan(262144, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
                $actual .= base64_decode($result['chunk']['data'], true);
                $offset = $result['chunk']['next_offset'];
            } while ($offset !== null);

            self::assertSame($body, $actual);
            $end = $this->queryBus->fetch(new GetCapturedDetail(
                $this->selection(),
                $detail->id(),
                $kind,
                strlen($body),
                1
            ))->toArray();
            self::assertSame('', $end['chunk']['data']);
            self::assertSame('partial', $end['reference']['capture']['completeness']);
        }

        foreach (CaptureCompleteness::cases() as $capture) {
            $retained = in_array($capture, [CaptureCompleteness::COMPLETE, CaptureCompleteness::PARTIAL], true);
            $detail = $this->seedDetail($this->activity, $retained ? '' : null, capture: $capture);
            $query = new GetCapturedDetail($this->selection(), $detail->id(), DetailKind::MESSAGE);
            $result = $this->queryBus->fetch($query)->toArray();
            self::assertSame($capture->value, $result['reference']['capture']['completeness']);
            self::assertSame($retained ? 'retained' : $capture->value, $result['reference']['availability']);
            self::assertSame($retained ? '' : null, $result['chunk']['data'] ?? null);
            $this->db->delete('fight_execution_contents', ['observation_id' => $detail->id()->toString()]);
            $missing = $this->queryBus->fetch($query)->toArray();
            self::assertNull($missing['chunk']);
            self::assertSame($retained ? 'missing' : $capture->value, $missing['reference']['availability']);
        }

        $this->db->delete('fight_execution_contents', ['observation_id' => $this->detail->id()->toString()]);
        self::assertNull($this->queryBus->fetch($this->query())->toArray()['chunk']);
        $this->db->delete('fight_execution_observations', ['observation_id' => $this->detail->id()->toString()]);
        self::assertNull($this->queryBus->fetch($this->query()));
    }

    public function test_policy_storage_and_resolution_faults_are_not_empty_success_or_raw_protocol_errors(): void
    {
        $this->scope->failure = new RuntimeException('PRIVATE-POLICY-BODY-SENTINEL');
        try {
            $this->queryBus->fetch($this->query());
            self::fail('Direct faults must propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($this->scope->failure, $runtimeException);
        }

        $this->assertSafeToolFailure();
        self::assertSame(0, $this->trace->bodyReads());
        $this->scope->failure = null;
        $this->db->executeStatement('DROP TABLE fight_execution_contents');
        try {
            $this->queryBus->fetch($this->query());
            self::fail('Storage failure must not be null.');
        } catch (ExecutionReadFailed $executionReadFailed) {
            self::assertSame('Execution evidence could not be read completely.', $executionReadFailed->getMessage());
        }

        $this->assertSafeToolFailure();
        $agent = $this->env->storage->agentRepository->all()[0];
        $this->env->storage->agentRepository->restoreSnapshot([
            $agent->grantPermission(PermissionId::generate(), $this->env->now())
        ]);
        $this->freshRequest();
        try {
            $this->queryBus->fetch($this->query());
            self::fail('Malformed authority is not a data denial.');
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertSame(0, $this->trace->bodyReads());
        }
    }

    public function test_fresh_message_page_loses_old_totals_after_relationship_and_permission_changes(): void
    {
        $second = $this->seedDetail($this->activity, str_repeat('second-message', 200));
        $page = $this->queryBus->fetch(new ListCapturedMessages($this->selection(), 1, 1))->toArray();
        self::assertSame(2, $page['total']);
        self::assertSame(1024, $page['messages'][0]['chunk']['bytes']);
        $this->scope->access = DetailAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => ['producer/'.$second->id()]]
        );
        $this->freshRequest();
        $this->trace->queries = [];
        $later = $this->queryBus->fetch(new ListCapturedMessages($this->selection(), 2, 1))->toArray();
        self::assertSame(1, $later['total']);
        self::assertSame([], $later['messages']);
        $this->grant(['USE_DETAIL_TOOLS', 'READ_DETAIL_BODY']);
        $this->freshRequest();
        self::assertNull($this->queryBus->fetch(new ListCapturedMessages($this->selection(), 2, 1)));
        self::assertSame(0, $this->trace->bodyReads());
        self::assertSame(3, $this->env->nonces->consumptionCalls());
    }

    public function test_common_captures_utf8_not_arbitrary_binary_but_byte_chunks_can_split_codepoints(): void
    {
        $detail = $this->seedDetail($this->activity, '🙂');
        $result = $this->queryBus->fetch(new GetCapturedDetail(
            $this->selection(),
            $detail->id(),
            DetailKind::MESSAGE,
            1,
            1
        ))->toArray();
        self::assertSame(substr('🙂', 1, 1), base64_decode($result['chunk']['data'], true));
        self::assertSame('base64', $result['chunk']['encoding']);
        try {
            $this->seedDetail($this->activity, "\xff\x00");
            self::fail('Common must reject arbitrary non-UTF-8 capture.');
        } catch (DomainException $domainException) {
            self::assertSame('Invalid or oversized protected content.', $domainException->getMessage());
        }
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function invalidRequirements(): iterable
    {
        foreach ([[], ['bad-name'], ['READ_DETAIL_METADATA', 1], ['key' => 'READ_DETAIL_METADATA']] as $invalid) {
            yield 'metadata-'.json_encode($invalid) => [$invalid, [PermissionName::fromString('READ_DETAIL_BODY')]];
            yield 'body-'.json_encode($invalid) => [[PermissionName::fromString('READ_DETAIL_METADATA')], $invalid];
        }
    }

    /**
     * @param array<mixed> $metadata
     * @param array<mixed> $body
     */
    #[DataProvider('invalidRequirements')]
    public function test_both_permission_sets_require_canonical_configuration(array $metadata, array $body): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentDetailReadAdmission(
            $this->env->provider,
            $this->env->request,
            $this->env->correlationId,
            $this->scope,
            $metadata,
            $body
        );
    }

    public function test_invalid_selection_ranges_and_caller_metadata_cannot_create_grants(): void
    {
        $invalid = [
            ['offset' => -1], ['length' => 0], ['length' => 4097], ['project_id' => ''], ['kind' => 'invented']
        ];
        foreach ($invalid as $change) {
            try {
                $failure = $this->invoker->invoke(
                    'execution.detail',
                    array_replace($this->arguments('execution.detail'), $change)
                )->toArray();
                self::assertTrue($failure['isError']);
                self::assertStringNotContainsString('PROTECTED', json_encode($failure));
            } catch (McpProtocolException $failure) {
                self::assertStringNotContainsString('PROTECTED', json_encode($failure->protocolError()->toArray()));
            }
        }

        self::assertSame(0, $this->trace->bodyReads());
        $this->grant(['USE_DETAIL_TOOLS', 'READ_TIMELINE']);
        $this->freshRequest();
        $result = $this->invoker->invoke('execution.detail', $this->arguments('execution.detail'))->toArray();
        self::assertNull($result['structuredContent']);
        self::assertSame(0, $this->trace->bodyReads());
    }

    private function compose(): void
    {
        $admission = new AgentDetailReadAdmission(
            $this->env->provider,
            $this->env->request,
            $this->env->correlationId,
            $this->scope,
            [PermissionName::fromString('READ_DETAIL_METADATA')],
            [PermissionName::fromString('READ_DETAIL_BODY')]
        );
        $router = new InMemoryQueryRouter();
        $reader = new DbalDetailReader($this->db);
        $router->registerHandler(
            GetCapturedDetailHandler::queryRegistration(),
            new GetCapturedDetailHandler($admission, $reader)
        );
        $router->registerHandler(
            ListCapturedMessagesHandler::queryRegistration(),
            new ListCapturedMessagesHandler($admission, $reader)
        );
        $this->queryBus = new RoutingQueryBus($router);
        $registry = new McpToolRegistry([
            new GetCapturedDetailTool($this->queryBus), new ListCapturedMessagesTool($this->queryBus)
        ]);
        $catalog = new AgentToolPermissionCatalog($registry, [
            'execution.detail'   => ['USE_DETAIL_TOOLS'],
            'execution.messages' => ['USE_DETAIL_TOOLS']
        ]);
        $availability = new AgentToolAvailability(
            $catalog,
            $this->env->provider,
            $this->env->request,
            $this->env->correlationId
        );
        $this->invoker = new McpToolInvoker($registry, $availability);
        $this->responder = new McpResponder(new McpCapabilityRegistry(
            new McpServerInfo('protected-detail-fixture', '1.0.0'),
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

    private function seedActivity(string $project, string $session, string $phase, string $producer): Observation
    {
        $reference = SessionReference::fromStrings($project, $session, 'consumer:'.$session);
        $phaseReference = PhaseReference::fromStrings($session, $phase, null, 'agent', 'work', 1);
        $this->recorder->record(Observation::create(
            ObservationId::generate(),
            $producer,
            $reference,
            null,
            $this->env->now()
        ));
        $this->recorder->record(Observation::create(
            ObservationId::generate(),
            $producer,
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
            PhaseActivity::create('consumer/tool', 'Safe summary')
        );
        $this->recorder->record($activity);

        return $activity;
    }

    private function seedDetail(
        Observation $activity,
        ?string $body,
        DetailKind $kind = DetailKind::MESSAGE,
        CaptureCompleteness $capture = CaptureCompleteness::COMPLETE
    ): Observation {
        $detail = Observation::forDetail(
            ObservationId::generate(),
            $activity->producerId(),
            $activity->session(),
            $activity->phase(),
            ObservationOccurrence::fromString('2026-10-01T12:00:00.12Z'),
            DetailCapture::create(
                $activity->id(),
                $kind,
                'application/octet-stream',
                $capture,
                $kind === DetailKind::PROCESS_OUTPUT ? OutputSegment::create('stdout', 0, 0) : null
            )
        );
        $this->recorder->record($detail, ProtectedContent::create($detail->detail(), $body));

        return $detail;
    }

    private function access(bool $body = true): DetailAccess
    {
        return DetailAccess::create(
            ['project' => ['session']],
            ['project' => ['phase']],
            ['project' => null],
            $body ? ['project' => null] : []
        );
    }

    private function selection(): DetailSelection
    {
        return DetailSelection::fromArray([
            'project_id'  => 'project',
            'session_id'  => 'session',
            'phase_id'    => 'phase',
            'producer_id' => 'producer',
            'activity_id' => $this->activity->id()->toString()
        ]);
    }

    private function query(): GetCapturedDetail
    {
        return new GetCapturedDetail($this->selection(), $this->detail->id(), DetailKind::MESSAGE);
    }

    /** @return array<string, mixed> */
    private function arguments(string $name): array
    {
        $arguments = $this->selection()->toArray();
        if ($name === 'execution.detail') {
            $arguments += ['detail_id' => $this->detail->id()->toString(), 'kind' => 'message'];
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

    private function assertSafeToolFailure(): void
    {
        try {
            $this->invoker->invoke('execution.detail', $this->arguments('execution.detail'));
            self::fail('Fault must remain a safe protocol failure.');
        } catch (DomainException $domainException) {
            self::assertSame('Tool execution failed.', $domainException->getMessage());
            self::assertStringNotContainsString('SENTINEL', $domainException->getMessage());
        }

        $response = $this->responder->respond(json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'execution.detail',
                'arguments' => $this->arguments('execution.detail'),
                '_meta'     => [
                    'io.modelcontextprotocol/protocolVersion'    => McpResponder::PROTOCOL_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => (object) []
                ]
            ]
        ], JSON_THROW_ON_ERROR))->toArray();
        self::assertSame(-32603, $response['error']['code']);
        self::assertStringNotContainsString('SENTINEL', json_encode($response));
    }
}
