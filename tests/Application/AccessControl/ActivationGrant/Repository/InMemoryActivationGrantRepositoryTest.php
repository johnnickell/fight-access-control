<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant\ExtensibleActivationDelivery;
use Fight\Test\AccessControl\Domain\AccessControl\ActivationGrant\ExtensibleActivationGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class InMemoryActivationGrantRepositoryTest extends TestCase
{
    public function test_it_discovers_due_pending_and_expired_lease_work_deterministically_without_secrets(): void
    {
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $later = $this->grant(UserId::generate(), 'later', '12:06:00');
        $earlier = $this->grant(UserId::generate(), 'earlier', '12:00:00');
        self::assertTrue($activationGrantRepository->add($later));
        self::assertTrue($activationGrantRepository->add($earlier));

        $claimToken = CredentialDeliveryClaimToken::generate();
        $claimed = $earlier->claimDelivery($claimToken, $this->at('12:00:00'), $this->at('12:05:00'));
        self::assertTrue($activationGrantRepository->replace($earlier, $claimed));
        self::assertSame([], $activationGrantRepository->findDue($this->at('12:04:59'), 10));

        $due = $activationGrantRepository->findDue($this->at('12:05:00'), 1);
        self::assertCount(1, $due);
        self::assertSame($claimed->getDelivery()->getId()->toString(), $due[0]->getDeliveryId()->toString());
        self::assertSame(CredentialDeliveryStatus::CLAIMED, $due[0]->getStatus());
        self::assertSame('activation', $due[0]->getPurpose());
        self::assertSame($claimed->getRevision(), $due[0]->getRevision());
        self::assertEquals($this->at('12:05:00'), $due[0]->getDueAt());
        self::assertStringNotContainsString('ciphertext', serialize($due[0]->toArray()));
        self::assertSame([], $activationGrantRepository->findDue($this->at('12:05:00'), 0));
    }

    public function test_concurrent_claims_and_stale_outcomes_have_one_complete_state_cas_winner(): void
    {
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $grant = $this->grant(UserId::generate(), 'once');
        self::assertTrue($activationGrantRepository->add($grant));
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

        self::assertTrue($activationGrantRepository->replace(clone $grant, $claimA));
        self::assertFalse($activationGrantRepository->replace(clone $grant, $claimB));
        $outcome = $claimA->failDelivery(
            $claimA->getDelivery()->getClaimToken(),
            $this->at('12:01:00'),
            CredentialDeliveryFailure::RETRYABLE_PROVIDER
        );
        self::assertTrue($activationGrantRepository->replace(clone $claimA, $outcome));
        self::assertFalse($activationGrantRepository->replace($claimA, $claimA->confirmDelivery(
            $claimA->getDelivery()->getClaimToken(),
            $this->at('12:01:00')
        )));
        self::assertSame($outcome, $activationGrantRepository->getById($grant->getId()));
        self::assertSame($outcome, $activationGrantRepository->getByDeliveryId($grant->getDelivery()->getId()));
    }

    public function test_writes_roll_back_with_the_callers_unit_of_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->grant(UserId::generate(), 'once');

        try {
            $unitOfWork->commitTransactional(function () use ($activationGrantRepository, $grant): void {
                self::assertTrue($activationGrantRepository->add($grant));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([], $activationGrantRepository->all());

        self::assertTrue($activationGrantRepository->add($grant));
        $claimed = $grant->claimDelivery(
            CredentialDeliveryClaimToken::generate(),
            $this->at('12:00:00'),
            $this->at('12:05:00')
        );
        try {
            $unitOfWork->commitTransactional(function () use ($activationGrantRepository, $grant, $claimed): void {
                self::assertTrue($activationGrantRepository->replace($grant, $claimed));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame($grant, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
    }

    public function test_initial_and_successor_writes_reject_non_pristine_delivery_state_without_mutation(): void
    {
        $template = $this->grant(UserId::generate(), 'malformed');
        $candidates = [
            $this->withDelivery(
                $template,
                ExtensibleActivationDelivery::malformedPending(
                    $template->getDelivery(),
                    $template->getDelivery()->getDueAt(),
                    true
                )
            ),
            $this->withDelivery(
                $template,
                ExtensibleActivationDelivery::malformedPending(
                    $template->getDelivery(),
                    $template->getExpiresAt(),
                    false
                )
            ),
            $this->withDelivery(
                $template,
                ExtensibleActivationDelivery::malformedPending(
                    $template->getDelivery(),
                    $template->getExpiresAt()->modify('+1 second'),
                    false
                )
            )
        ];

        foreach ($candidates as $candidate) {
            $activationGrantRepository = new InMemoryActivationGrantRepository();
            self::assertFalse($activationGrantRepository->add($candidate));
            self::assertSame([], $activationGrantRepository->all());

            $predecessor = $this->grant($candidate->getUserId(), 'predecessor');
            self::assertTrue($activationGrantRepository->add($predecessor));
            $terminal = $predecessor->revoke($this->at('12:10:00'));
            self::assertFalse($activationGrantRepository->replaceWithSuccessor($predecessor, $terminal, $candidate));
            self::assertSame([$predecessor], $activationGrantRepository->all());
        }
    }

    public function test_successor_replacement_is_atomic_and_fences_historical_or_duplicate_authority(): void
    {
        $userId = UserId::generate();
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $predecessor = $this->grant($userId, 'old');
        self::assertTrue($activationGrantRepository->add($predecessor));
        $terminal = $predecessor->revoke($this->at('12:10:00'));

        self::assertFalse($activationGrantRepository->replaceWithSuccessor(
            $predecessor,
            $terminal,
            $this->grant($userId, 'old')
        ));
        $successor = $this->grant($userId, 'new');
        self::assertTrue($activationGrantRepository->replaceWithSuccessor($predecessor, $terminal, $successor));
        self::assertSame([$terminal, $successor], $activationGrantRepository->all());
        self::assertFalse($activationGrantRepository->addSuccessor($this->grant($userId, 'third')));
    }

    private function withDelivery(
        ActivationGrant $grant,
        ActivationDelivery $delivery
    ): ActivationGrant {
        return ExtensibleActivationGrant::reconstitute(
            $grant->getOrigin(),
            $grant->getId(),
            $grant->getUserId(),
            $grant->getCredentialHash(),
            $grant->getExpiresAt(),
            $delivery
        );
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-25T'.$time.'+00:00');
    }

    private function grant(UserId $userId, string $credential, string $issuedAt = '12:00:00'): ActivationGrant
    {
        return ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            $userId,
            ActivationCredential::fromString($credential),
            $this->at($issuedAt),
            $this->at('13:00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:'.$credential
        );
    }
}
