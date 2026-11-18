<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationHandoff;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticePublicationPolicy;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticePublicationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeReadPolicy;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Adapter\Mcp\Notice\SodiumNoticeContinuationProtector;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeReader;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRecoveryReader;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeSchema;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Adapter\Socket\MercureHubPublisher;
use Fight\Common\Adapter\Socket\PrivateMercureHubPublisher;
use Fight\Common\Application\Mcp\Notice\Data\NoticeView;
use Fight\Common\Application\Mcp\Notice\Exception\CommittedNoticeDispatchFailed;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeReadFailed;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeStorageFailed;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublication;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublicationDiagnostics;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublicationGrant;
use Fight\Common\Application\Mcp\Notice\Publication\NoticePublicationStatus;
use Fight\Common\Application\Mcp\Notice\Publication\RecordNoticeAndPublish;
use Fight\Common\Application\Mcp\Notice\QueryHandler\GetNoticeHandler;
use Fight\Common\Application\Mcp\Notice\QueryHandler\RecoverNoticesHandler;
use Fight\Common\Application\Mcp\Notice\Service\NoticeAccess;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecovery;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecoveryIdentity;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Mcp\Notice\Command\RecordNotice;
use Fight\Common\Domain\Mcp\Notice\Event\NoticeRecorded;
use Fight\Common\Domain\Mcp\Notice\NoticeSelection;
use Fight\Common\Domain\Mcp\Notice\Query\GetNotice;
use Fight\Common\Domain\Mcp\Notice\Query\RecoverNotices;
use Fight\Common\Domain\Utility\ClassName;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\Common\TestCase\Mcp\Notice\NoticeFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

#[CoversClass(AgentNoticePublicationPolicy::class)]
final class AgentNoticePublicationPolicyTest extends TestCase
{
    private Connection $connection;

    private NoticeFixture $writer;

    private RoutingQueryBus $queryBus;

    private Agent $agent;

    private InMemoryAgentRepository $agentRepository;

    private InMemoryPermissionRepository $permissionRepository;

    private Permission $publish;

    private Permission $read;

    private AgentContinuationBinding $binding;

    private DateTimeImmutable $time;

    private AgentNoticePublicationPolicy $policy;

    private ?NoticePublicationGrant $grant;

    private NoticeAccess $access;

    private ?Throwable $scopeFailure = null;

    private ?Throwable $authorityFailure = null;

    private int $scopeCalls = 0;

    private int $requests = 0;

    private InMemoryAgentRequestNonceConsumer $bindingNonce;

    private string $permittedTopic = 'catalog/tools';

    /** @var list<string> */
    private array $permittedAudience = ['other-recipient', 'recipient-agent'];

    /** @var list<Update> */
    private array $updates = [];

    /** @var list<Throwable> */
    private array $diagnostics = [];

    private bool $hubFails = false;

    private bool $diagnosticsFail = false;

    private string $key;

