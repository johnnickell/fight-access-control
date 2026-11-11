<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use Fiber;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\MappedAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use RuntimeException;
use Throwable;

/** Modeled storage checkpoints contain bytes, never retained aggregates, messages or returned Events */
final class InMemoryAuditTransactionBinding implements AuditTransactionBinding
{
    private readonly InMemoryUnitOfWork $unitOfWork;

    private readonly InMemoryUnitOfWork $auditUnitOfWork;

    private readonly InMemoryUserRepository $userRepository;

    private readonly MappedAuditEvidenceRepository $auditEvidenceRepository;

    private readonly CommandMessage $commandMessage;

    private readonly RuntimeException $injectedFailure;

    private readonly RuntimeException $notificationFailure;

    private string $userBytes;

    /** @var list<string> */
    private array $auditBytes = [];

    /** @var list<string> */
    private array $winnerAuditBytes = [];

    public function __construct(private readonly string $fault = 'none')
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $misbound = in_array($fault, ['orphan', 'missing'], true);
        $this->auditUnitOfWork = $misbound ? new InMemoryUnitOfWork() : $this->unitOfWork;
        $this->injectedFailure = new RuntimeException('Synthetic conformance fault.');
        $this->notificationFailure = new RuntimeException('Synthetic notification fault.');
        $state = new InMemoryUserRepositoryState();
        $this->userRepository = new InMemoryUserRepository(
            $this->unitOfWork,
            state: $state,
            beforeReplaceLifecycleState: $fault === 'concurrent' ? function () use ($state): void {
                $winnerUnitOfWork = new InMemoryUnitOfWork();
                $winnerRepository = new InMemoryUserRepository($winnerUnitOfWork, state: $state);
                $winnerAuditRepository = new MappedAuditEvidenceRepository($winnerUnitOfWork);
                $this->handler($winnerRepository, $winnerAuditRepository, $winnerUnitOfWork)->handle(
                    CommandMessage::create(new EnableUser(UserId::generate(), $this->reopenUser()->getId()))
                );
                $this->userBytes = StoredAuditUser::encode($winnerRepository->all()[0]);
                $this->winnerAuditBytes = self::encodeEvidence($winnerAuditRepository->all());
            } : null
        );
        $user = UserFixture::withState('audit-conformance@example.test', UserState::DISABLED);
        $this->userRepository->add($user);
        $this->userBytes = StoredAuditUser::encode($user);
        $this->commandMessage = CommandMessage::create(new EnableUser(UserId::generate(), $user->getId()));
        $this->auditEvidenceRepository = new MappedAuditEvidenceRepository(
            $this->auditUnitOfWork,
            failAfterSave: $fault === 'audit-after',
            failBeforeSave: $fault === 'audit-before'
        );
        // These are independent storage checkpoints, not a second combined commit or business-aware UoW.
        $this->unitOfWork->afterCommit = function (): void {
            $this->userBytes = StoredAuditUser::encode($this->userRepository->all()[0]);
            if ($this->auditUnitOfWork === $this->unitOfWork) {
                $this->auditBytes = self::encodeEvidence($this->auditEvidenceRepository->all());
            }

            if ($this->fault === 'uncertain-committed') {
                throw $this->injectedFailure;
            }
        };
        if ($misbound) {
            $this->auditUnitOfWork->afterCommit = function (): void {
                $this->auditBytes = self::encodeEvidence($this->auditEvidenceRepository->all());
            };
        }

        $this->unitOfWork->beforeCommit = function (): void {
            if ($this->fault === 'concurrent-final') {
                throw new StateConflict('Synthetic final concurrency loss.');
            }

            if (in_array($this->fault, ['rollback', 'uncertain-uncommitted', 'orphan'], true)) {
                throw $this->injectedFailure;
            }
        };
    }

    /** @param list<AuditEvidence> $evidence
     * @return list<string>
     */
    public static function encodeEvidence(array $evidence): array
    {
        return array_map(
            static fn (AuditEvidence $record): string => json_encode($record->toArray(), JSON_THROW_ON_ERROR),
            $evidence
        );
    }

    public static function decodeEvidence(string $bytes): AuditEvidence
    {
        /** @var array<array-key, mixed> $data */
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        return AuditEvidence::fromArray($data);
    }

    public function execute(): void
    {
        $foreign = null;
        if ($this->auditUnitOfWork !== $this->unitOfWork) {
            $foreign = new Fiber(function (): void {
                $this->auditUnitOfWork->commitTransactional(function (): void {
                    // Deliberately leave a different enclosing transaction active while the handler runs.
                    Fiber::suspend();
                    if ($this->fault === 'missing') {
                        throw $this->injectedFailure;
                    }
                });
            });
            $foreign->start();
        }

        try {
            $this->handler($this->userRepository, $this->auditEvidenceRepository, $this->unitOfWork)->handle(
                $this->commandMessage
            );
        } finally {
            // Observe backing state after completion even on failure: a broken rollback must remain visible.
            $this->userBytes = StoredAuditUser::encode($this->userRepository->all()[0]);
            if ($foreign !== null) {
                try {
                    $foreign->resume();
                } catch (RuntimeException $exception) {
                    if ($exception !== $this->injectedFailure || $this->fault !== 'missing') {
                        throw $exception;
                    }
                }
            }

            $this->auditBytes = array_merge(
                $this->winnerAuditBytes,
                self::encodeEvidence($this->auditEvidenceRepository->all())
            );
        }
    }

    public function reopenUser(): User
    {
        return StoredAuditUser::decode($this->userBytes);
    }

    /** @return list<AuditEvidence> */
    public function reopenEvidence(): array
    {
        return array_map(self::decodeEvidence(...), $this->auditBytes);
    }

    /** @return array{object, object, object} */
    public function identities(): array
    {
        return [
            $this->unitOfWork,
            $this->userRepository->transactionIdentity() ?? throw new RuntimeException('Missing business binding.'),
            $this->auditEvidenceRepository->transactionIdentity()
        ];
    }

    public function primaryFailure(): Throwable
    {
        return $this->auditEvidenceRepository->failure() ?? $this->injectedFailure;
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        MappedAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork
    ): EnableUserHandler {
        $eventDispatcher = new InMemoryEventDispatcher(function (Event $event): void {
            if ($event instanceof CommandFailedEvent) {
                throw $this->notificationFailure;
            }

            if (
                in_array($this->fault, [
                'publication', 'audit-before', 'audit-after', 'rollback',
                'uncertain-committed', 'uncertain-uncommitted', 'orphan'
                ], true)
            ) {
                throw $this->injectedFailure;
            }
        });

        return new EnableUserHandler(
            $userRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher,
            new FixedClock('2030-01-02T03:04:05.123456-04:00')
        );
    }
}
