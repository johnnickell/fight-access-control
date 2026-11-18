<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationContext;
use Fight\AccessControl\Application\AccessControl\Agent\Continuation\AgentContinuationHandoff;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeRemovalAdmission;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRepository;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeSchema;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Application\Mcp\Notice\CommandHandler\RecordNoticeHandler;
use Fight\Common\Application\Mcp\Notice\CommandHandler\RemoveNoticesHandler;
use Fight\Common\Application\Mcp\Notice\Exception\CommittedNoticeDispatchFailed;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeConflict;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeRejected;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeRemovalRejected;
use Fight\Common\Application\Mcp\Notice\Exception\NoticeStorageFailed;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecorder;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRemover;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Domain\Mcp\Notice\Command\RecordNotice;
use Fight\Common\Domain\Mcp\Notice\Command\RemoveNotices;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeRemovalSelection;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

#[CoversClass(AgentNoticeAdmission::class)]
#[CoversClass(AgentNoticeAuthority::class)]
#[CoversClass(AgentNoticeRemovalAdmission::class)]
final class AgentNoticeAdmissionTest extends TestCase
{
    private ?Agent $agent = null;

    /** @var list<Permission> */
    private array $definitions;

    private Connection $connection;

    private DbalNoticeTransaction $transaction;

    private ControlledNoticePolicy $policy;

    private AgentNoticeAuthority $recordingAuthority;

    private AgentNoticeAuthority $removalAuthority;

    private RoutingCommandBus $commandBus;

    /** @var list<Event> */
    private array $events = [];

    private ?Closure $beforeAuthority = null;

    private ?Throwable $dispatchFailure = null;

    private CurrentAgentPrincipalProvider $requestProvider;

    private SignedAgentRequest $request;

    private InMemoryAgentRequestNonceConsumer $nonceConsumer;

    private FixedHmacSignedAgentRequestVerifier $verifier;

