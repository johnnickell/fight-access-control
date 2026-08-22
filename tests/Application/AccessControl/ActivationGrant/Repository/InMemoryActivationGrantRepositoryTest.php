<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
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
        $failed = $activationGrant->failDelivery();
        self::assertTrue($repository->replace($activationGrant, $failed));

        self::assertFalse($repository->replace($activationGrant, $activationGrant->confirmDelivery()));
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
}
