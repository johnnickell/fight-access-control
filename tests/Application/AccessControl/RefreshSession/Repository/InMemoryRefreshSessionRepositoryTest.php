<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryRefreshSessionRepositoryTest extends TestCase
{
    private const string CURRENT_CREDENTIAL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const string WINNER_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private const string STALE_ROTATED_CREDENTIAL = '1111111111111111111111111111111111111111111111111111111111111111';

    private const string SECOND_WINNER_CREDENTIAL = '2222222222222222222222222222222222222222222222222222222222222222';

    public function test_that_only_the_expected_predecessor_can_be_atomically_replaced(): void
    {
        $repository = new InMemoryRefreshSessionRepository();
        $current = $this->session();
        $repository->add($current);
        $winner = $current->rotate(
            RefreshCredential::fromString(self::WINNER_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
        $staleCandidate = $current->rotate(
            RefreshCredential::fromString(self::STALE_ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:01+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:01+00:00')
        );
        $skippedRevision = $current->revoke()->revoke();

        self::assertFalse($repository->replace($current, $skippedRevision));
        self::assertSame($current, $repository->getById($current->getId()));

        self::assertTrue($repository->replace($current, $winner));

        self::assertFalse($repository->replace($current, $staleCandidate));
        self::assertSame($winner, $repository->getById($current->getId()));
        self::assertSame($winner, $repository->getByCredential(
            RefreshCredential::fromString(self::WINNER_CREDENTIAL)
        ));
        self::assertNull($repository->getByCredential(
            RefreshCredential::fromString(self::STALE_ROTATED_CREDENTIAL)
        ));
    }

    public function test_that_used_credential_history_resolves_the_authoritative_family(): void
    {
        $repository = new InMemoryRefreshSessionRepository();
        $current = $this->session();
        $firstWinner = $current->rotate(
            RefreshCredential::fromString(self::WINNER_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:30:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:30:00+00:00')
        );
        $secondWinner = $firstWinner->rotate(
            RefreshCredential::fromString(self::SECOND_WINNER_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00')
        );
        $repository->add($secondWinner);

        self::assertSame($secondWinner, $repository->getByUsedCredential(
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL)
        ));
        self::assertSame($secondWinner, $repository->getByUsedCredential(
            RefreshCredential::fromString(self::WINNER_CREDENTIAL)
        ));
        self::assertNull($repository->getByUsedCredential(
            RefreshCredential::fromString(self::SECOND_WINNER_CREDENTIAL)
        ));
    }

    private function session(?UserId $userId = null): RefreshSession
    {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $userId ?? UserId::generate(),
            RefreshCredential::fromString(self::CURRENT_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            1,
            false
        );
    }
}
