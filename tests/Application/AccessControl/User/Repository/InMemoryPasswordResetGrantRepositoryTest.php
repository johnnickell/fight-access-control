<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryPasswordResetGrantRepositoryTest extends TestCase
{
    public function test_that_issued_reissue_rejects_any_digest_from_the_users_history(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrantRepository();
        $grantA = $this->grant($userId, 'reset-a', '2026-08-20T13:00:00+00:00');
        $grantB = $this->grant($userId, 'reset-b', '2026-08-20T14:00:00+00:00');
        $reusedA = $this->grant($userId, 'reset-a', '2026-08-20T15:00:00+00:00');
        $repository->add($grantA);
        self::assertTrue($repository->replace(
            $grantA,
            $grantA->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00')),
            $grantB
        ));

        self::assertFalse($repository->replace(
            $grantB,
            $grantB->revoke(new DateTimeImmutable('2026-08-20T13:15:00+00:00')),
            $reusedA
        ));
        self::assertSame($grantB, $repository->getByUserId($userId));
        self::assertCount(2, $repository->all());
    }

    public function test_that_terminal_append_rejects_any_digest_from_the_users_history(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrantRepository();
        $grantA = $this->grant($userId, 'reset-a', '2026-08-20T13:00:00+00:00');
        $grantB = $this->grant($userId, 'reset-b', '2026-08-20T14:00:00+00:00');
        $reusedA = $this->grant($userId, 'reset-a', '2026-08-20T15:00:00+00:00');
        $repository->add($grantA);
        self::assertTrue($repository->replace(
            $grantA,
            $grantA->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00')),
            $grantB
        ));
        $terminalGrantB = $grantB->consume(new DateTimeImmutable('2026-08-20T13:15:00+00:00'));
        self::assertTrue($repository->replaceConsumed($grantB, $terminalGrantB));

        self::assertFalse($repository->appendAfterTerminal($terminalGrantB, $reusedA));
        self::assertSame($terminalGrantB, $repository->getByUserId($userId));
        self::assertCount(2, $repository->all());
    }

    public function test_that_only_the_first_empty_history_add_wins(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrantRepository();
        $winner = $this->grant($userId, 'reset-winner', '2026-08-20T13:00:00+00:00');
        $staleCandidate = $this->grant($userId, 'reset-stale', '2026-08-20T13:00:01+00:00');

        self::assertTrue($repository->add($winner));
        self::assertFalse($repository->add($staleCandidate));
        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame([$winner], $repository->all());
    }

    public function test_that_fresh_authority_can_only_follow_the_latest_terminal_grant(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrantRepository();
        $terminalPredecessor = $this->grant(
            $userId,
            'reset-terminal',
            '2026-08-20T13:00:00+00:00'
        )->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));
        $repository->add($terminalPredecessor);
        $winner = $this->grant($userId, 'reset-winner', '2026-08-20T14:00:00+00:00');
        $staleCandidate = $this->grant($userId, 'reset-stale', '2026-08-20T14:00:01+00:00');

        self::assertTrue($repository->appendAfterTerminal($terminalPredecessor, $winner));
        self::assertFalse($repository->appendAfterTerminal($terminalPredecessor, $staleCandidate));
        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame([$terminalPredecessor, $winner], $repository->all());

        $invalidRepository = new InMemoryPasswordResetGrantRepository();
        $invalidRepository->add($terminalPredecessor);
        self::assertFalse($invalidRepository->appendAfterTerminal($terminalPredecessor, $terminalPredecessor));
        self::assertSame([$terminalPredecessor], $invalidRepository->all());
    }

    public function test_that_only_the_latest_issued_predecessor_can_win_reissue(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrantRepository();
        $predecessor = $this->grant($userId, 'reset-old', '2026-08-20T13:00:00+00:00');
        $repository->add($predecessor);
        $winner = $this->grant($userId, 'reset-winner', '2026-08-20T14:00:00+00:00');
        $staleCandidate = $this->grant($userId, 'reset-stale', '2026-08-20T14:00:01+00:00');
        $reusedCredential = $this->grant($userId, 'reset-old', '2026-08-20T14:00:02+00:00');

        self::assertFalse($repository->replace(
            $predecessor,
            $predecessor->revoke(new DateTimeImmutable('2026-08-20T12:14:59+00:00')),
            $reusedCredential
        ));
        self::assertSame([$predecessor], $repository->all());

        self::assertTrue($repository->replace(
            $predecessor,
            $predecessor->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00')),
            $winner
        ));
        self::assertFalse($repository->replace(
            $predecessor,
            $predecessor->revoke(new DateTimeImmutable('2026-08-20T12:15:01+00:00')),
            $staleCandidate
        ));

        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame([$repository->all()[0], $winner], $repository->all());
        self::assertTrue($repository->all()[0]->isRevoked());
    }

    public function test_that_only_the_latest_issued_predecessor_can_win_consumption(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrantRepository();
        $predecessor = $this->grant($userId, 'reset-once', '2026-08-20T13:00:00+00:00');
        $repository->add($predecessor);
        $winner = $predecessor->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));
        $staleCandidate = $predecessor->consume(new DateTimeImmutable('2026-08-20T12:15:01+00:00'));

        self::assertTrue($repository->replaceConsumed($predecessor, $winner));
        self::assertFalse($repository->replaceConsumed($predecessor, $staleCandidate));
        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame([$winner], $repository->all());
    }

    private function grant(UserId $userId, string $credential, string $expiresAt): PasswordResetGrant
    {
        return PasswordResetGrant::issue(
            $userId,
            PasswordResetCredential::fromString($credential),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable($expiresAt)
        );
    }
}