    protected function setUp(): void
    {
        $this->time = new DateTimeImmutable('2026-10-25T01:02:03Z');
        $this->publish = $this->permission('PUBLISH_NOTICES', 1);
        $this->read = $this->permission('READ_NOTICES', 2);
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000901'),
            AgentName::fromString('Publisher'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000902'),
            'encrypted:fixture',
            $this->time
        )->grantPermission($this->publish->getId(), $this->time)->grantPermission($this->read->getId(), $this->time);
        $this->agentRepository = new InMemoryAgentRepository();
        $this->agentRepository->add($this->agent);

        $this->permissionRepository = new InMemoryPermissionRepository();
        $this->permissionRepository->add($this->publish);
        $this->permissionRepository->add($this->read);
        [$provider, $request, $nonce] = $this->request();
        $original = AgentContinuationBinding::fromRequest($provider, $request, 'publish');
        $handoff = $this->createStub(AgentContinuationHandoff::class);
        $handoff->method('verify')->willReturn($original->getContext());
        $this->binding = AgentContinuationBinding::fromHandoff($handoff);
        $this->bindingNonce = $nonce;
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new DbalNoticeSchema()->install($this->connection);
        $this->writer = new NoticeFixture($this->connection);
        $this->key = random_bytes(32);
        $this->access = NoticeAccess::create(['scope'], [], new NoticeRecoveryIdentity('reader', 'revision-1', true));
        $this->newReadRequest();
        $repository = $this->createStub(AgentRepository::class);
        $repository->method('getOperationContract')->willReturn($this->agentRepository->getOperationContract());
        $repository->method('getById')->willReturnCallback(function (AgentId $id): ?Agent {
            self::assertFalse($this->connection->isTransactionActive());
            if ($this->authorityFailure !== null) {
                throw $this->authorityFailure;
            }

            return $this->agentRepository->getById($id);
        });
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn(): DateTimeImmutable => $this->time);
        $scope = $this->createStub(AgentNoticePublicationScope::class);
        $scope->method('currentGrant')->willReturnCallback(function (
            AuthenticatedAgentPrincipal $principal,
            NoticeView $view
        ): ?NoticePublicationGrant {
            ++$this->scopeCalls;
            self::assertTrue($principal->getAgentId()->equals($this->agent->getId()));
            self::assertSame('scope', $view->notice()->scope());
            self::assertFalse($this->connection->isTransactionActive());
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
            if ($this->scopeFailure !== null) {
                throw $this->scopeFailure;
            }

            if (
                $view->toArray()['topic'] !== $this->permittedTopic
                || $view->notice()->relationships()->toArray()['recipients'] !== $this->permittedAudience
            ) {
                return null;
            }

            return $this->grant;
        });
        $this->grant = NoticePublicationGrant::create('https://feed.test/private', true, ['topic'], ['app/label']);
        $this->policy = new AgentNoticePublicationPolicy(new AgentNoticeAuthority(
            $this->binding,
            $repository,
            $this->permissionRepository,
            $clock,
            [PermissionName::fromString('PUBLISH_NOTICES')]
        ), $scope);
    }

    public function test_real_public_private_updates_preserve_committed_data_after_repeated_attempts(): void
    {
        $operation = $this->operation();
        $composition = new RecordNoticeAndPublish($this->writer->commandBus, $operation);
        $command = RecordNotice::fromArray(NoticeFixture::data([
            'metadata' => ['app/label' => '雪"\\/', 'app/hidden' => 'HIDDEN-BODY']
        ]));
        self::assertSame(NoticePublicationStatus::PUBLISHED, $composition->record($command));
        $before = $this->connection->fetchAllAssociative('SELECT * FROM fight_notices');
        $this->hubFails = true;
        $this->diagnosticsFail = true;
        self::assertSame(NoticePublicationStatus::FAILED, $composition->record($command));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM fight_notices'));
        self::assertCount(1, $this->diagnostics);
        $this->hubFails = false;
        $this->grant = NoticePublicationGrant::create('https://feed.test/public', false, [], ['app/label']);
        self::assertSame(NoticePublicationStatus::PUBLISHED, $operation->publish($this->identity()));
        self::assertCount(3, $this->updates);
        self::assertSame($this->updates[0]->getData(), $this->updates[1]->getData());
        foreach ($this->updates as $i => $update) {
            self::assertSame($i < 2, $update->isPrivate());
            self::assertSame([$i < 2 ? 'https://feed.test/private' : 'https://feed.test/public'], $update->getTopics());
            self::assertLessThanOrEqual(4096, strlen($update->getData()));
            self::assertStringNotContainsString('HIDDEN', $update->getData());
            self::assertStringNotContainsString('PRIVATE', $update->getData());
            self::assertSame('雪"\\/', json_decode($update->getData(), true)['metadata']['app/label']);
        }

        self::assertSame(1, $this->bindingNonce->consumptionCalls());
        self::assertSame(3, $this->scopeCalls);
        self::assertSame('complete', $this->recover()['status']);
        self::assertCount(1, $this->recover()['notices']);
    }

    #[DataProvider('authorityChanges')]
    public function test_each_attempt_rechecks_original_authority(string $change): void
    {
        $operation = $this->operation();
        self::assertSame(NoticePublicationStatus::PUBLISHED, $this->record($operation));
        $this->changeAuthority($change);
        $expected = NoticePublicationStatus::DENIED;
        if (in_array($change, ['resolver', 'definitions'], true)) {
            $expected = NoticePublicationStatus::FAILED;
        }

        self::assertSame($expected, $operation->publish($this->identity()));
        self::assertCount(1, $this->updates);
        self::assertSame(1, $this->scopeCalls);
        self::assertSame(1, $this->bindingNonce->consumptionCalls());
        self::assertCount($expected === NoticePublicationStatus::FAILED ? 1 : 0, $this->diagnostics);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
    }

    /** @return iterable<string, array{string}> */
    public static function authorityChanges(): iterable
    {
        foreach (['missing', 'revoked', 'permission', 'credential', 'expired', 'resolver', 'definitions'] as $change) {
            yield $change => [$change];
        }
    }

    public function test_read_allow_scope_denial_fault_and_missing_private_support_never_grant_publication(): void
    {
        $this->writer->record();
        $operation = $this->operation();
        $this->changeAuthority('permission');
        self::assertNotNull($this->queryBus->fetch($this->identity()));
        self::assertSame(NoticePublicationStatus::DENIED, $operation->publish($this->identity()));
        self::assertSame(0, $this->scopeCalls);
        $this->agent = $this->agent->grantPermission($this->publish->getId(), $this->time);
        $this->saveAgent();
        $this->grant = null; // Consumer denies wrong audience/topic/mode; no inference from recipients.
        self::assertSame(NoticePublicationStatus::DENIED, $operation->publish($this->identity()));
        $this->scopeFailure = new RuntimeException('PRIVATE topic policy');
        self::assertSame(NoticePublicationStatus::FAILED, $operation->publish($this->identity()));
        $this->scopeFailure = null;
        $this->grant = NoticePublicationGrant::create('https://feed.test/private', true);
        $withoutPrivate = $this->operation(private: false);
        self::assertSame(NoticePublicationStatus::UNSUPPORTED, $withoutPrivate->publish($this->identity()));
        $withoutOutput = $this->operation(false, false);
        self::assertSame(NoticePublicationStatus::UNSUPPORTED, $withoutOutput->publish($this->identity()));
        self::assertSame([], $this->updates);
        self::assertCount(1, $this->diagnostics);
        self::assertCount(1, $this->recover()['notices']);
    }

    public function test_failed_precommit_and_caller_owned_transaction_never_send_or_undo_unrelated_work(): void
    {
        $operation = $this->operation();
        $sql = "CREATE TRIGGER fail_record BEFORE INSERT ON fight_notice_comparisons ";
        $sql .= "BEGIN SELECT RAISE(ABORT, 'PRIVATE storage'); END";
        $this->connection->executeStatement($sql);
        try {
            $this->record($operation);
            self::fail('Precommit failure must throw');
        } catch (NoticeStorageFailed $noticeStorageFailed) {
            self::assertFalse($noticeStorageFailed->outcomeUncertain());
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notices'));
        $this->connection->executeStatement('CREATE TABLE caller_work (id INTEGER)');
        $this->connection->beginTransaction();
        $this->connection->insert('caller_work', ['id' => 42]);
        try {
            $this->record($operation);
            self::fail('A savepoint is not acknowledged commit');
        } catch (LogicException) {
            self::assertTrue($this->connection->isTransactionActive());
            self::assertSame(42, (int) $this->connection->fetchOne('SELECT id FROM caller_work'));
        }

        $this->connection->commit();
        self::assertSame([], $this->updates);
        self::assertSame(0, $this->scopeCalls);
    }

    public function test_mapping_reference_and_actual_encoded_byte_bounds_reject_without_any_send(): void
    {
        $recipients = array_map(static fn(int $i): string => 'recipient-'.$i, range(1, 32));
        $this->writer->references = [...$this->writer->references, ...$recipients];
        $data = NoticeFixture::data();
        $data['relationships']['recipients'] = $recipients;
        sort($recipients);
        $this->permittedAudience = $recipients;
        $this->writer->record($data);
        $this->grant = NoticePublicationGrant::create('https://feed.test/private', true, ['recipients']);
        self::assertSame(NoticePublicationStatus::INVALID, $this->operation()->publish($this->identity()));
        $this->grant = NoticePublicationGrant::create('https://feed.test/private', true, [], ['app/missing']);
        self::assertSame(NoticePublicationStatus::INVALID, $this->operation()->publish($this->identity()));
        $this->scopeFailure = new DomainException('Invalid topic mapping.');
        self::assertSame(NoticePublicationStatus::INVALID, $this->operation()->publish($this->identity()));
        self::assertSame([], $this->updates);
        self::assertCount(3, $this->diagnostics);
    }

    public function test_complete_encoded_bytes_include_escaping_and_multibyte_expansion_before_send(): void
    {
        $recipients = array_map(static fn(int $i): string => str_repeat('r', 94).$i, range(1, 28));
        $this->writer->references = [...$this->writer->references, ...$recipients];
        $data = NoticeFixture::data();
        $data['relationships']['recipients'] = $recipients;
        $keys = array_map(static fn(int $i): string => 'app/key'.$i, range(1, 10));
        $data['metadata'] = array_fill_keys($keys, str_repeat('雪', 20));
        sort($recipients);
        $this->permittedAudience = $recipients;
        $this->writer->record($data);
        $this->grant = NoticePublicationGrant::create(
            'https://feed.test/private',
            true,
            ['recipients'],
            array_keys($data['metadata'])
        );
        self::assertSame(NoticePublicationStatus::INVALID, $this->operation()->publish($this->identity()));
        self::assertSame([], $this->updates);
        self::assertCount(1, $this->diagnostics);
    }

    public function test_recovery_after_failure_keeps_independent_authority_and_retention_truth(): void
    {
        $this->hubFails = true;
        self::assertSame(NoticePublicationStatus::FAILED, $this->record($this->operation()));
        $before = $this->recover();
        self::assertCount(1, $before['notices']);
        $this->agent = $this->agent->revokePermission($this->read->getId(), $this->time);
        $this->saveAgent();
        $this->newReadRequest();
        try {
            $this->recover();
            self::fail('Publication failure grants no recovery lease');
        } catch (NoticeReadFailed) {
            self::assertCount(1, $this->updates);
        }

        $this->agent = $this->agent->grantPermission($this->read->getId(), $this->time);
        $this->saveAgent();
        $this->access = NoticeAccess::create([], [], new NoticeRecoveryIdentity('reader', 'revision-2', true));
        $this->newReadRequest();
        self::assertSame([], $this->recover()['notices']);
        $this->access = NoticeAccess::create(['scope'], [], new NoticeRecoveryIdentity('reader', null));
        self::assertSame('uncertain', $this->recover()['status']);
        $this->connection->executeStatement('DELETE FROM fight_notice_relationships');
        $this->connection->executeStatement('DELETE FROM fight_notice_comparisons');
        $this->connection->executeStatement('DELETE FROM fight_notices');
        $this->connection->executeStatement('UPDATE fight_notice_state SET lost_through = 1');

        $this->access = NoticeAccess::create(['scope'], [], new NoticeRecoveryIdentity('reader', 'revision-3', true));
        self::assertSame('incomplete', $this->recover()['status']);
        self::assertSame(NoticePublicationStatus::NOT_AVAILABLE, $this->operation()->publish($this->identity()));
    }

    public function test_changed_consumer_topic_and_whole_audience_deny_each_new_attempt(): void
    {
        $operation = $this->operation();
        self::assertSame(NoticePublicationStatus::PUBLISHED, $this->record($operation));
        $this->permittedTopic = 'other-topic';
        self::assertSame(NoticePublicationStatus::DENIED, $operation->publish($this->identity()));
        $this->permittedTopic = 'catalog/tools';
        $this->permittedAudience = ['recipient-agent'];
        self::assertSame(NoticePublicationStatus::DENIED, $this->operation()->publish($this->identity()));
        self::assertCount(1, $this->updates);
        self::assertSame(3, $this->scopeCalls);
    }

    public function test_committed_dispatch_failure_is_not_rollback_and_needs_explicit_later_attempt(): void
    {
        $this->writer->eventDispatcher->addHandler(
            ClassName::underscore(NoticeRecorded::class),
            static function (): void {
                throw new RuntimeException('PRIVATE subscriber');
            }
        );
        $operation = $this->operation();
        try {
            $this->record($operation);
            self::fail('Committed dispatch failure must stay distinct');
        } catch (CommittedNoticeDispatchFailed) {
            self::assertCount(0, $this->updates);
            self::assertSame(0, $this->scopeCalls);
            self::assertCount(1, $this->recover()['notices']);
        }

        self::assertSame(NoticePublicationStatus::PUBLISHED, $operation->publish($this->identity()));
        self::assertCount(1, $this->updates);
    }

    public function test_disabled_mercure_requires_no_hub_while_retained_recovery_works(): void
    {
        // Neither publisher nor hub is resolved; retained operation is entirely independent.
        $this->writer->record();
        self::assertCount(1, $this->recover()['notices']);
        self::assertSame('complete', $this->recover()['status']);
        self::assertSame([], $this->updates);
        self::assertSame(0, $this->scopeCalls);
    }

    /** @phpstan-impure Creates fresh stateful hub and diagnostic callbacks per composition */
    private function operation(bool $public = true, bool $private = true): NoticePublication
    {
        $diagnostics = $this->createStub(NoticePublicationDiagnostics::class);
        $diagnostics->method('record')->willReturnCallback(function (Throwable $failure): void {
            $this->diagnostics[] = $failure;
            if ($this->diagnosticsFail) {
                throw new RuntimeException('PRIVATE diagnostic sink');
            }
        });
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            self::assertFalse($this->connection->isTransactionActive());
            $this->updates[] = $update;
            if ($this->hubFails) {
                throw new RuntimeException('PRIVATE hub credential');
            }

            return 'controlled-id';
        });

        return new NoticePublication(
            $this->queryBus,
            $this->policy,
            $diagnostics,
            $public ? new MercureHubPublisher($hub) : null,
            $private ? new PrivateMercureHubPublisher($hub) : null
        );
    }

    private function record(NoticePublication $operation): NoticePublicationStatus
    {
        return new RecordNoticeAndPublish($this->writer->commandBus, $operation)->record(
            RecordNotice::fromArray(NoticeFixture::data())
        );
    }

    private function identity(): GetNotice
    {
        return new GetNotice('scope', 'producer', 'notice-1');
    }

    /** @return array<string, mixed> */
    private function recover(): array
    {
        return $this->queryBus->fetch(new RecoverNotices(NoticeSelection::create(['scope'])))->toArray();
    }

    private function newReadRequest(): void
    {
        [$provider, $request] = $this->request();
        $readPolicy = $this->createStub(AgentNoticeReadPolicy::class);
        $readPolicy->method('accessFor')->willReturnCallback(fn(): NoticeAccess => $this->access);
        $admission = new AgentNoticeReadAdmission(
            $provider,
            $request,
            'recovery',
            [PermissionName::fromString('READ_NOTICES')],
            $readPolicy
        );
        $get = new GetNoticeHandler($admission, new DbalNoticeReader($this->connection));
        $recover = new RecoverNoticesHandler($admission, new NoticeRecovery(
            new DbalNoticeRecoveryReader($this->connection),
            new SodiumNoticeContinuationProtector(['fixture1' => $this->key], 'fixture1')
        ));
        $router = new InMemoryQueryRouter();
        $router->registerHandlers([$get::queryRegistration() => $get, $recover::queryRegistration() => $recover]);

        $this->queryBus = new RoutingQueryBus($router);
    }

    /** @return array{CurrentAgentPrincipalProvider, SignedAgentRequest, InMemoryAgentRequestNonceConsumer} */
    private function request(): array
    {
        $unit = new InMemoryUnitOfWork();
        $nonce = new InMemoryAgentRequestNonceConsumer($this->agentRepository, $unit);
        $request = new SignedAgentRequest(
            'POST',
            'notice.example',
            '/query',
            '',
            $this->time,
            'nonce-'.++$this->requests,
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
            new FixedClock($this->time),
            $nonce,
            $unit
        );

        return [$provider, $request, $nonce];
    }

    private function changeAuthority(string $change): void
    {
        match ($change) {
            'missing' => $this->agentRepository->restoreSnapshot([]),
            'revoked' => $this->agent = $this->agent->revoke($this->time),
            'permission' => $this->agent = $this->agent->revokePermission($this->publish->getId(), $this->time),
            'credential' => $this->agent = $this->agent->rotateRecoverableCredential(
                $this->agent->getCredentialId(),
                0,
                AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000999'),
                'encrypted:new', $this->time
            ),
            'expired' => $this->time = $this->time->modify('+300 seconds'),
            'resolver' => $this->authorityFailure = new RuntimeException('PRIVATE repository'),
            'definitions' => $this->permissionRepository->remove($this->publish),
            default => throw new LogicException('Invalid fixture')
        };
        if ($change !== 'missing') {
            $this->saveAgent();
        }
    }

    private function saveAgent(): void
    {
        $this->agentRepository->restoreSnapshot([$this->agent]);
    }

    private function permission(string $name, int $suffix): Permission
    {
        return Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-'.str_pad((string) $suffix, 12, '0', STR_PAD_LEFT)),
            PermissionName::fromString($name),
            PermissionTier::ADMIN_SAFE,
            $this->time
        );
    }
}