    private AgentContinuationBinding $binding;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->compose();
    }

    public function test_that_routed_recording_deduplicates_conflicts_removes_and_rerecords_without_extra_events(): void
    {
        $notice = $this->notice();
        $this->record($notice);
        $original = $this->rows();
        self::assertCount(1, $this->events);
        $this->record($notice);
        self::assertSame($original, $this->rows());
        self::assertCount(1, $this->events);
        $this->failsAs(NoticeConflict::class, fn() => $this->record($this->notice(type: 'other')));
        self::assertSame($original, $this->rows());
        $this->record($this->notice('survivor'));
        $survivor = $this->rows()[1];
        $selection = $this->selection(['one' => 1]);
        $this->commandBus->execute(new RemoveNotices($selection));
        self::assertSame([$survivor], $this->rows());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT lost_through FROM fight_notice_state'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notice_relationships'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notice_comparisons'));
        self::assertCount(2, $this->events);
        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->commandBus->execute(new RemoveNotices($selection)));
        $this->record($notice);
        self::assertSame(3, (int) $this->rows()[1]['position']);
        self::assertCount(3, $this->events);
        self::assertSame($notice->comparison(), $this->connection->fetchOne(
            'SELECT payload FROM fight_notice_comparisons WHERE position = 3'
        ));
        self::assertSame($notice->relationships()->toString(), $this->connection->fetchOne(
            'SELECT payload FROM fight_notice_relationships WHERE position = 3'
        ));
        self::assertSame(1, $this->nonceConsumer->consumptionCalls());
        self::assertSame(1, $this->verifier->calls());
    }

    public function test_that_denied_equal_and_conflicting_retries_are_indistinguishable_before_identity_lookup(): void
    {
        $this->record($this->notice());
        $before = $this->rows();
        $this->policy->recording = false;
        foreach ([$this->notice(), $this->notice(type: 'other'), $this->notice('missing')] as $notice) {
            $this->failsAs(NoticeRejected::class, fn() => $this->record($notice));
        }

        self::assertSame($before, $this->rows());
        self::assertCount(1, $this->events);
        // A broken identity table cannot replace denial: admission precedes the attempted lookup.
        $this->connection->executeStatement('ALTER TABLE fight_notices RENAME TO hidden_notices');
        $this->failsAs(NoticeRejected::class, fn() => $this->record($this->notice()));
    }

    public function test_that_recording_and_removal_permissions_and_consumer_policy_are_independent(): void
    {
        $this->agent = $this->agent->revokePermission($this->permission('REMOVE_NOTICES', 2)->getId(), $this->now());
        $snapshot = $this->requestProvider->resolve($this->request, 'cached');
        self::assertTrue($snapshot->hasPermission(PermissionName::fromString('REMOVE_NOTICES')));
        $this->record($this->notice());
        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->remove(['one' => 1]));
        self::assertSame(['record:one'], $this->policy->calls);
        $this->agent = $this->agent->grantPermission($this->permission('REMOVE_NOTICES', 2)->getId(), $this->now())
            ->revokePermission($this->permission('RECORD_NOTICES', 1)->getId(), $this->now());
        $this->failsAs(NoticeRejected::class, fn() => $this->record($this->notice('two')));
        $this->policy->removal = false;
        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->remove(['one' => 1]));
        $this->policy->removal = true;
        $this->remove(['one' => 1]);
        self::assertSame([], $this->rows());
        self::assertCount(1, $this->events);
        self::assertSame($snapshot, $this->requestProvider->resolve($this->request, 'unchanged'));
        self::assertSame(1, $this->nonceConsumer->consumptionCalls());
    }

    public function test_that_all_configured_direct_permissions_are_required_without_role_or_selector_fallback(): void
    {
        $this->bindAuthorities($this->binding, [
            PermissionName::fromString('RECORD_NOTICES'), PermissionName::fromString('REMOVE_NOTICES')
        ]);
        $this->wireBus();
        $this->record($this->notice());
        $this->agent = $this->agent->revokePermission($this->permission('REMOVE_NOTICES', 2)->getId(), $this->now());
        $this->failsAs(NoticeRejected::class, fn() => $this->record($this->notice('two')));
        self::assertCount(1, $this->rows());
    }

    public function test_that_all_target_and_retained_preflight_precede_any_deletion(): void
    {
        $this->record($this->notice());
        $this->record($this->notice('two'));
        $before = $this->rows();
        $this->policy->deniedRetainedId = 'two';
        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->remove(['one' => 1, 'two' => 2]));
        self::assertSame(['selection', 'retained:one', 'retained:two'], array_slice($this->policy->calls, -3));
        self::assertSame($before, $this->rows());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT lost_through FROM fight_notice_state'));
        $this->policy->deniedRetainedId = null;
        foreach ([['one' => 1, 'missing' => 2], ['one' => 1, 'two' => 99]] as $targets) {
            $this->failsAs(NoticeRemovalRejected::class, fn() => $this->remove($targets));
            self::assertSame($before, $this->rows());
        }

        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->commandBus->execute(new RemoveNotices(
            NoticeRemovalSelection::fromArray([
                'version'   => 1,
                'namespace' => str_repeat('0', 32),
                'targets'   => $this->selection(['one' => 1])->targets()
            ])
        )));
        self::assertSame($before, $this->rows());
        $this->policy->removal = false;
        $this->connection->executeStatement('ALTER TABLE fight_notices RENAME TO hidden_notices');
        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->remove(['missing' => 10]));
    }

    public function test_that_retained_admission_rechecks_authority_instead_of_reusing_selection_allow(): void
    {
        $this->record($this->notice());
        $this->record($this->notice('two'));
        $this->policy->beforePolicy = function (): void {
            if (end($this->policy->calls) === 'retained:one') {
                $this->agent = $this->agent->revokePermission(
                    $this->permission('REMOVE_NOTICES', 2)->getId(),
                    $this->now()
                );
            }
        };
        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->remove(['one' => 1, 'two' => 2]));
        self::assertCount(2, $this->rows());
        self::assertSame(['selection', 'retained:one'], array_slice($this->policy->calls, -2));
    }

    #[DataProvider('changedAuthority')]
    public function test_that_current_bound_authority_is_checked_under_the_actual_storage_lock(string $change): void
    {
        $this->beforeAuthority = function () use ($change): void {
            $this->transaction->requireOwnedTransaction();
            $this->beforeAuthority = null;
            match ($change) {
                'missing' => $this->agent = null,
                'revoked' => $this->agent = $this->agent->revoke($this->now()),
                'permission' => $this->agent = $this->agent->revokePermission(
                    $this->permission('RECORD_NOTICES', 1)->getId(),
                    $this->now()
                ),
                'credential' => $this->agent = $this->agent->rotateRecoverableCredential(
                    $this->agent->getCredentialId(),
                    0,
                    AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000299'),
                    'encrypted:new',
                    $this->now()
                ),
                'expired' => $this->clock = new FixedClock($this->now()->modify('+300 seconds')),
                default => throw new LogicException('Unknown fixture.')
            };
        };
        // The clock port delegates to the current controlled clock, just as repositories use current state.
        $this->failsAs(NoticeRejected::class, fn() => $this->record($this->notice()));
        self::assertSame([], $this->rows());
        self::assertSame([], $this->events);
        self::assertSame(1, $this->nonceConsumer->consumptionCalls());
    }

    /** @return iterable<string, array{string}> */
    public static function changedAuthority(): iterable
    {
        foreach (['missing', 'revoked', 'permission', 'credential', 'expired'] as $change) {
            yield $change => [$change];
        }
    }

    public function test_that_consumer_inputs_never_supply_grants_and_each_protected_field_is_checked(): void
    {
        foreach (
            ['scope', 'producer', 'topic', 'type', 'metadata', 'subject', 'recipients', 'author',
            'command', 'event', 'action'] as $field
        ) {
            $data = $this->notice()->toArray();
            if (array_key_exists($field, $data)) {
                $data[$field] = $field === 'metadata' ? ['private/body' => 'concealed'] : 'foreign';
            } else {
                $relationships = $this->notice()->relationships()->toArray();
                $relationships[$field] = $field === 'recipients' ? ['foreign'] : 'foreign';
                $data['relationships'] = $relationships;
            }

            $this->failsAs(NoticeRejected::class, fn() => $this->record(Notice::fromArray($data)));
        }

        $this->failsAs(NoticeRemovalRejected::class, fn() => $this->commandBus->execute(new RemoveNotices(
            NoticeRemovalSelection::fromArray([
                'version'   => 1,
                'namespace' => $this->selection(['one' => 1])->namespace(),
                'targets'   => [['scope' => 'foreign', 'producer' => 'producer', 'id' => 'one', 'position' => 1]]
            ])
        )));
        self::assertSame([], $this->rows());
    }

    public function test_that_operational_and_malformed_authority_faults_are_not_boolean_denial(): void
    {
        $private = new RuntimeException('Private repository detail.');
        $this->beforeAuthority = static function () use ($private): void {
            throw $private;
        };
        $this->failsAs(RuntimeException::class, fn() => $this->record($this->notice()), $private);
        $this->beforeAuthority = null;
        $this->definitions = [];
        $this->failsAs(ExactPermissionResolutionException::class, fn() => $this->record($this->notice()));
        $this->definitions = [$this->permission('RECORD_NOTICES', 1, PermissionTier::SUPER_ADMIN_ONLY),
            $this->permission('REMOVE_NOTICES', 2)];
        $this->failsAs(LogicException::class, fn() => $this->record($this->notice()));
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000299'),
            AgentName::fromString('Wrong identity'),
            $this->binding->getContext()->getCredentialId(),
            'encrypted:fixture',
            $this->now()
        );
        $this->failsAs(LogicException::class, fn() => $this->record($this->notice()));
        self::assertSame([], $this->rows());
    }

    public function test_that_policy_faults_propagate_once_and_leave_all_selected_rows_unchanged(): void
    {
        $this->record($this->notice());
        $failure = new RuntimeException('Private policy reference.');
        $this->policy->beforePolicy = static function () use ($failure): void {
            throw $failure;
        };
        $this->failsAs(RuntimeException::class, fn() => $this->record($this->notice()), $failure);
        $this->failsAs(RuntimeException::class, fn() => $this->remove(['one' => 1]), $failure);
        $this->policy->beforePolicy = function () use ($failure): void {
            if (end($this->policy->calls) === 'retained:one') {
                throw $failure;
            }
        };
        $this->failsAs(RuntimeException::class, fn() => $this->remove(['one' => 1]), $failure);
        self::assertCount(1, $this->rows());
        self::assertCount(1, $this->events);
    }

    public function test_that_storage_rollback_and_post_commit_publication_failure_retain_common_contracts(): void
    {
        $this->connection->executeStatement('DROP TABLE fight_notice_comparisons');
        $this->failsAs(NoticeStorageFailed::class, fn() => $this->record($this->notice()));
        self::assertSame([], $this->rows());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT last_position FROM fight_notice_state'));
        self::assertSame([], $this->events);
        $this->compose();
        $this->dispatchFailure = new RuntimeException('Private subscriber failure.');
        $this->failsAs(CommittedNoticeDispatchFailed::class, fn() => $this->record($this->notice()));
        self::assertCount(1, $this->rows());
        self::assertCount(1, $this->events);
        $this->record($this->notice());
        self::assertCount(1, $this->events);
    }

    #[DataProvider('commitFailures')]
    public function test_that_lost_commit_acknowledgement_does_not_invent_rollback_or_dispatch(
        string $mode,
        string $operation
    ): void {
        $this->compose(uncertain: true);
        self::assertInstanceOf(UncertainNoticeConnection::class, $this->connection);
        if ($operation === 'remove') {
            $this->record($this->notice());
            $this->events = [];
        }

        self::assertInstanceOf(UncertainNoticeConnection::class, $this->connection);
        $this->connection->failure = $mode;
        try {
            if ($operation === 'record') {
                $this->record($this->notice());
            } else {
                $this->remove(['one' => 1]);
            }

            self::fail('Uncertain acknowledgement must throw.');
        } catch (NoticeStorageFailed $noticeStorageFailed) {
            self::assertTrue($noticeStorageFailed->outcomeUncertain());
            self::assertSame('Notice storage failed.', $noticeStorageFailed->getMessage());
        }

        $expectedCount = (int) (($mode === 'after') === ($operation === 'record'));
        self::assertCount($expectedCount, $this->rows());
        self::assertSame([], $this->events);
        self::assertTrue($this->transaction->isClosed());
    }

    /** @return iterable<string, array{string, string}> */
    public static function commitFailures(): iterable
    {
        foreach (['record', 'remove'] as $operation) {
            foreach (['before', 'after'] as $mode) {
                yield $operation.'-'.$mode => [$mode, $operation];
            }
        }
    }

    public function test_that_deletion_fault_rolls_back_earlier_selected_deletion_and_loss_evidence(): void
    {
        $this->record($this->notice());
        $this->record($this->notice('two'));
        $before = $this->rows();
        $sql = 'CREATE TRIGGER refuse_second BEFORE DELETE ON fight_notices WHEN OLD.position = 2 ';
        $sql .= "BEGIN SELECT RAISE(ABORT, 'Private deletion failure'); END";
        $this->connection->executeStatement($sql);
        $this->failsAs(NoticeStorageFailed::class, fn() => $this->remove(['one' => 1, 'two' => 2]));
        self::assertSame($before, $this->rows());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT lost_through FROM fight_notice_state'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notice_relationships'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fight_notice_comparisons'));
        self::assertCount(2, $this->events);
    }

    public function test_that_invalid_or_empty_permission_configuration_cannot_enable_either_operation(): void
    {
        foreach (
            [[], ['RECORD_NOTICES'], [PermissionName::fromString('RECORD_NOTICES'),
            PermissionName::fromString('RECORD_NOTICES')]] as $requirements
        ) {
            $this->failsAs(InvalidArgumentException::class, fn(): AgentNoticeAuthority => new AgentNoticeAuthority(
                $this->binding,
                new InMemoryAgentRepository(),
                new InMemoryPermissionRepository(),
                $this->clock,
                $requirements
            ));
        }
    }

    #[DataProvider('waitingChanges')]
    public function test_that_worker_original_binding_is_rechecked_after_an_actual_sqlite_lock_wait(
        string $operation,
        string $change
    ): void {
        $database = tempnam(sys_get_temp_dir(), 'notice-wait-');
        $marker = $database.'.authority';
        $ready = $database.'.ready';
        $holder = null;
        try {
            $this->compose($database);
            if ($operation === 'remove') {
                $this->record($this->notice());
            }

            $context = $this->binding->getContext();
            $handoff = $this->createStub(AgentContinuationHandoff::class);
            $handoff->method('verify')->willReturn(new AgentContinuationContext(
                $context->getAgentId(),
                $context->getCredentialId(),
                $context->getCredentialRevision(),
                $context->getAuthenticatedAt()
            ));
            $workerBinding = AgentContinuationBinding::fromHandoff($handoff);
            self::assertEquals($context, $workerBinding->getContext());
            $this->bindAuthorities($workerBinding);
            $this->wireBus();
            $script = '$p = new PDO("sqlite:".$argv[1]); $p->exec("BEGIN IMMEDIATE");';
            $script .= 'file_put_contents($argv[3], "locked"); usleep(400000);';
            $script .= 'file_put_contents($argv[2], "revoked"); $p->exec("COMMIT");';
            $holder = new Process([PHP_BINARY, '-r', $script, $database, $marker, $ready]);
            $holder->start();
            $deadline = microtime(true) + 5;
            while (!is_file($ready) && $holder->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }

            self::assertFileExists($ready);
            self::assertFileDoesNotExist($marker);
            $this->beforeAuthority = function () use ($marker, $operation, $change): void {
                $this->transaction->requireOwnedTransaction();
                self::assertSame('revoked', file_get_contents($marker));
                if ($change === 'credential') {
                    $this->agent = $this->agent->rotateRecoverableCredential(
                        $this->agent->getCredentialId(),
                        0,
                        AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000299'),
                        'encrypted:new',
                        $this->now()
                    );
                } elseif ($change === 'scope') {
                    $this->policy->recording = false;
                    $this->policy->removal = false;
                } else {
                    $permission = $this->permission('RECORD_NOTICES', 1);
                    if ($operation === 'remove') {
                        $permission = $this->permission('REMOVE_NOTICES', 2);
                    }

                    $this->agent = $this->agent->revokePermission($permission->getId(), $this->now());
                }
            };
            if ($operation === 'record') {
                $this->failsAs(NoticeRejected::class, fn() => $this->record($this->notice()));
            } else {
                $this->failsAs(NoticeRemovalRejected::class, fn() => $this->remove(['one' => 1]));
            }

            self::assertSame(0, $holder->wait());
            self::assertCount($operation === 'record' ? 0 : 1, $this->rows());
            self::assertSame(1, $this->nonceConsumer->consumptionCalls());
            self::assertSame(1, $this->verifier->calls());
        } finally {
            $holder?->stop();
            $this->connection->close();
            foreach ([$database, $marker, $ready] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function waitingChanges(): iterable
    {
        foreach (['record', 'remove'] as $operation) {
            foreach (['permission', 'credential', 'scope'] as $change) {
                yield $operation.'-'.$change => [$operation, $change];
            }
        }
    }

    private function compose(?string $database = null, bool $uncertain = false): void
    {
        $this->agent = Agent::provision(
            AgentId::fromString('018f0000-0000-7000-8000-000000000201'),
            AgentName::fromString('Notice fixture'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000202'),
            'encrypted:fixture',
            $this->now()
        )->grantPermission($this->permission('RECORD_NOTICES', 1)->getId(), $this->now())
            ->grantPermission($this->permission('REMOVE_NOTICES', 2)->getId(), $this->now());
        $this->definitions = [$this->permission('RECORD_NOTICES', 1), $this->permission('REMOVE_NOTICES', 2)];
        $this->clock = new FixedClock($this->now());
        $agentRepository = new InMemoryAgentRepository();
        $agentRepository->add($this->agent);

        $permissionRepository = new InMemoryPermissionRepository();
        foreach ($this->definitions as $permission) {
            $permissionRepository->add($permission);
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $this->nonceConsumer = new InMemoryAgentRequestNonceConsumer($agentRepository, $unitOfWork);
        $this->request = new SignedAgentRequest(
            'POST',
            'notice.example',
            '/record',
            '',
            $this->now(),
            'fixture-nonce',
            $this->agent->getCredentialId(),
            'HMAC-SHA256',
            'fixture-signature',
            null,
            ''
        );
        $this->verifier = new FixedHmacSignedAgentRequestVerifier($this->request, 'fixture');
        $this->requestProvider = new CurrentAgentPrincipalProvider(
            $agentRepository,
            $permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            $this->verifier,
            $this->clock,
            $this->nonceConsumer,
            $unitOfWork
        );
        $this->binding = AgentContinuationBinding::fromRequest($this->requestProvider, $this->request, 'notice');
        $parameters = ['driver' => 'pdo_sqlite', 'memory' => true];
        if ($database !== null) {
            $parameters = ['driver' => 'pdo_sqlite', 'path' => $database];
        }

        if ($uncertain) {
            $parameters['wrapperClass'] = UncertainNoticeConnection::class;
        }

        $this->connection = DriverManager::getConnection($parameters);
        $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
        new DbalNoticeSchema()->install($this->connection);
        $this->transaction = new DbalNoticeTransaction($this->connection);
        $this->policy = new ControlledNoticePolicy();
        $this->events = [];
        $this->bindAuthorities($this->binding);
        $this->wireBus();
    }

    /** @phpstan-param list<PermissionName>|null $recordingRequirements */
    private function bindAuthorities(AgentContinuationBinding $binding, ?array $recordingRequirements = null): void
    {
        $agentRepository = $this->createStub(AgentRepository::class);
        $contract = new InMemoryAgentRepository()->getOperationContract();
        $agentRepository->method('getOperationContract')->willReturn($contract);
        $agentRepository->method('getById')->willReturnCallback(function (): ?Agent {
            $this->transaction->requireOwnedTransaction();
            ($this->beforeAuthority ?? static function (): void {
            })();

            return $this->agent;
        });
        $permissionRepository = new InMemoryPermissionRepository(getByIdsResult: fn(array $ids): array => array_values(
            array_filter($this->definitions, static fn(Permission $permission): bool => in_array(
                $permission->getId(),
                $ids
            ))
        ));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn(): DateTimeImmutable => $this->clock->now());
        $this->recordingAuthority = new AgentNoticeAuthority(
            $binding,
            $agentRepository,
            $permissionRepository,
            $clock,
            $recordingRequirements ?? [PermissionName::fromString('RECORD_NOTICES')]
        );
        $this->removalAuthority = new AgentNoticeAuthority(
            $binding,
            $agentRepository,
            $permissionRepository,
            $clock,
            [PermissionName::fromString('REMOVE_NOTICES')]
        );
    }

    private function wireBus(): void
    {
        $repository = new DbalNoticeRepository($this->transaction);
        $eventDispatcher = $this->createStub(EventDispatcher::class);
        $eventDispatcher->method('trigger')->willReturnCallback(function (Event $event): void {
            self::assertFalse($this->connection->isTransactionActive());
            self::assertNotEmpty($this->rows());
            $this->events[] = $event;
            if ($this->dispatchFailure !== null) {
                throw $this->dispatchFailure;
            }
        });
        $recordHandler = new RecordNoticeHandler(new NoticeRecorder(
            $repository,
            $this->transaction,
            new AgentNoticeAdmission($this->recordingAuthority, $this->policy),
            $eventDispatcher
        ));
        $removeHandler = new RemoveNoticesHandler(new NoticeRemover(
            $repository,
            $this->transaction,
            new AgentNoticeRemovalAdmission($this->removalAuthority, $this->policy)
        ));
        $router = new InMemoryCommandRouter();
        $router->registerHandlers([
            $recordHandler::commandRegistration() => $recordHandler,
            $removeHandler::commandRegistration() => $removeHandler
        ]);
        $this->commandBus = new RoutingCommandBus($router);
    }

    private function record(Notice $notice): void
    {
        $this->commandBus->execute(new RecordNotice($notice));
    }

    /** @phpstan-param array<string, int> $targets */
    private function remove(array $targets): void
    {
        $this->commandBus->execute(new RemoveNotices($this->selection($targets)));
    }

    /** @phpstan-param array<string, int> $targets */
    private function selection(array $targets): NoticeRemovalSelection
    {
        $selected = [];
        foreach ($targets as $id => $position) {
            $selected[] = ['scope' => 'project', 'producer' => 'producer', 'id' => $id, 'position' => $position];
        }

        return NoticeRemovalSelection::fromArray([
            'version'   => 1,
            'namespace' => $this->connection->fetchOne('SELECT namespace FROM fight_notice_state'),
            'targets'   => $selected
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM fight_notices ORDER BY position');
    }

    private function notice(string $id = 'one', string $type = 'changed'): Notice
    {
        return Notice::fromArray([
            'version'       => 1,
            'id'            => $id,
            'producer'      => 'producer',
            'scope'         => 'project',
            'topic'         => 'updates',
            'type'          => $type,
            'occurred_at'   => null,
            'metadata'      => ['safe/state' => 'ready'],
            'relationships' => [
                'subject'    => 'subject',
                'recipients' => ['recipient'],
                'author'     => 'author',
                'command'    => 'command',
                'event'      => 'event',
                'action'     => 'action'
            ]
        ]);
    }

    private function permission(
        string $name,
        int $suffix,
        PermissionTier $tier = PermissionTier::ADMIN_SAFE
    ): Permission {
        return Permission::defineManaged(
            PermissionId::fromString('018f0000-0000-7000-8000-00000000030'.$suffix),
            PermissionName::fromString($name),
            $tier,
            $this->now()
        );
    }

    /**
     * @phpstan-param class-string<Throwable> $class
     * @phpstan-param Closure(): mixed $action
     */
    private function failsAs(string $class, Closure $action, ?Throwable $expected = null): void
    {
        try {
            $action();
            self::fail('The protected operation must throw '.$class);
        } catch (Throwable $throwable) {
            self::assertSame($class, $throwable::class);
            if ($expected !== null) {
                self::assertSame($expected, $throwable);
            }

            if ($throwable instanceof NoticeRejected || $throwable instanceof NoticeRemovalRejected) {
                self::assertNull($throwable->getPrevious());
                self::assertSame(0, $throwable->getCode());
                self::assertStringNotContainsString('one', $throwable->getMessage());
            }
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10T12:00:00+00:00');
    }
}
