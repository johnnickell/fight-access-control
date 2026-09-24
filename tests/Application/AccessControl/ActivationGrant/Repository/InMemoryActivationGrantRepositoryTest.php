<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class InMemoryActivationGrantRepositoryTest extends TestCase
{
    public function test_it_discovers_due_pending_and_expired_lease_work_deterministically_without_secrets(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $later = $this->grant(UserId::generate(), 'later', '12:06:00');
        $earlier = $this->grant(UserId::generate(), 'earlier', '12:00:00');
        self::assertTrue($repository->add($later));
        self::assertTrue($repository->add($earlier));

        $claimToken = CredentialDeliveryClaimToken::generate();
        $claimed = $earlier->claimDelivery($claimToken, $this->at('12:00:00'), $this->at('12:05:00'));
        self::assertTrue($repository->replace($earlier, $claimed));
        self::assertSame([], $repository->findDue($this->at('12:04:59'), 10));

        $due = $repository->findDue($this->at('12:05:00'), 1);
        self::assertCount(1, $due);
        self::assertSame($claimed->getDelivery()->getId()->toString(), $due[0]->getDeliveryId()->toString());
        self::assertSame(CredentialDeliveryStatus::CLAIMED, $due[0]->getStatus());
        self::assertSame('activation', $due[0]->getPurpose());
        self::assertSame($claimed->getRevision(), $due[0]->getRevision());
        self::assertEquals($this->at('12:05:00'), $due[0]->getDueAt());
        self::assertStringNotContainsString('ciphertext', serialize($due[0]->toArray()));
        self::assertSame([], $repository->findDue($this->at('12:05:00'), 0));
    }

    public function test_concurrent_claims_and_stale_outcomes_have_one_complete_state_cas_winner(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $grant = $this->grant(UserId::generate(), 'once');
        self::assertTrue($repository->add($grant));
        $claimA = $grant->claimDelivery(
            CredentialDeliveryClaimToken::generate(),
            $this->at('12:00:00'),
            $this->at('12:05:00')
        );
        $claimB = $grant->claimDelivery(
            CredentialDeliveryClaimToken::generate(),
            $this->at('12:00:00'),
            $this->at('12:05:00')
        );

        self::assertTrue($repository->replace(clone $grant, $claimA));
        self::assertFalse($repository->replace(clone $grant, $claimB));
        $outcome = $claimA->failDelivery(
            $claimA->getDelivery()->getClaimToken(),
            $this->at('12:01:00'),
            CredentialDeliveryFailure::RETRYABLE_PROVIDER
        );
        self::assertTrue($repository->replace(clone $claimA, $outcome));
        self::assertFalse($repository->replace($claimA, $claimA->confirmDelivery()));
        self::assertSame($outcome, $repository->getById($grant->getId()));
        self::assertSame($outcome, $repository->getByDeliveryId($grant->getDelivery()->getId()));
    }

    public function test_writes_roll_back_with_the_callers_unit_of_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->grant(UserId::generate(), 'once');

        try {
            $unitOfWork->commitTransactional(function () use ($repository, $grant): void {
                self::assertTrue($repository->add($grant));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([], $repository->all());

        self::assertTrue($repository->add($grant));
        $claimed = $grant->claimDelivery();
        try {
            $unitOfWork->commitTransactional(function () use ($repository, $grant, $claimed): void {
                self::assertTrue($repository->replace($grant, $claimed));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame($grant, $repository->getLatestByUserId($grant->getUserId()));
    }

    public function test_successor_replacement_is_atomic_and_fences_historical_or_duplicate_authority(): void
    {
        $userId = UserId::generate();
        $repository = new InMemoryActivationGrantRepository();
        $predecessor = $this->grant($userId, 'old');
        self::assertTrue($repository->add($predecessor));
        $terminal = $predecessor->revoke($this->at('12:10:00'));

        self::assertFalse($repository->replaceWithSuccessor(
            $predecessor,
            $terminal,
            $this->grant($userId, 'old')
        ));
        $successor = $this->grant($userId, 'new');
        self::assertTrue($repository->replaceWithSuccessor($predecessor, $terminal, $successor));
        self::assertSame([$terminal, $successor], $repository->all());
        self::assertFalse($repository->addSuccessor($this->grant($userId, 'third')));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-25T'.$time.'+00:00');
    }

    private function grant(UserId $userId, string $credential, string $issuedAt = '12:00:00'): ActivationGrant
    {
        return ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString($credential),
            $this->at($issuedAt),
            $this->at('13:00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:'.$credential
        );
    }
}
