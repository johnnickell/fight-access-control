<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant\ExtensibleActivationDelivery;
use Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant\ExtensibleActivationGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InMemoryActivationGrantRepositoryTest extends TestCase
{
    public function test_that_stable_id_delivery_and_latest_user_lookups_resolve_one_generation(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->grant(UserId::generate(), 'activate-once');

        self::assertTrue($repository->add($activationGrant));
        self::assertSame($activationGrant, $repository->getById($activationGrant->getId()));
        self::assertSame(
            $activationGrant,
            $repository->getByDeliveryId($activationGrant->getDelivery()->getId())
        );
        self::assertSame($activationGrant, $repository->getLatestByUserId($activationGrant->getUserId()));
    }

    public function test_that_same_generation_cas_rejects_a_stale_predecessor(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->grant(UserId::generate(), 'activate-once');
        self::assertTrue($repository->add($activationGrant));
        $claimed = $activationGrant->claimDelivery();
        self::assertTrue($repository->replace($activationGrant, $claimed));
        $failed = $claimed->failDelivery();
        self::assertTrue($repository->replace($claimed, $failed));

        self::assertFalse($repository->replace($activationGrant, $activationGrant->claimDelivery()));
        self::assertSame($failed, $repository->getLatestByUserId($activationGrant->getUserId()));
    }

    public function test_that_cas_uses_generation_and_revision_instead_of_object_identity(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->grant(UserId::generate(), 'activate-once');
        self::assertTrue($repository->add($activationGrant));
        $equivalentPredecessor = clone $activationGrant;
        $claimed = $equivalentPredecessor->claimDelivery();

        self::assertNotSame($activationGrant, $equivalentPredecessor);
        self::assertTrue($repository->replace($equivalentPredecessor, $claimed));
        self::assertFalse($repository->replace($equivalentPredecessor, $equivalentPredecessor->claimDelivery()));
        self::assertSame(
            $claimed->getRevision(),
            $repository->getLatestByUserId($activationGrant->getUserId())?->getRevision()
        );
    }

    public function test_that_concurrent_delivery_claims_have_one_cas_winner(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->grant(UserId::generate(), 'activate-once');
        self::assertTrue($repository->add($activationGrant));
        $claimA = (clone $activationGrant)->claimDelivery();
        $claimB = (clone $activationGrant)->claimDelivery();

        self::assertTrue($repository->replace(clone $activationGrant, $claimA));
        self::assertFalse($repository->replace(clone $activationGrant, $claimB));
        self::assertSame($claimA, $repository->getLatestByUserId($activationGrant->getUserId()));
    }

    public function test_that_successor_append_is_atomic_and_rejects_historical_credential_reuse(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryActivationGrantRepository();
        $predecessor = $this->grant($userId, 'activate-once');
        self::assertTrue($repository->add($predecessor));
        $terminal = $predecessor->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00'));
        $duplicateDigest = $this->grant($userId, 'activate-once');

        self::assertFalse($repository->replaceWithSuccessor($predecessor, $terminal, $duplicateDigest));
        self::assertSame([$predecessor], $repository->all());

        $successor = $this->grant($userId, 'activate-new');
        $equivalentPredecessor = clone $predecessor;
        $equivalentTerminal = $equivalentPredecessor->revoke(
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );
        self::assertTrue($repository->replaceWithSuccessor(
            $equivalentPredecessor,
            $equivalentTerminal,
            $successor
        ));
        self::assertSame([$equivalentTerminal, $successor], $repository->all());
    }

    public function test_that_add_rejects_every_non_pristine_or_misowned_initial_generation(): void
    {
        $grant = $this->grant(UserId::generate(), 'activate-once');
        $at = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        $candidates = [
            $this->reconstitute($grant, revision: 1),
            $this->reconstitute($grant, status: ActivationDeliveryStatus::CLAIMED),
            $this->reconstitute($grant, status: ActivationDeliveryStatus::FAILED),
            $this->reconstitute($grant, status: ActivationDeliveryStatus::CONFIRMED, ciphertext: null),
            $this->reconstitute($grant, status: ActivationDeliveryStatus::EXPIRED, ciphertext: null),
            $this->reconstitute($grant, ciphertext: null),
            $this->reconstitute($grant, ciphertext: ''),
            $this->reconstitute($grant, consumedAt: $at, revision: 1, ciphertext: null),
            $this->reconstitute($grant, revokedAt: $at, revision: 1, ciphertext: null),
            $this->reconstitute($grant, deliveryUserId: UserId::generate()),
        ];

        foreach ($candidates as $candidate) {
            $repository = new InMemoryActivationGrantRepository();
            self::assertFalse($repository->add($candidate));
            self::assertSame([], $repository->all());
        }
    }

    public function test_that_replace_rejects_unclaimed_outcomes_without_mutation(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $pending = $this->grant(UserId::generate(), 'activate-once');
        self::assertTrue($repository->add($pending));

        foreach ([ActivationDeliveryStatus::CONFIRMED, ActivationDeliveryStatus::FAILED] as $status) {
            $ciphertext = $status === ActivationDeliveryStatus::CONFIRMED ? null : 'ciphertext';
            $bypass = $this->reconstitute($pending, $status, $ciphertext, 1);
            self::assertFalse($repository->replace($pending, $bypass));
            self::assertSame($pending, $repository->getLatestByUserId($pending->getUserId()));
        }
    }

    public function test_that_replace_rejects_outcomes_derived_from_a_fabricated_claimed_predecessor(): void
    {
        foreach ([ActivationDeliveryStatus::CONFIRMED, ActivationDeliveryStatus::FAILED] as $status) {
            $repository = new InMemoryActivationGrantRepository();
            $pending = $this->grant(UserId::generate(), 'activate-once');
            self::assertTrue($repository->add($pending));
            $fabricatedClaimed = $this->reconstitute($pending, ActivationDeliveryStatus::CLAIMED);
            $ciphertext = $status === ActivationDeliveryStatus::CONFIRMED ? null : 'ciphertext';
            $outcome = $this->reconstitute($pending, $status, $ciphertext, 1);

            self::assertFalse($repository->replace($fabricatedClaimed, $outcome));
            self::assertSame([$pending], $repository->all());
        }
    }

    public function test_that_replace_accepts_outcomes_from_the_authoritative_claimed_predecessor(): void
    {
        foreach ([ActivationDeliveryStatus::CONFIRMED, ActivationDeliveryStatus::FAILED] as $status) {
            $repository = new InMemoryActivationGrantRepository();
            $pending = $this->grant(UserId::generate(), 'activate-once');
            self::assertTrue($repository->add($pending));
            $claimed = $pending->claimDelivery();
            self::assertTrue($repository->replace($pending, $claimed));
            if ($status === ActivationDeliveryStatus::CONFIRMED) {
                $outcome = $claimed->confirmDelivery();
            } else {
                $outcome = $claimed->failDelivery();
            }

            self::assertTrue($repository->replace($claimed, $outcome));
            self::assertSame([$outcome], $repository->all());
        }
    }

    public function test_that_replace_rejects_consumption_fabricated_at_or_after_expiry_without_mutation(): void
    {
        $issued = $this->grant(UserId::generate(), 'activate-once');

        foreach ([$issued->getExpiresAt(), $issued->getExpiresAt()->modify('+1 second')] as $consumedAt) {
            $repository = new InMemoryActivationGrantRepository();
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

    public function test_that_successor_rejects_a_fabricated_terminal_transition_without_mutation(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $predecessor = $this->grant(UserId::generate(), 'activate-old');
        self::assertTrue($repository->add($predecessor));
        $fabricatedTerminal = $this->reconstitute(
            $predecessor,
            ActivationDeliveryStatus::CONFIRMED,
            null,
            1,
            revokedAt: new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );
        $successor = $this->grant($predecessor->getUserId(), 'activate-new');

        self::assertFalse($repository->replaceWithSuccessor($predecessor, $fabricatedTerminal, $successor));
        self::assertSame([$predecessor], $repository->all());
        self::assertNull($repository->getById($successor->getId()));
    }

    public function test_that_successor_accepts_authoritative_terminalization_and_a_pristine_successor(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $predecessor = $this->grant(UserId::generate(), 'activate-old');
        self::assertTrue($repository->add($predecessor));
        $terminal = $predecessor->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00'));
        $successor = $this->grant($predecessor->getUserId(), 'activate-new');

        self::assertTrue($repository->replaceWithSuccessor(clone $predecessor, $terminal, $successor));
        self::assertSame([$terminal, $successor], $repository->all());
    }

    public function test_that_successor_requires_pristine_state_and_fresh_ids_and_digest(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $predecessor = $this->grant(UserId::generate(), 'activate-old');
        self::assertTrue($repository->add($predecessor));
        $terminal = $predecessor->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00'));
        $successor = $this->grant($predecessor->getUserId(), 'activate-new');
        $malformed = [
            $this->reconstitute($successor, revision: 1),
            $this->reconstitute($successor, status: ActivationDeliveryStatus::FAILED),
            $this->reconstitute($successor, status: ActivationDeliveryStatus::CONFIRMED, ciphertext: null),
            $this->reconstitute($successor, ciphertext: ''),
            $this->reconstitute($successor, revokedAt: new DateTimeImmutable(), revision: 1, ciphertext: null),
            $this->reconstitute($successor, grantId: $predecessor->getId()),
            $this->reconstitute($successor, deliveryId: $predecessor->getDelivery()->getId()),
            $this->grant($predecessor->getUserId(), 'activate-old'),
        ];

        foreach ($malformed as $candidate) {
            self::assertFalse($repository->replaceWithSuccessor($predecessor, $terminal, $candidate));
            self::assertSame([$predecessor], $repository->all());
        }
    }

    private function grant(UserId $userId, string $credential): ActivationGrant
    {
        return ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString($credential),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
    }

    private function reconstitute(
        ActivationGrant $grant,
        ActivationDeliveryStatus $status = ActivationDeliveryStatus::PENDING,
        ?string $ciphertext = 'ciphertext',
        int $revision = 0,
        ?DateTimeImmutable $consumedAt = null,
        ?DateTimeImmutable $revokedAt = null,
        ?UserId $deliveryUserId = null,
        ?ActivationGrantId $grantId = null,
        ?ActivationDeliveryId $deliveryId = null
    ): ActivationGrant {
        return ExtensibleActivationGrant::reconstitute(
            $grantId ?? $grant->getId(),
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            ExtensibleActivationDelivery::reconstitute(
                $deliveryId ?? $grant->getDelivery()->getId(),
                $deliveryUserId ?? $grant->getUserId(),
                $grant->getDelivery()->getEmail(),
                $ciphertext,
                $grant->getExpiresAt(),
                $status
            ),
            $consumedAt,
            $revokedAt,
            $revision
        );
    }
}
