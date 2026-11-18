<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Task\Agent;

use Closure;
use Doctrine\DBAL\Connection;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionFamilies;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentTaskSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Task\Agent\AgentTaskServing;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationFence;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriptionScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCurrentReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureGuard;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosurePolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskDisclosureScope;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionStore;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Notice\Data\NoticeRecoveryBatch;
use Fight\Common\Application\Mcp\Protocol\McpRequest;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionAdmission;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionExecution;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptions;
use Fight\Common\Application\Mcp\Task\McpTasks;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Adapter\Mcp\Subscription\Agent\ControlledSubscriptionStore;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Task\TaskAdmissionFixture;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Controlled fence and policy, not a production all-writer lock or policy implementation. */
final class TaskLiveFixture implements AgentTaskDisclosurePolicy, AgentContinuationFence, McpDiagnostics
{
    public const array GRANTS = [...TaskServingFixture::GRANTS, 'TASK_LISTEN', 'TASK_SIGNAL'];

    public readonly TaskServingFixture $ordinary;

    public AgentTaskSubscriptionAdmission $admission;

    public AgentTaskCurrentReadAdmission $read;

    public AgentTaskDisclosureGuard $guard;

    public AgentContinuationBinding $binding;

    public TaskAdmissionFixture $auth;

    public readonly ControlledSubscriptionStore $tracking;

    public McpSubscriptions $subscriptions;

    public bool $signal = true;

    public bool $state = true;

    public bool $held = false;

    public bool $supported = true;

    public bool $fault = false;

    public int $guards = 0;

    public int $releases = 0;

    public int $states = 0;

    public ?Closure $beforeAcquire = null;

    public ?Closure $afterState = null;

    public ?Closure $onSignal = null;

    /** @var list<Throwable> */
    public array $failures = [];

    /** @param list<string> $grants */
    public function __construct(
        Connection $connection,
        Connection $tracking,
        array $grants = self::GRANTS,
        int $maxFrameBytes = 8192,
        ?McpSubscriptionAdmission $generic = null,
        int $lifetimeSeconds = 1,
        bool $mixed = false
    ) {
        $this->tracking = new ControlledSubscriptionStore(new DbalSubscriptionStore($tracking));
        $this->ordinary = new TaskServingFixture(
            $connection,
            $grants,
            readFactory: function (TaskAdmissionFixture $auth): AgentTaskCurrentReadAdmission {
                $this->auth = $auth;
                $this->binding = AgentContinuationBinding::fromRequest($auth->provider, $auth->request, 'task-serving');
                $this->read = new AgentTaskCurrentReadAdmission($this->authority(['TASK_READ']), $auth);

                return $this->read;
            },
            subscriptionsFactory: function (
                AgentTaskServing $serving,
                TaskAdmissionFixture $auth,
                McpToolRegistry $registry
            ) use (
                $maxFrameBytes,
                $generic,
                $lifetimeSeconds,
                $mixed
            ): McpSubscriptions {
                if ($mixed) {
                    $generic = new AgentSubscriptionAdmission(
                        'reader',
                        $this->authority(['TASK_LISTEN']),
                        $this->authority(['TASK_SIGNAL']),
                        new class implements AgentSubscriptionScope {
                            public function allows(
                                AuthenticatedAgentPrincipal $principal,
                                string $filter,
                                ?string $uri,
                                ?Notice $notice
                            ): bool {
                                return $filter === 'toolsListChanged';
                            }
                        },
                        new AgentSubscriptionFamilies(
                            $registry,
                            new AgentToolPermissionCatalog($registry, ['example.operation' => ['TOOL_START']])
                        )
                    );
                }

                $source = new RoutingQueryBus(new InMemoryQueryRouter());
                if ($mixed) {
                    // Controlled safe-notice source. Actual DBAL recovery is qualified by TASK-00093.
                    $source = new class implements QueryBus {
                        public function fetch(Query $query): NoticeRecoveryBatch
                        {
                            return new NoticeRecoveryBatch([], 'complete', 'controlled-generic-continuation');
                        }

                        public function dispatch(QueryMessage $queryMessage): NoticeRecoveryBatch
                        {
                            return new NoticeRecoveryBatch([], 'complete', 'controlled-generic-continuation');
                        }
                    };
                }

                $this->admission = new AgentTaskSubscriptionAdmission(
                    'reader',
                    $this->authority(['TASK_LISTEN', 'TASK_SIGNAL']),
                    $this,
                    $generic
                );
                $this->guard = new AgentTaskDisclosureGuard(
                    'reader',
                    new AgentContinuationGuard(
                        $this->auth->agentRepository,
                        $this->auth->permissionRepository,
                        new FixedClock(TaskAdmissionFixture::now()),
                        $this
                    ),
                    $this->binding,
                    array_map(PermissionName::fromString(...), ['TASK_LISTEN', 'TASK_SIGNAL', 'TASK_READ']),
                    $this
                );
                $this->subscriptions = new McpSubscriptions(
                    $source,
                    $this->tracking,
                    $this->admission,
                    NoticeSelection::create(['scope']),
                    tools: $generic !== null,
                    resources: false,
                    lifetimeSeconds: $lifetimeSeconds,
                    waitMicroseconds: 10000,
                    maxFrameBytes: $maxFrameBytes,
                    taskCapability: $serving->tasks(),
                    taskDisclosureGuard: $this->guard
                );

                return $this->subscriptions;
            }
        );
        $this->ordinary->admission->allows('subscriptions/listen', null);
    }

