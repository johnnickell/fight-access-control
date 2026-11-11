<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriberReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentSubscriberReadPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriberReader;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionSchema;
use Fight\Common\Adapter\Mcp\Persistence\Subscription\Dbal\DbalSubscriptionStore;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Mcp\McpResponder;
use Fight\Common\Application\Mcp\Protocol\McpRequestMetadata;
use Fight\Common\Application\Mcp\Subscription\Exception\SubscriberReadFailed;
use Fight\Common\Application\Mcp\Subscription\QueryHandler\ListSubscribersHandler;
use Fight\Common\Application\Mcp\Subscription\QueryHandler\SummarizeSubscribersHandler;
use Fight\Common\Application\Mcp\Subscription\Service\SubscriberAccess;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use Fight\Common\Domain\Mcp\Subscription\Query\ListSubscribers;
use Fight\Common\Domain\Mcp\Subscription\Query\SummarizeSubscribers;
use Fight\Common\Domain\Mcp\Subscription\SubscriberSelection;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
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
use stdClass;
use Symfony\Component\Process\Process;
use Throwable;

#[CoversClass(AgentSubscriberReadAdmission::class)]
final class AgentSubscriberReadAdmissionTest extends TestCase
{
    private CurrentAgentPrincipalProvider $provider;

    private SignedAgentRequest $request;

    private InMemoryAgentRequestNonceConsumer $nonces;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-11-12T12:00:00Z');
        $permission = Permission::define(
            PermissionId::fromString('018f0000-0000-7000-8000-000000000801'),
            PermissionName::fromString('INSPECT'),
            $now
        );
        $agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000802'),
            AgentName::fromString('Inspector'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000803'),
            'encrypted:fixture',
            $now
        )->grantPermission($permission->getId(), $now);
        $agentRepository = new InMemoryAgentRepository();
        $agentRepository->add($agent);

        $permissionRepository = new InMemoryPermissionRepository();
        $permissionRepository->add($permission);

