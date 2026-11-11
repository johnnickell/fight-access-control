<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionAdmission;
use Fight\AccessControl\Adapter\Mcp\Subscription\Agent\AgentSubscriptionFamilies;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationContext;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationHandoff;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriptionScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Http\DenyAllMcpOriginPolicy;
use Fight\Common\Adapter\Mcp\Http\McpRequestHandler;
use Fight\Common\Adapter\Mcp\Http\McpResponseEmitter;
use Fight\Common\Adapter\Mcp\Http\McpResponseFactory;
use Fight\Common\Adapter\Mcp\Notice\SodiumNoticeContinuationProtector;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRecoveryReader;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRepository;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeSchema;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeTransaction;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionSchema;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionStore;
use Fight\Common\Adapter\Mcp\Tool\Notice\RecoverNoticesTool;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Capability\McpCapabilityRegistry;
use Fight\Common\Application\Mcp\Http\McpInvocationGuard;
use Fight\Common\Application\Mcp\McpDiagnostics;
use Fight\Common\Application\Mcp\Notice\QueryHandler\RecoverNoticesHandler;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAccess;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAdmission;
use Fight\Common\Application\Mcp\Notice\Service\NoticeReadAdmission;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecorder;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecovery;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecoveryIdentity;
use Fight\Common\Application\Mcp\Protocol\McpServerInfo;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptions;
use Fight\Common\Application\Mcp\Tool\McpToolAvailability;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInfo;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

require dirname(__DIR__, 5).'/vendor/autoload.php';

// Controlled trusted original handoff; request authentication and nonce consumption are covered by owning tests.
$now = new DateTimeImmutable();
$agent = Agent::provision(
    AgentId::fromString('018f0000-0000-7000-8000-000000000701'),
    AgentName::fromString('Native fixture'),
    AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000702'),
    'encrypted:fixture',
    $now
);
$permission = Permission::define(
    PermissionId::fromString('018f0000-0000-7000-8000-000000000703'),
    PermissionName::fromString('LIVE_FIXTURE'),
    $now
);
$agent = $agent->grantPermission($permission->getId(), $now);
$agentRepository = new InMemoryAgentRepository();
$agentRepository->add($agent);
$permissionRepository = new InMemoryPermissionRepository();
$permissionRepository->add($permission);
$handoff = new readonly class ($agent, $now) implements AgentContinuationHandoff {
    public function __construct(private Agent $agent, private DateTimeImmutable $now)
    {
    }

    public function verify(): AgentContinuationContext
    {
        return new AgentContinuationContext(
            $this->agent->getId(),
            $this->agent->getCredentialId(),
            $this->agent->getCredentialRevision(),
            $this->now
        );
    }
};
$binding = AgentContinuationBinding::fromHandoff($handoff);
$authority = new AgentNoticeAuthority(
    $binding,
    $agentRepository,
    $permissionRepository,
    new FixedClock($now),
    [$permission->getName()]
);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
new DbalNoticeSchema()->install($connection);
$trackingPath = getenv('INSPECTION_TRACKING_PATH');
$trackingParameters = ['driver' => 'pdo_sqlite', 'memory' => true];
if ($trackingPath !== false) {
    $trackingParameters = ['driver' => 'pdo_sqlite', 'path' => $trackingPath];
}

$tracking = DriverManager::getConnection($trackingParameters);
if ($trackingPath === false) {
    new DbalSubscriptionSchema()->install($tracking);
}

$transaction = new DbalNoticeTransaction($connection);
$admission = new class implements NoticeAdmission {
    public function admit(Notice $notice): void
    {
    }
};
$dispatcher = new SimpleEventDispatcher();
$recorder = new NoticeRecorder(new DbalNoticeRepository($transaction), $transaction, $admission, $dispatcher);
$source = new class ($recorder) implements NoticeReadAdmission {
    private int $calls = 0;

    public function __construct(private readonly NoticeRecorder $recorder)
    {
    }

    public function currentAccess(NoticeSelection $selection): NoticeAccess
    {
        if (++$this->calls === 2) {
            $this->recorder->record(Notice::fromArray([
                'version'       => 1,
                'id'            => 'native-one',
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

        return NoticeAccess::create(['project'], [], new NoticeRecoveryIdentity('producer-source', 'epoch-1', true));
    }
};
$handler = new RecoverNoticesHandler($source, new NoticeRecovery(
    new DbalNoticeRecoveryReader($connection),
    new SodiumNoticeContinuationProtector(['fixture1' => random_bytes(32)], 'fixture1')
));
$router = new InMemoryQueryRouter();
$router->registerHandlers([$handler::queryRegistration() => $handler]);
$queryBus = new RoutingQueryBus($router);
$registry = new McpToolRegistry([new RecoverNoticesTool($queryBus)]);
$catalog = new AgentToolPermissionCatalog($registry, ['notices.recover' => ['LIVE_FIXTURE']]);
$scope = new class implements AgentSubscriptionScope {
    public function allows(AuthenticatedAgentPrincipal $principal, string $filter, ?string $uri, ?Notice $notice): bool
    {
        return true;
    }
};
$live = new AgentSubscriptionAdmission('trusted-native', $authority, $authority, $scope, new AgentSubscriptionFamilies(
    $registry,
    $catalog
));
$subscriptions = new McpSubscriptions(
    $queryBus,
    new DbalSubscriptionStore($tracking),
    $live,
    NoticeSelection::create(['project']),
    resources: false,
    lifetimeSeconds: $trackingPath === false ? 3 : 30,
    waitMicroseconds: 10000,
    maxFrameBytes: 8192
);
$availability = new class implements McpToolAvailability {
    public function isAvailable(McpToolInfo $tool): bool
    {
        return true;
    }
};
$capabilities = new McpCapabilityRegistry(new McpServerInfo('native-fixture', '1'), [
    new McpToolDiscovery($registry, $availability, str_repeat('k', 32)),
    new McpToolInvocation(new McpToolInvoker($registry, $availability))
], $subscriptions);
$guard = new class implements McpInvocationGuard {
    public function allows(string $method, ?string $name): bool
    {
        return true;
    }
};
$diagnostics = new class implements McpDiagnostics {
    public function record(Throwable $failure): void
    {
        error_log('Controlled native failure: '.$failure::class);
    }
};
$endpoint = new McpRequestHandler(
    $capabilities,
    new DenyAllMcpOriginPolicy(),
    $guard,
    new McpResponseFactory(new ResponseFactory(), new StreamFactory(), $diagnostics)
);
new McpResponseEmitter()->emit($endpoint->handle(
    ServerRequestCreatorFactory::create()->createServerRequestFromGlobals()
));