    /** @param list<string> $requirements */
    public function authority(array $requirements): AgentNoticeAuthority
    {
        return new AgentNoticeAuthority(
            $this->binding,
            $this->auth->agentRepository,
            $this->auth->permissionRepository,
            new FixedClock(TaskAdmissionFixture::now()),
            array_map(PermissionName::fromString(...), $requirements)
        );
    }

    public function execution(int|string $id = '007'): McpSubscriptionExecution
    {
        $this->ordinary->admission->allows('subscriptions/listen', null);

        return $this->subscriptions->execution(new McpRequest($id, 'subscriptions/listen', [
            'notifications' => StrictJson::fromObject([
                'taskIds' => [TaskAdmissionFixture::ID], 'toolsListChanged' => true
            ])
        ], McpRequestMetadata::fromObject(StrictJson::fromObject([
            McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
            McpRequestMetadata::CLIENT_CAPABILITIES_KEY => (object) [
                'extensions' => (object) ['io.modelcontextprotocol/tasks' => (object) []]
            ]
        ]))));
    }

    public function allowsSignal(AuthenticatedAgentPrincipal $principal, string $subscriber, TaskId $id): bool
    {
        ($this->onSignal)?->__invoke();
        if ($this->fault) {
            throw new RuntimeException('Private Task policy failure.');
        }

        return $this->signal && $subscriber === 'reader' && $id->toString() === TaskAdmissionFixture::ID
            && $principal->getAgentId()->equals($this->auth->agent->getId());
    }

    public function allowsState(
        AuthenticatedAgentPrincipal $principal,
        string $subscriber,
        TaskId $id,
        array $state
    ): bool {
        if (!$this->held) {
            throw new RuntimeException('Missing controlled fence.');
        }

        ++$this->states;
        ($this->afterState)?->__invoke($state);
        // Check visibility continuity, not arrival order: an older coherent state may remain eligible.
        $current = $this->ordinary->serving->tasks()->current($id->toString());

        $proposedScope = $state['_meta'][McpTasks::REVISION]->get('scope');

        return $this->state && $current !== null
            && $proposedScope === $current['_meta'][McpTasks::REVISION]->get('scope');
    }

    public function synchronized(
        AgentId $agentId,
        #[SensitiveParameter] AgentContinuationScope $scope,
        #[SensitiveParameter] Closure $admit
    ): void {
        ($this->beforeAcquire)?->__invoke();
        if (
            !$this->supported || $this->held || !$scope instanceof AgentTaskDisclosureScope
            || $scope->policy() !== $this
        ) {
            throw new RuntimeException('Unsupported controlled synchronization.');
        }

        $this->held = true;
        ++$this->guards;
        try {
            $admit();
        } finally {
            $this->held = false;
            ++$this->releases;
        }
    }

    public function record(Throwable $failure): void
    {
        $this->failures[] = $failure;
    }
}
