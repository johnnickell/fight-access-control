<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetDelivery;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryPasswordResetGrantsTest extends TestCase
{
    public function test_that_issued_reissue_rejects_any_digest_from_the_users_history(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrants();
        $grantA = $this->grant($userId, 'reset-a', '2026-08-20T13:00:00+00:00');
        $grantB = $this->grant($userId, 'reset-b', '2026-08-20T14:00:00+00:00');
        $reusedA = $this->grant($userId, 'reset-a', '2026-08-20T15:00:00+00:00');
        $repository->add($grantA);
        self::assertTrue($repository->replaceWithSuccessor(
            $grantA,
            $grantA->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00')),
            $grantB
        ));

        self::assertFalse($repository->replaceWithSuccessor(
            $grantB,
            $grantB->revoke(new DateTimeImmutable('2026-08-20T13:15:00+00:00')),
            $reusedA
        ));
        self::assertSame($grantB, $repository->getLatestByUserId($userId));
        self::assertCount(2, $repository->all());
    }

    public function test_that_terminal_append_rejects_any_digest_from_the_users_history(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrants();
        $grantA = $this->grant($userId, 'reset-a', '2026-08-20T13:00:00+00:00');
        $grantB = $this->grant($userId, 'reset-b', '2026-08-20T14:00:00+00:00');
        $reusedA = $this->grant($userId, 'reset-a', '2026-08-20T15:00:00+00:00');
        $repository->add($grantA);
        self::assertTrue($repository->replaceWithSuccessor(
            $grantA,
            $grantA->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00')),
            $grantB
        ));
        $terminalGrantB = $grantB->consume(
            new DateTimeImmutable('2026-08-20T13:15:00+00:00')
        )->invalidateDelivery();
        self::assertTrue($repository->replace($grantB, $terminalGrantB));

        self::assertFalse($repository->appendAfterTerminal($terminalGrantB, $reusedA));
        self::assertSame($terminalGrantB, $repository->getLatestByUserId($userId));
        self::assertCount(2, $repository->all());
    }

    public function test_that_only_the_first_empty_history_add_wins(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrants();
        $winner = $this->grant($userId, 'reset-winner', '2026-08-20T13:00:00+00:00');
        $staleCandidate = $this->grant($userId, 'reset-stale', '2026-08-20T13:00:01+00:00');

        self::assertTrue($repository->add($winner));
        self::assertFalse($repository->add($staleCandidate));
        self::assertSame($winner, $repository->getLatestByUserId($userId));
        self::assertSame([$winner], $repository->all());
    }

    public function test_that_fresh_authority_can_only_follow_the_latest_terminal_grant(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrants();
        $issuedPredecessor = $this->grant(
            $userId,
            'reset-terminal',
            '2026-08-20T13:00:00+00:00'
        );
        self::assertTrue($repository->add($issuedPredecessor));
        $terminalPredecessor = $issuedPredecessor
            ->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'))
            ->invalidateDelivery();
        self::assertTrue($repository->replace($issuedPredecessor, $terminalPredecessor));
        $winner = $this->grant($userId, 'reset-winner', '2026-08-20T14:00:00+00:00');
        $staleCandidate = $this->grant($userId, 'reset-stale', '2026-08-20T14:00:01+00:00');

        self::assertTrue($repository->appendAfterTerminal(clone $terminalPredecessor, $winner));
        self::assertFalse($repository->appendAfterTerminal($terminalPredecessor, $staleCandidate));
        self::assertSame($winner, $repository->getLatestByUserId($userId));
        self::assertSame([$terminalPredecessor, $winner], $repository->all());

        $invalidRepository = new InMemoryPasswordResetGrants();
        self::assertTrue($invalidRepository->add($issuedPredecessor));
        self::assertTrue($invalidRepository->replace($issuedPredecessor, $terminalPredecessor));
        self::assertFalse($invalidRepository->appendAfterTerminal($terminalPredecessor, $terminalPredecessor));
        self::assertSame([$terminalPredecessor], $invalidRepository->all());
    }

    public function test_that_only_the_latest_issued_predecessor_can_win_reissue(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrants();
        $predecessor = $this->grant($userId, 'reset-old', '2026-08-20T13:00:00+00:00');
        $repository->add($predecessor);
        $winner = $this->grant($userId, 'reset-winner', '2026-08-20T14:00:00+00:00');
        $staleCandidate = $this->grant($userId, 'reset-stale', '2026-08-20T14:00:01+00:00');
        $reusedCredential = $this->grant($userId, 'reset-old', '2026-08-20T14:00:02+00:00');

        self::assertFalse($repository->replaceWithSuccessor(
            $predecessor,
            $predecessor->revoke(new DateTimeImmutable('2026-08-20T12:14:59+00:00')),
            $reusedCredential
        ));
        self::assertSame([$predecessor], $repository->all());

        $equivalentPredecessor = clone $predecessor;
        self::assertTrue($repository->replaceWithSuccessor(
            $equivalentPredecessor,
            $equivalentPredecessor->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00')),
            $winner
        ));
        self::assertFalse($repository->replaceWithSuccessor(
            $predecessor,
            $predecessor->revoke(new DateTimeImmutable('2026-08-20T12:15:01+00:00')),
            $staleCandidate
        ));

        self::assertSame($winner, $repository->getLatestByUserId($userId));
        self::assertSame([$repository->all()[0], $winner], $repository->all());
        self::assertTrue($repository->all()[0]->isRevoked());
    }

    public function test_that_only_the_latest_issued_predecessor_can_win_consumption(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrants();
        $predecessor = $this->grant($userId, 'reset-once', '2026-08-20T13:00:00+00:00');
        $repository->add($predecessor);
        $winner = $predecessor->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));
        $staleCandidate = $predecessor->consume(new DateTimeImmutable('2026-08-20T12:15:01+00:00'));

        self::assertTrue($repository->replace($predecessor, $winner));
        self::assertFalse($repository->replace($predecessor, $staleCandidate));
        self::assertSame($winner, $repository->getLatestByUserId($userId));
        self::assertSame([$winner], $repository->all());
    }

    public function test_that_cas_accepts_a_distinct_equivalent_predecessor_and_rejects_its_stale_revision(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryPasswordResetGrants();
        $predecessor = $this->grant($userId, 'reset-once', '2026-08-20T13:00:00+00:00');
        self::assertTrue($repository->add($predecessor));
        $equivalentPredecessor = clone $predecessor;
        $consumed = $equivalentPredecessor->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));

        self::assertNotSame($predecessor, $equivalentPredecessor);
        self::assertTrue($repository->replace($equivalentPredecessor, $consumed));
        self::assertSame($equivalentPredecessor->getRevision() + 1, $consumed->getRevision());
        self::assertFalse($repository->replace(
            $equivalentPredecessor,
            $equivalentPredecessor->consume(new DateTimeImmutable('2026-08-20T12:16:00+00:00'))
        ));
        self::assertSame($consumed, $repository->getLatestByUserId($userId));
    }

    public function test_that_add_rejects_every_non_pristine_or_misowned_initial_generation(): void
    {
        $grant = $this->grant(UserId::generate(), 'reset-once', '2026-08-20T13:00:00+00:00');
        $at = new DateTimeImmutable('2026-08-20T12:15:00+00:00');
        $candidates = [
            $this->reconstitute($grant, revision: 1),
            $this->reconstitute($grant, ciphertext: null),
            $this->reconstitute($grant, ciphertext: ''),
            $this->reconstitute($grant, consumedAt: $at, revision: 1, ciphertext: null),
            $this->reconstitute($grant, revokedAt: $at, revision: 1, ciphertext: null),
            $this->reconstitute($grant, deliveryUserId: UserId::generate()),
        ];

        foreach ($candidates as $candidate) {
            $repository = new InMemoryPasswordResetGrants();
            self::assertFalse($repository->add($candidate));
            self::assertSame([], $repository->all());
        }
    }

    public function test_that_terminal_append_rejects_fabricated_terminal_state_while_storage_is_issued(): void
    {
        $repository = new InMemoryPasswordResetGrants();
        $issued = $this->grant(UserId::generate(), 'reset-old', '2026-08-20T13:00:00+00:00');
        self::assertTrue($repository->add($issued));
        $fabricatedTerminal = $this->reconstitute(
            $issued,
            consumedAt: new DateTimeImmutable('2026-08-20T12:15:00+00:00'),
            ciphertext: null
        );
        $successor = $this->grant($issued->getUserId(), 'reset-new', '2026-08-20T14:00:00+00:00');

        self::assertFalse($repository->appendAfterTerminal($fabricatedTerminal, $successor));
        self::assertSame([$issued], $repository->all());
        self::assertNull($repository->getById($successor->getId()));
    }

    public function test_that_replace_rejects_transitions_derived_from_fabricated_predecessor_state(): void
    {
        $issued = $this->grant(UserId::generate(), 'reset-old', '2026-08-20T13:00:00+00:00');
        $differentExpiry = new DateTimeImmutable('2026-08-20T14:00:00+00:00');
        $fabricatedDigest = $this->reconstitute($issued, credentialHash: 'fabricated-digest');
        $fabricatedDeliveryId = $this->reconstitute($issued, deliveryId: PasswordResetDeliveryId::generate());
        $fabricatedExpiry = $this->reconstitute($issued, expiresAt: $differentExpiry);
        $fabricatedEmail = $this->reconstitute(
            $issued,
            email: EmailAddress::fromString('mallory@example.test')
        );
        $fabricatedCiphertext = $this->reconstitute($issued, ciphertext: 'fabricated-ciphertext');
        $attempts = [
            [$fabricatedDigest, $fabricatedDigest->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'))],
            [$fabricatedDeliveryId, $fabricatedDeliveryId->confirmDelivery()],
            [$fabricatedExpiry, $fabricatedExpiry->expireDeliveryAt($differentExpiry)],
            [$fabricatedEmail, $fabricatedEmail->invalidateDelivery()],
            [$fabricatedCiphertext, $fabricatedCiphertext->consume(
                new DateTimeImmutable('2026-08-20T12:15:00+00:00')
            )],
        ];

        foreach ($attempts as [$fabricatedPredecessor, $replacement]) {
            $repository = new InMemoryPasswordResetGrants();
            self::assertTrue($repository->add($issued));
            self::assertFalse($repository->replace($fabricatedPredecessor, $replacement));
            self::assertSame([$issued], $repository->all());
        }
    }

    public function test_that_replace_rejects_consumption_fabricated_at_or_after_expiry_without_mutation(): void
    {
        $issued = $this->grant(UserId::generate(), 'reset-once', '2026-08-20T13:00:00+00:00');

        foreach ([$issued->getExpiresAt(), $issued->getExpiresAt()->modify('+1 second')] as $consumedAt) {
            $repository = new InMemoryPasswordResetGrants();
            self::assertTrue($repository->add($issued));
            $fabricatedConsumption = $this->reconstitute(
                $issued,
                revision: 1,
                consumedAt: $consumedAt,
                ciphertext: null
            );

            self::assertFalse($repository->replace($issued, $fabricatedConsumption));
            self::assertSame([$issued], $repository->all());
        }
    }

    public function test_that_terminal_append_compares_complete_stored_terminal_state(): void
    {
        $repository = new InMemoryPasswordResetGrants();
        $issued = $this->grant(UserId::generate(), 'reset-old', '2026-08-20T13:00:00+00:00');
        self::assertTrue($repository->add($issued));
        $terminal = $issued->consume(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));
        self::assertTrue($repository->replace($issued, $terminal));
        $successor = $this->grant($issued->getUserId(), 'reset-new', '2026-08-20T14:00:00+00:00');
        $fabricatedTerminal = $this->reconstitute(
            $terminal,
            revision: $terminal->getRevision(),
            consumedAt: new DateTimeImmutable('2026-08-20T12:16:00+00:00'),
            ciphertext: null
        );

        self::assertFalse($repository->appendAfterTerminal($fabricatedTerminal, $successor));
        self::assertSame([$terminal], $repository->all());
    }

    public function test_that_successor_rejects_fabricated_predecessor_and_terminal_transition(): void
    {
        $issued = $this->grant(UserId::generate(), 'reset-old', '2026-08-20T13:00:00+00:00');
        $successor = $this->grant($issued->getUserId(), 'reset-new', '2026-08-20T14:00:00+00:00');

        $repository = new InMemoryPasswordResetGrants();
        self::assertTrue($repository->add($issued));
        $fabricatedPredecessor = $this->reconstitute(
            $issued,
            email: EmailAddress::fromString('mallory@example.test')
        );
        self::assertFalse($repository->replaceWithSuccessor(
            $fabricatedPredecessor,
            $fabricatedPredecessor->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00')),
            $successor
        ));
        self::assertSame([$issued], $repository->all());

        $fabricatedTerminal = $this->reconstitute(
            $issued,
            revision: 1,
            consumedAt: $issued->getExpiresAt(),
            ciphertext: null
        );
        self::assertFalse($repository->replaceWithSuccessor($issued, $fabricatedTerminal, $successor));
        self::assertSame([$issued], $repository->all());
        self::assertNull($repository->getById($successor->getId()));
    }

    public function test_that_both_successor_paths_require_pristine_state_and_fresh_ids_and_digest(): void
    {
        $repository = new InMemoryPasswordResetGrants();
        $predecessor = $this->grant(UserId::generate(), 'reset-old', '2026-08-20T13:00:00+00:00');
        self::assertTrue($repository->add($predecessor));
        $terminal = $predecessor->revoke(new DateTimeImmutable('2026-08-20T12:15:00+00:00'));
        $successor = $this->grant($predecessor->getUserId(), 'reset-new', '2026-08-20T14:00:00+00:00');
        $malformed = [
            $this->reconstitute($successor, revision: 1),
            $this->reconstitute($successor, ciphertext: null),
            $this->reconstitute($successor, ciphertext: ''),
            $this->reconstitute($successor, consumedAt: new DateTimeImmutable(), revision: 1, ciphertext: null),
            $this->reconstitute($successor, grantId: $predecessor->getId()),
            $this->reconstitute($successor, deliveryId: $predecessor->getDelivery()->getId()),
            $this->grant($predecessor->getUserId(), 'reset-old', '2026-08-20T15:00:00+00:00'),
        ];

        foreach ($malformed as $candidate) {
            self::assertFalse($repository->replaceWithSuccessor($predecessor, $terminal, $candidate));
            self::assertSame([$predecessor], $repository->all());
        }

        self::assertTrue($repository->replace($predecessor, $terminal));
        foreach ($malformed as $candidate) {
            self::assertFalse($repository->appendAfterTerminal($terminal, $candidate));
            self::assertSame([$terminal], $repository->all());
        }
    }

    private function grant(UserId $userId, string $credential, string $expiresAt): PasswordResetGrant
    {
        return PasswordResetGrant::issue(
            $userId,
            PasswordResetCredential::fromString($credential),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable($expiresAt),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
    }

    private function reconstitute(
        PasswordResetGrant $grant,
        int $revision = 0,
        ?DateTimeImmutable $consumedAt = null,
        ?DateTimeImmutable $revokedAt = null,
        ?UserId $deliveryUserId = null,
        ?PasswordResetGrantId $grantId = null,
        ?PasswordResetDeliveryId $deliveryId = null,
        ?string $credentialHash = null,
        ?DateTimeImmutable $expiresAt = null,
        ?EmailAddress $email = null,
        ?string $ciphertext = 'ciphertext'
    ): PasswordResetGrant {
        $resolvedExpiresAt = $expiresAt ?? $grant->getExpiresAt();

        return ExtensiblePasswordResetGrant::reconstitute(
            $grantId ?? $grant->getId(),
            $grant->getUserId(),
            $credentialHash ?? $grant->getCredentialHash(),
            $resolvedExpiresAt,
            ExtensiblePasswordResetDelivery::reconstitute(
                $deliveryId ?? $grant->getDelivery()->getId(),
                $deliveryUserId ?? $grant->getUserId(),
                $email ?? $grant->getDelivery()->getEmail(),
                $ciphertext,
                $resolvedExpiresAt
            ),
            $consumedAt,
            $revokedAt,
            $revision
        );
    }
}
