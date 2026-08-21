<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryPasswordResetDeliveryRepositoryTest extends TestCase
{
    public function test_that_only_the_first_empty_history_add_wins(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetDeliveryRepository();
        $winner = $this->delivery($userId, 'ciphertext:reset-winner');
        $staleCandidate = $this->delivery($userId, 'ciphertext:reset-stale');

        self::assertTrue($repository->add($winner));
        self::assertFalse($repository->add($staleCandidate));
        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame([$winner], $repository->all());
        self::assertNull($repository->getById($staleCandidate->getId()));
    }

    public function test_that_fresh_work_can_only_follow_the_latest_terminal_generation(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetDeliveryRepository();
        $terminalPredecessor = $this->delivery($userId, 'ciphertext:reset-old')->confirm();
        $repository->add($terminalPredecessor);
        $winner = $this->delivery($userId, 'ciphertext:reset-winner');
        $staleCandidate = $this->delivery($userId, 'ciphertext:reset-stale');

        self::assertTrue($repository->appendAfterTerminal($terminalPredecessor, $winner));
        self::assertFalse($repository->appendAfterTerminal($terminalPredecessor, $staleCandidate));
        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame([$terminalPredecessor, $winner], $repository->all());
        self::assertNull($repository->getById($staleCandidate->getId()));
    }

    public function test_that_reissue_requires_the_latest_delivery_generation(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetDeliveryRepository();
        $predecessor = $this->delivery($userId, 'ciphertext:reset-old');
        $repository->add($predecessor);
        $winner = $this->delivery($userId, 'ciphertext:reset-winner');
        $staleCandidate = $this->delivery($userId, 'ciphertext:reset-stale');

        self::assertTrue($repository->replace($predecessor, $predecessor->invalidate(), $winner));
        self::assertFalse($repository->replace($predecessor, $predecessor->invalidate(), $staleCandidate));

        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame($predecessor->getId(), $repository->all()[0]->getId());
        self::assertFalse($repository->all()[0]->isRecoverable());
        self::assertSame([$repository->all()[0], $winner], $repository->all());
        self::assertNull($repository->getById($staleCandidate->getId()));
    }

    public function test_that_only_the_current_generation_can_be_invalidated(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetDeliveryRepository();
        $predecessor = $this->delivery($userId, 'ciphertext:reset-once');
        $repository->add($predecessor);
        $winner = $predecessor->confirm();
        $staleCandidate = $predecessor->expireAt(new DateTimeImmutable('2026-08-20T14:00:00+00:00'));

        self::assertTrue($repository->replaceInvalidated($predecessor, $winner));
        self::assertFalse($repository->replaceInvalidated($predecessor, $staleCandidate));
        self::assertSame($winner, $repository->getByUserId($userId));
        self::assertSame($winner, $repository->getById($predecessor->getId()));
        self::assertSame([$winner], $repository->all());
    }

    private function delivery(UserId $userId, string $ciphertext): PasswordResetDelivery
    {
        return PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            $userId,
            'alice@example.test',
            $ciphertext,
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
    }
}
