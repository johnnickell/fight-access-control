<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\StateConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Bind unchanged assertions to isolated real storage and actual fault/commit boundaries in consumers */
#[CoversClass(EnableUserHandler::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(User::class)]
abstract class AuditTransactionConformance extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rollbackFaults(): iterable
    {
        foreach (['audit-before', 'audit-after', 'rollback', 'uncertain-uncommitted'] as $fault) {
            yield $fault => [$fault];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function committedFaults(): iterable
    {
        yield 'publisher and failure subscriber both fail' => ['publication'];
        yield 'lost commit acknowledgement after commit' => ['uncertain-committed'];
    }

    /** @return iterable<string, array{string, UserState, int}> */
    public static function concurrentFaults(): iterable
    {
        yield 'other connection wins before CAS' => ['concurrent', UserState::ACTIVE, 1];
        yield 'final validation loses after audit insertion' => ['concurrent-final', UserState::DISABLED, 0];
    }

    /** @return iterable<string, array{string, UserState, int}> */
    public static function wrongTransactions(): iterable
    {
        yield 'foreign active commit leaves orphan' => ['orphan', UserState::DISABLED, 1];
        yield 'foreign active rollback loses evidence' => ['missing', UserState::ACTIVE, 0];
    }

    public function test_confirmed_commit_has_shared_identity_and_reopened_state_and_evidence(): void
    {
        $binding = $this->binding();
        [$transaction, $business, $audit] = $binding->identities();
        self::assertSame($transaction, $business);
        self::assertSame($transaction, $audit);
        $before = $binding->reopenUser();
        $binding->execute();
        $after = $binding->reopenUser();
        self::assertNotSame($before, $after);
        self::assertSame(UserState::ACTIVE, $after->getState());
        $this->assertAtomicOutcome($binding);
        $evidence = $binding->reopenEvidence()[0];
        self::assertNotSame($evidence, $binding->reopenEvidence()[0]);
        self::assertSame(AuditUserRevisions::fromUser($before)->toArray(), $evidence->getFacts()->toArray()['before']);
        self::assertSame('2030-01-02T03:04:05.123456-04:00', $evidence->toArray()['occurred_at']);
        self::assertSame(AuditAction::USER_ENABLED, $evidence->action());
        self::assertNotNull($evidence->getCorrelation()?->getMessageId());
    }

    #[DataProvider('rollbackFaults')]
    public function test_required_participants_roll_back_and_double_notification_failure_cannot_replace_fault(
        string $fault
    ): void {
        $binding = $this->binding($fault);
        $before = $binding->reopenUser();
        try {
            $binding->execute();
            self::fail('The injected failure did not propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($binding->primaryFailure(), $runtimeException);
        }

        $after = $binding->reopenUser();
        self::assertNotSame($before, $after);
        self::assertEquals($before, $after);
        self::assertSame([], $binding->reopenEvidence());
    }

    #[DataProvider('committedFaults')]
    public function test_throwing_does_not_establish_rollback(string $fault): void
    {
        $binding = $this->binding($fault);
        try {
            $binding->execute();
            self::fail('The injected failure did not propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($binding->primaryFailure(), $runtimeException);
        }

        self::assertSame(UserState::ACTIVE, $binding->reopenUser()->getState());
        $this->assertAtomicOutcome($binding);
    }

    #[DataProvider('concurrentFaults')]
    public function test_concurrency_loss_keeps_only_authoritative_outcomes(
        string $fault,
        UserState $state,
        int $records
    ): void {
        $binding = $this->binding($fault);
        try {
            $binding->execute();
            self::fail('A losing write was accepted.');
        } catch (StateConflict) {
        }

        self::assertSame($state, $binding->reopenUser()->getState());
        self::assertCount($records, $binding->reopenEvidence());
        $this->assertAtomicOutcome($binding);
    }

    #[DataProvider('wrongTransactions')]
    public function test_negative_controls_detect_two_independently_active_misbound_transactions(
        string $fault,
        UserState $state,
        int $records
    ): void {
        $binding = $this->binding($fault);
        [$transaction, $business, $audit] = $binding->identities();
        self::assertSame($transaction, $business);
        self::assertNotSame($transaction, $audit);
        $failure = null;
        try {
            $binding->execute();
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        if ($fault === 'orphan') {
            self::assertSame($binding->primaryFailure(), $failure);
        } else {
            self::assertNull($failure);
        }

        self::assertSame($state, $binding->reopenUser()->getState());
        self::assertCount($records, $binding->reopenEvidence());
        self::assertNotNull($this->atomicityViolation($binding));
    }

    abstract protected function binding(string $fault = 'none'): AuditTransactionBinding;

    private function assertAtomicOutcome(AuditTransactionBinding $binding): void
    {
        self::assertNull($this->atomicityViolation($binding));
    }

    private function atomicityViolation(AuditTransactionBinding $binding): ?string
    {
        $user = $binding->reopenUser();
        $records = $binding->reopenEvidence();
        if ($user->getState() === UserState::DISABLED) {
            return $records === [] ? null : 'Orphaned evidence';
        }

        if (count($records) !== 1) {
            return 'Missing or duplicate evidence';
        }

        if ($user->getId()->toString() !== $records[0]->getSubject()->getId()->toString()) {
            return 'Unrelated subject';
        }

        if (AuditUserRevisions::fromUser($user)->toArray() !== $records[0]->getFacts()->toArray()['after']) {
            return 'Non-authoritative successor';
        }

        return null;
    }
}