        $unit = new InMemoryUnitOfWork();
        $this->nonces = new InMemoryAgentRequestNonceConsumer($agentRepository, $unit);
        $this->request = new SignedAgentRequest(
            'POST',
            'inspect.example',
            '/query',
            '',
            $now,
            'nonce',
            $agent->getCredentialId(),
            'HMAC-SHA256',
            'signature',
            null,
            ''
        );
        $this->provider = new CurrentAgentPrincipalProvider(
            $agentRepository,
            $permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($this->request, 'fixture'),
            new FixedClock($now),
            $this->nonces,
            $unit
        );
    }

    public function test_all_requirements_are_conjunctive_and_scope_is_not_derived_from_selection(): void
    {
        $policy = $this->createMock(AgentSubscriberReadPolicy::class);
        $access = SubscriberAccess::create(['trusted'], [str_repeat('a', 32)]);
        $policy->expects(self::exactly(2))->method('accessFor')->willReturn($access);
        $admission = new AgentSubscriberReadAdmission(
            $this->provider,
            $this->request,
            'inspect',
            [PermissionName::fromString('INSPECT')],
            $policy
        );
        self::assertSame($access, $admission->currentAccess(SubscriberSelection::create(['FORGED'])));
        self::assertSame($access, $admission->currentAccess(SubscriberSelection::create([])));
        self::assertSame(1, $this->nonces->consumptionCalls());
        $admission = new AgentSubscriberReadAdmission(
            $this->provider,
            $this->request,
            'inspect',
            [PermissionName::fromString('INSPECT'), PermissionName::fromString('OTHER')],
            $policy
        );
        $this->expectException(PermissionDenied::class);
        $admission->currentAccess(SubscriberSelection::create(['trusted']));
    }

    public function test_invalid_requirement_configuration_rejects_before_authentication(): void
    {
        foreach ([[], ['INSPECT'], [null], ['forged' => PermissionName::fromString('INSPECT')]] as $requirements) {
            try {
                new AgentSubscriberReadAdmission(
                    $this->provider,
                    $this->request,
                    'inspect',
                    $requirements,
                    $this->createStub(AgentSubscriberReadPolicy::class)
                );
                self::fail('Malformed independent requirements must reject');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->nonces->consumptionCalls());
            }
        }
    }

    public function test_abrupt_real_protected_http_worker_death_stays_uncertain_until_owning_cleanup(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inspection-');
        self::assertIsString($path);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        new DbalSubscriptionSchema()->install($connection);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        fclose($listener);
        $process = new Process([
            PHP_BINARY,
            '-d',
            'output_buffering=0',
            '-d',
            'zlib.output_compression=0',
            '-S',
            $address,
            dirname(__DIR__, 4).'/Adapter/Mcp/Subscription/Agent/native-server.php'
        ], dirname(__DIR__, 5), ['INSPECTION_TRACKING_PATH' => $path], timeout: 10);
        $process->start();
        try {
            $socket = false;
            for ($attempt = 0; $attempt < 100 && $socket === false; ++$attempt) {
                $socket = @stream_socket_client('tcp://'.$address, $errorCode, $error, 0.05);
                if ($socket === false) {
                    usleep(10000);
                }
            }

            self::assertIsResource($socket, $process->getErrorOutput());
            stream_set_timeout($socket, 3);
            $json = json_encode([
                'jsonrpc' => '2.0',
                'id'      => 7,
                'method'  => 'subscriptions/listen',
                'params'  => [
                    '_meta'         => [
                        McpRequestMetadata::PROTOCOL_VERSION_KEY    => McpResponder::PROTOCOL_VERSION,
                        McpRequestMetadata::CLIENT_CAPABILITIES_KEY => new stdClass()
                    ],
                    'notifications' => ['toolsListChanged' => true, 'promptsListChanged' => true]
                ]
            ], JSON_THROW_ON_ERROR);
            fwrite($socket, implode("\r\n", [
                'POST /mcp HTTP/1.1',
                'Host: '.$address,
                'Accept: application/json, text/event-stream',
                'Content-Type: application/json',
                'MCP-Protocol-Version: '.McpResponder::PROTOCOL_VERSION,
                'MCP-Method: subscriptions/listen',
                'Content-Length: '.strlen($json),
                'Connection: close',
                '',
                $json
            ]));
            $wire = '';
            while (!str_contains($wire, 'acknowledged')) {
                $line = fgets($socket);
                self::assertNotFalse($line, $process->getErrorOutput());
                $wire .= $line;
            }

            $policy = $this->createStub(AgentSubscriberReadPolicy::class);
            $policy->method('accessFor')->willReturn(SubscriberAccess::create(['project']));
            $admission = new AgentSubscriberReadAdmission(
                $this->provider,
                $this->request,
                'inspect',
                [PermissionName::fromString('INSPECT')],
                $policy
            );
            $now = time();
            $handler = new SummarizeSubscribersHandler(
                $admission,
                new DbalSubscriberReader($connection, 'controlled-worker-sqlite', static function () use (&$now): int {
                    return $now;
                })
            );
            $query = QueryMessage::create(new SummarizeSubscribers(SubscriberSelection::create(['project'])));
            self::assertSame(1, $handler->handle($query)->toArray()['states']['recently_observed']);
            $process->stop(0, 9);
            fclose($socket);
            self::assertFalse($process->isRunning());
            self::assertSame(1, $handler->handle($query)->toArray()['states']['recently_observed']);
            $before = $connection->fetchAllAssociative('SELECT * FROM fight_mcp_subscriptions');
            $now += 6;
            self::assertSame(1, $handler->handle($query)->toArray()['states']['stale_uncertain']);
            self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM fight_mcp_subscriptions'));
            $row = $before[0];
            try {
                new DbalSubscriptionStore($connection)->heartbeat(
                    $row['registration_id'],
                    $row['owner'],
                    $now,
                    $now + 5
                );
                self::fail('Late update cannot renew an expired observation');
            } catch (RuntimeException) {
                self::assertSame(1, $handler->handle($query)->toArray()['states']['stale_uncertain']);
            }

            self::assertSame(1, new DbalSubscriptionStore($connection)->cleanup($now, 256));
            self::assertSame(0, $handler->handle($query)->toArray()['subscriptions']);
            self::assertSame(1, $this->nonces->consumptionCalls());
        } finally {
            $process->stop(0, 9);
            $connection->close();
            unlink($path);
        }
    }

    public function test_committed_cleanup_between_actual_reader_batches_preserves_live_not_frozen_semantics(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inspection-race-');
        self::assertIsString($path);
        $writer = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        new DbalSubscriptionSchema()->install($writer);
        $store = new DbalSubscriptionStore($writer);
        $context = [
            'subscriber' => 'same',
            'selection'  => NoticeSelection::create(['project'])->toArray(),
            'requested'  => ['toolsListChanged' => true],
            'accepted'   => ['toolsListChanged' => true]
        ];
        for ($index = 1; $index <= 40; ++$index) {
            $store->open(str_pad(dechex($index), 32, '0', STR_PAD_LEFT), str_repeat('f', 32), $context, 100, 105);
        }

        $connection = new InterleavedSubscriberConnection(
            ['driver' => 'pdo_sqlite', 'path' => $path],
            $writer->getDriver()
        );
        try {
            $policy = $this->createStub(AgentSubscriberReadPolicy::class);
            $policy->method('accessFor')->willReturn(SubscriberAccess::create(['project']));
            $admission = new AgentSubscriberReadAdmission(
                $this->provider,
                $this->request,
                'inspect',
                [PermissionName::fromString('INSPECT')],
                $policy
            );
            $handler = new ListSubscribersHandler(
                $admission,
                new DbalSubscriberReader($connection, 'controlled-shared-sqlite', static fn(): int => 103)
            );
            $query = QueryMessage::create(new ListSubscribers(SubscriberSelection::create(['project']), 1, 16));
            $before = $handler->handle($query)->toArray();
            self::assertSame(40, $before['total']);
            self::assertSame(str_pad(dechex(40), 32, '0', STR_PAD_LEFT), $before['subscribers'][0]['registration_id']);
            $connection->afterBatch = static function () use ($store): void {
                self::assertSame(40, $store->cleanup(106, 256));
            };
            $during = $handler->handle($query)->toArray();
            self::assertSame(32, $during['total']);
            self::assertSame('live', $during['scope']['consistency']);
            self::assertCount(16, $during['subscribers']);
            self::assertSame(0, $handler->handle($query)->toArray()['total']);
            self::assertSame(1, $this->nonces->consumptionCalls());
        } finally {
            $connection->close();
            $writer->close();
            unlink($path);
        }
    }

    public function test_actual_reader_refuses_oversized_complete_pages_without_truncating_dimensions(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalSubscriptionSchema()->install($connection);
        $store = new DbalSubscriptionStore($connection);
        $owner = str_repeat('f', 32);
        for ($index = 1; $index <= 16; ++$index) {
            $store->open(str_pad(dechex($index), 32, '0', STR_PAD_LEFT), $owner, [
                'subscriber' => 'same',
                'selection'  => NoticeSelection::create(['project'])->toArray(),
                'requested'  => ['resourceSubscriptions' => array_fill(0, 16, 'docs:'.str_repeat('x', 2000))],
                'accepted'   => ['toolsListChanged' => true]
            ], 100, 105);
        }

        $policy = $this->createStub(AgentSubscriberReadPolicy::class);
        $policy->method('accessFor')->willReturn(SubscriberAccess::create(['project']));
        $admission = new AgentSubscriberReadAdmission(
            $this->provider,
            $this->request,
            'inspect',
            [PermissionName::fromString('INSPECT')],
            $policy
        );
        $reader = new DbalSubscriberReader($connection, 'controlled-sqlite', static fn(): int => 103);
        $selection = SubscriberSelection::create(['project']);
        $summary = new SummarizeSubscribersHandler($admission, $reader);
        self::assertSame(16, $summary->handle(QueryMessage::create(new SummarizeSubscribers($selection)))
            ->toArray()['subscriptions']);
        $handler = new ListSubscribersHandler($admission, $reader);
        $small = $handler->handle(QueryMessage::create(new ListSubscribers($selection, 1, 1)))->toArray();
        self::assertSame(16, $small['total']);
        self::assertCount(16, $small['subscribers'][0]['requested']->get('resourceSubscriptions'));
        try {
            $handler->handle(QueryMessage::create(new ListSubscribers($selection, 1, 16)));
            self::fail('A complete oversized page must fail, never silently truncate');
        } catch (SubscriberReadFailed $subscriberReadFailed) {
            self::assertSame('Subscriber inspection unavailable.', $subscriberReadFailed->getMessage());
        }

        $connection->executeStatement('DROP TABLE fight_mcp_subscriptions');
        $denied = $this->createStub(AgentSubscriberReadPolicy::class);
        $denied->method('accessFor')->willReturn(SubscriberAccess::create([]));
        $deniedHandler = new ListSubscribersHandler(new AgentSubscriberReadAdmission(
            $this->provider,
            $this->request,
            'inspect',
            [PermissionName::fromString('INSPECT')],
            $denied
        ), $reader);
        self::assertSame(0, $deniedHandler->handle(QueryMessage::create(new ListSubscribers($selection)))
            ->toArray()['total']);
    }

    public function test_actual_supported_query_and_grant_bounds_reject_instead_of_truncating(): void
    {
        $selection = SubscriberSelection::create(['project']);
        $constructors = [
            static fn(): ListSubscribers => new ListSubscribers($selection, 0),
            static fn(): ListSubscribers => new ListSubscribers($selection, 1, 0),
            static fn(): ListSubscribers => new ListSubscribers($selection, 1, 17),
            static fn(): ListSubscribers => new ListSubscribers($selection, 1000002, 1),
            static fn(): ListSubscribers => new ListSubscribers($selection, 1, 1, ['subscriber' => 'ASC']),
            static fn(): ListSubscribers => new ListSubscribers($selection, 1, 1, ['opened_at' => 'sideways']),
            static fn(): SubscriberSelection => SubscriberSelection::create(['project'], ['role' => 'admin']),
            static fn(): SubscriberSelection => SubscriberSelection::create(['project'], ['state' => 'connected']),
            static fn(): SubscriberSelection => SubscriberSelection::create(['project'], ['registration_id' => '7']),
            static fn(): SubscriberSelection => SubscriberSelection::create(
                ['project'],
                ['requested_resource' => 'bad uri']
            ),
            static fn(): SubscriberSelection => SubscriberSelection::create(array_fill(0, 17, 'project')),
            static fn(): SubscriberAccess => SubscriberAccess::create(
                ['project'],
                array_fill(0, 4097, str_repeat('a', 32))
            )
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
    }
}
