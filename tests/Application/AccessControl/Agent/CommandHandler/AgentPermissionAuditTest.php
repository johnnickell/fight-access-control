<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\GrantPermissionToAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ReplaceAgentPermissionsHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RevokePermissionFromAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\PermissionAssignment\AgentPermissionAssignmentCoordinator;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RevokePermissionFromAgent;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentPermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GrantPermissionToAgentHandler::class)]
#[CoversClass(RevokePermissionFromAgentHandler::class)]
#[CoversClass(ReplaceAgentPermissionsHandler::class)]
#[CoversClass(AgentPermissionAssignmentCoordinator::class)]
#[CoversClass(AuditAgentPermissionOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentPermissionAuditTest extends TestCase
{
    private const string NOW = '2026-11-13T14:15:16.123456+00:00';

    public static function changes(): iterable
    {
        yield 'grant' => ['grant'];
        yield 'revoke' => ['revoke'];
        yield 'normalized complete replacement' => ['replace'];
        yield 'changed complete set' => ['swap'];
        yield 'empty replacement' => ['empty'];
    }

    #[DataProvider('changes')]
    public function test_one_handler_transaction_persists_truthful_changed_relationship_evidence(string $path): void
    {
        [$transaction, $agents, $permissions, $audit, $before, $held, $added] = $this->environment();
        $message = $this->message($path, $before, $held, $added);
        $events = new InMemoryEventDispatcher(static function ($event) use ($transaction, $audit): void {
            self::assertTrue($transaction->transactionCompleted);
            self::assertFalse($transaction->transactionActive);
            self::assertCount(1, $audit->all());
        });

        $this->execute($path, $message, $transaction, $agents, $permissions, $events, $audit);

        $after = $agents->getById($before->getId());
        self::assertInstanceOf(Agent::class, $after);
        self::assertSame($before->getPermissionAssignmentRevision() + 1, $after->getPermissionAssignmentRevision());
        self::assertSame($before->getCredentialId(), $after->getCredentialId());
        self::assertSame($before->getCredentialRevision(), $after->getCredentialRevision());
        self::assertSame($before->getState(), $after->getState());
        self::assertSame($before->getName(), $after->getName());
        self::assertSame(
            $before->getEncryptedHmacSharedSecretEnvelope(),
            $after->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame(1, $transaction->transactions);
        self::assertSame(1, $agents->permissionAssignmentReplacementCalls());
        self::assertCount(1, $events->events());
        self::assertCount(1, $audit->all());
        $evidence = $audit->all()[0];
        $payload = $evidence->toArray();
        self::assertSame($message->payload()->getActorId(), $evidence->actorId());
        self::assertSame($before->getId(), $evidence->getSubject()->getId());
        self::assertSame($message->id(), $evidence->getCorrelation()?->getMessageId());
        self::assertSame(self::NOW, $payload['occurred_at']);
        $grantedIds = [];
        if (in_array($path, ['grant', 'replace', 'swap'], true)) {
            $grantedIds = [$added->getId()->toString()];
        }

        $revokedIds = [];
        if (in_array($path, ['revoke', 'empty', 'swap'], true)) {
            $revokedIds = [$held->getId()->toString()];
        }

        self::assertSame([
            'before_revision'        => 2,
            'after_revision'         => 3,
            'granted_permission_ids' => $grantedIds,
            'revoked_permission_ids' => $revokedIds
        ], $payload['facts']);
        self::assertSame(match ($path) {
            'grant' => AuditAction::AGENT_PERMISSION_GRANTED,
            'revoke' => AuditAction::AGENT_PERMISSION_REVOKED,
            default => AuditAction::AGENT_PERMISSIONS_REPLACED
        }, $evidence->action());
        self::assertSame($payload, AuditEvidence::fromArray($payload)->toArray());
        self::assertStringNotContainsString(
            'synthetic-envelope-not-evidence',
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertSame(match ($path) {
            'grant', 'replace' => [$held->getId(), $added->getId()],
            'swap' => [$added->getId()],
            default => []
        }, $after->getPermissionIds());
    }

    #[DataProvider('changes')]
    public function test_explicitly_disabled_audit_retains_ordinary_mutation(string $path): void
    {
        [$transaction, $agents, $permissions, $audit, $before, $held, $added] = $this->environment();
        $events = new InMemoryEventDispatcher();

        $this->execute(
            $path,
            $this->message($path, $before, $held, $added),
            $transaction,
            $agents,
            $permissions,
            $events,
            null
        );

        self::assertSame(3, $agents->getById($before->getId())?->getPermissionAssignmentRevision());
        self::assertSame([], $audit->all());
        self::assertCount(1, $events->events());
    }

    #[DataProvider('changes')]
    public function test_audit_failure_rolls_back_both_staged_writes_and_preserves_primary_failure(string $path): void
    {
        [$transaction, $agents, $permissions, , $before, $held, $added] = $this->environment();
        $audit = new InMemoryAuditEvidenceRepository($transaction, failAfterSave: true);
        $events = new InMemoryEventDispatcher(static function ($event): void {
            self::assertInstanceOf(CommandFailedEvent::class, $event);

            throw new RuntimeException('secondary notification failure');
        });

        try {
            $this->execute(
                $path,
                $this->message($path, $before, $held, $added),
                $transaction,
                $agents,
                $permissions,
                $events,
                $audit
            );
            self::fail('Configured evidence failure cannot downgrade to unaudited success.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($audit->failure(), $runtimeException);
        }

        self::assertSame($before, $agents->getById($before->getId()));
        self::assertSame([], $audit->all());
        self::assertFalse($transaction->transactionCompleted);
        self::assertSame(1, $transaction->transactions);
    }

    #[DataProvider('changes')]
    public function test_post_commit_double_publication_failure_leaves_evidence_committed(string $path): void
    {
        [$transaction, $agents, $permissions, $audit, $before, $held, $added] = $this->environment();
        $primary = new RuntimeException('synthetic dependency diagnostic');
        $attempted = [];
        $events = new InMemoryEventDispatcher(static function ($event) use ($primary, &$attempted): void {
            $attempted[] = $event;
            if ($event instanceof CommandFailedEvent) {
                self::assertStringNotContainsString(
                    $primary->getMessage(),
                    json_encode($event->toArray(), JSON_THROW_ON_ERROR)
                );

                throw new RuntimeException('secondary');
            }

            throw $primary;
        });

        try {
            $this->execute(
                $path,
                $this->message($path, $before, $held, $added),
                $transaction,
                $agents,
                $permissions,
                $events,
                $audit
            );
            self::fail('Ordinary post-commit publication must still throw.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($primary, $runtimeException);
        }

        self::assertTrue($transaction->transactionCompleted);
        self::assertSame(3, $agents->getById($before->getId())?->getPermissionAssignmentRevision());
        self::assertCount(1, $audit->all());
        self::assertCount(2, $attempted);
    }

    public static function uncertainOutcomes(): iterable
    {
        foreach (self::changes() as [$path]) {
            yield $path.' uncommitted' => [$path, false];
            yield $path.' committed' => [$path, true];
        }
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_lost_commit_acknowledgement_is_rethrown_without_guessing_persisted_outcome(
        string $path,
        bool $committed
    ): void {
        [$transaction, $agents, $permissions, $audit, $before, $held, $added] = $this->environment();
        $uncertain = new RuntimeException('commit acknowledgement unavailable');
        $failure = static function () use ($uncertain): void {
            throw $uncertain;
        };
        if ($committed) {
            $transaction->afterCommit = $failure;
        } else {
            $transaction->beforeCommit = $failure;
        }

        $events = new InMemoryEventDispatcher();
        try {
            $this->execute(
                $path,
                $this->message($path, $before, $held, $added),
                $transaction,
                $agents,
                $permissions,
                $events,
                $audit
            );
            self::fail('Uncertain commit is not success.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($uncertain, $runtimeException);
        }

        self::assertSame($committed ? 3 : 2, $agents->getById($before->getId())?->getPermissionAssignmentRevision());
        self::assertCount($committed ? 1 : 0, $audit->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
    }

    public function test_validated_no_ops_have_no_writes_revision_advance_success_or_evidence(): void
    {
        foreach (['grant', 'revoke', 'replace'] as $path) {
            [$transaction, $agents, $permissions, $audit, $before, $held, $added] = $this->environment();
            $actor = UserId::generate();
            $command = match ($path) {
                'grant' => new GrantPermissionToAgent($actor, $before->getId(), $held->getId()),
                'revoke' => new RevokePermissionFromAgent($actor, $before->getId(), $added->getId()),
                'replace' => new ReplaceAgentPermissions(
                    $actor,
                    $before->getId(),
                    2,
                    [$held->getId(), $held->getId()]
                )
            };
            $events = new InMemoryEventDispatcher();

            $this->execute(
                $path,
                CommandMessage::create($command),
                $transaction,
                $agents,
                $permissions,
                $events,
                $audit
            );

            self::assertSame($before, $agents->getById($before->getId()));
            self::assertSame(0, $agents->permissionAssignmentReplacementCalls());
            self::assertSame([], $audit->all());
            self::assertSame([], $events->events());
        }
    }

    public function test_stale_identical_set_is_rejected_without_mutation_or_evidence(): void
    {
        [$transaction, $agents, $permissions, $audit, $before, $held] = $this->environment();
        $message = CommandMessage::create(new ReplaceAgentPermissions(
            UserId::generate(),
            $before->getId(),
            1,
            [$held->getId()]
        ));
        try {
            $this->execute(
                'replace',
                $message,
                $transaction,
                $agents,
                $permissions,
                new InMemoryEventDispatcher(),
                $audit
            );
            self::fail('Stale intent cannot become no-op success.');
        } catch (PreconditionFailed) {
            self::assertSame($before, $agents->getById($before->getId()));
        }

        self::assertSame([], $audit->all());
    }

    #[DataProvider('changes')]
    public function test_final_assignment_cas_loss_produces_no_committed_evidence(string $path): void
    {
        [$transaction, , $permissions, $audit, $before, $held, $added] = $this->environment();
        $agents = new InMemoryAgentRepository($transaction, replacePermissionAssignmentsSucceeds: false);
        $agents->add($before);

        try {
            $this->execute(
                $path,
                $this->message($path, $before, $held, $added),
                $transaction,
                $agents,
                $permissions,
                new InMemoryEventDispatcher(),
                $audit
            );
            self::fail('Final CAS loss cannot create evidence.');
        } catch (StateConflict) {
            self::assertSame($before, $agents->getById($before->getId()));
        }

        self::assertSame([], $audit->all());
    }

    public static function referenceRaces(): iterable
    {
        foreach (['grant', 'replace', 'swap'] as $path) {
            yield $path.' removed reference' => [$path, false];
            yield $path.' protected promotion' => [$path, true];
        }
    }

    #[DataProvider('referenceRaces')]
    public function test_final_tier_or_reference_loss_keeps_assignment_and_evidence_absent(
        string $path,
        bool $promoted
    ): void {
        [$transaction, , $permissions, $audit, $before, $held, $added] = $this->environment();
        $agents = new InMemoryAgentRepository(
            $transaction,
            beforeReplacePermissionAssignments: static function () use ($transaction, $added, $promoted): void {
                $references = $transaction->authorizationReferenceState();
                self::assertTrue($references->isReferenceFenceHeld());
                if ($promoted) {
                    $references->addPermission(Permission::defineManaged(
                        $added->getId(),
                        $added->getName(),
                        PermissionTier::SUPER_ADMIN_ONLY,
                        new DateTimeImmutable(self::NOW)
                    ));
                } else {
                    $references->removePermission($added->getId());
                }
            }
        );
        $agents->add($before);
        try {
            $this->execute(
                $path,
                $this->message($path, $before, $held, $added),
                $transaction,
                $agents,
                $permissions,
                new InMemoryEventDispatcher(),
                $audit
            );
            self::fail('Final definition loss cannot commit proposed relationships or evidence.');
        } catch (StateConflict) {
            self::assertSame($before, $agents->getById($before->getId()));
        }

        self::assertSame([], $audit->all());
    }

    #[DataProvider('changes')]
    public function test_complete_state_cas_loss_preserves_a_concurrent_name_winner(string $path): void
    {
        [$transaction, , $permissions, $audit, $before, $held, $added] = $this->environment();
        $winner = $before->rename(AgentName::fromString('Concurrent name winner'), $this->now());
        $agents = null;
        $agents = new InMemoryAgentRepository(
            $transaction,
            beforeReplacePermissionAssignments: static function () use (&$agents, $winner): void {
                $agents->restoreSnapshot([$winner]);
            }
        );
        $agents->add($before);
        try {
            $this->execute(
                $path,
                $this->message($path, $before, $held, $added),
                $transaction,
                $agents,
                $permissions,
                new InMemoryEventDispatcher(),
                $audit
            );
            self::fail('A stale assignment write cannot overwrite another writer.');
        } catch (StateConflict) {
            self::assertSame($winner, $agents->getById($before->getId()));
        }

        self::assertSame([], $audit->all());
    }

    private function environment(): array
    {
        $transaction = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($transaction);
        $permissions = new InMemoryPermissionRepository($transaction);
        $held = Permission::define(PermissionId::generate(), PermissionName::fromString('CONTENT_READ'), $this->now());
        $added = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('CONTENT_WRITE'),
            $this->now()
        );
        $permissions->add($held);
        $permissions->add($added);

        $before = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Permission audit fixture'),
            AgentCredentialId::generate(),
            'synthetic-envelope-not-evidence',
            $this->now()
        )->grantPermission($held->getId(), $this->now());
        $agents->add($before);

        return [
            $transaction,
            $agents,
            $permissions,
            new InMemoryAuditEvidenceRepository($transaction),
            $before,
            $held,
            $added
        ];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function message(string $path, Agent $before, Permission $held, Permission $added): CommandMessage
    {
        $actor = UserId::generate();
        $command = match ($path) {
            'grant' => new GrantPermissionToAgent($actor, $before->getId(), $added->getId()),
            'revoke' => new RevokePermissionFromAgent($actor, $before->getId(), $held->getId()),
            'replace' => new ReplaceAgentPermissions(
                $actor,
                $before->getId(),
                2,
                [$held->getId(), $added->getId(), $held->getId()]
            ),
            'swap' => new ReplaceAgentPermissions($actor, $before->getId(), 2, [$added->getId(), $added->getId()]),
            'empty' => new ReplaceAgentPermissions($actor, $before->getId(), 2, [])
        };

        return CommandMessage::create($command);
    }

    private function execute(
        string $path,
        CommandMessage $message,
        InMemoryUnitOfWork $transaction,
        InMemoryAgentRepository $agents,
        InMemoryPermissionRepository $permissions,
        InMemoryEventDispatcher $events,
        ?AuditEvidenceRepository $audit
    ): void {
        $handler = match ($path) {
            'grant' => new GrantPermissionToAgentHandler(
                $agents,
                $permissions,
                new FixedClock(self::NOW),
                $transaction,
                $events,
                $audit
            ),
            'revoke' => new RevokePermissionFromAgentHandler(
                $agents,
                $permissions,
                new FixedClock(self::NOW),
                $transaction,
                $events,
                $audit
            ),
            default => new ReplaceAgentPermissionsHandler(
                $agents,
                $permissions,
                new FixedClock(self::NOW),
                $transaction,
                $events,
                $audit
            )
        };
        $handler->handle($message);
    }
}
