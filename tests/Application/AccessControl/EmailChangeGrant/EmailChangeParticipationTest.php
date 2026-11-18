<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeTransition;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeTransitionStage;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeRequestOrigin;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\User\Event\EmailChangeConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\FabricatedEmailChangeGrant;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\RecoveryFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestEmailChangeHandler::class)]
#[CoversClass(EmailChangeConfirmation::class)]
#[CoversClass(EmailChangeTransition::class)]
#[CoversClass(EmailChangeRequestOrigin::class)]
#[CoversClass(EmailChangeGrant::class)]
final class EmailChangeParticipationTest extends TestCase
{
    public function test_confirmation_after_restore_binds_the_accepted_origin_and_exact_grant(): void
    {
        $original = new RecoveryFixture();
        $participant = new InMemoryEmailChangeTransitionParticipant($original->unitOfWork);
        $requester = UserId::generate();
        $original->requestEmail(participant: $participant, requesterId: $requester);
        $grant = $original->emailChangeGrantRepository->all()[0];
        $requested = $participant->intents[$grant->getId()->toString().':requested'];
        self::assertSame(EmailChangeTransitionStage::REQUESTED, $requested->stage);
        self::assertEquals($original->now, $requested->occurredAt);
        self::assertEquals($original->userId, $requested->userId);
        self::assertEquals($requester, $requested->origin->requesterId);
        self::assertSame('recovery@example.test', $requested->origin->oldMailbox);
        self::assertSame(1, $requested->reservationRevision);
        self::assertNull($requested->confirmationActorId);
        self::assertInstanceOf(EmailChangeRequested::class, $original->eventDispatcher->events()[0]);
        self::assertEquals($requester, $original->auditEvidenceRepository->all()[0]->actorId());

        $restored = $original->connection();
        $restoredGrant = FabricatedEmailChangeGrant::rehydrateIssued($grant);
        self::assertTrue($restored->emailChangeGrantRepository->add($restoredGrant));
        $completion = new InMemoryEmailChangeTransitionParticipant($restored->unitOfWork);
        $proof = AnonymousId::generate();
        $restored->now = $original->now->modify('+1 minute');
        $restored->emailChangeConfirmation($completion)->confirmEmail($restored->userId, 'email-fixture', $proof);

        $confirmed = $completion->intents[$grant->getId()->toString().':confirmed'];
        self::assertSame(EmailChangeTransitionStage::CONFIRMED, $confirmed->stage);
        self::assertEquals($requested->grantId, $confirmed->grantId);
        self::assertEquals($requested->userId, $confirmed->userId);
        self::assertSame($requested->reservationRevision, $confirmed->reservationRevision);
        self::assertEquals($requested->origin, $confirmed->origin);
        self::assertEquals($proof, $confirmed->confirmationActorId);
        self::assertEquals($proof, $restored->auditEvidenceRepository->all()[0]->actorId());
        self::assertEquals($restored->now, $confirmed->occurredAt);
        self::assertSame('destination@example.test', $restored->user()->getEmail()->canonical());
        self::assertInstanceOf(EmailChangeConfirmed::class, $restored->eventDispatcher->events()[0]);
        foreach ([$requested, $confirmed, $restored->auditEvidenceRepository->all()[0]->toArray()] as $facts) {
            self::assertStringNotContainsString('email-fixture', serialize($facts));
            self::assertStringNotContainsString($grant->getCredentialHash(), serialize($facts));
        }
    }

    public function test_cancelled_same_destination_successor_and_later_change_have_distinct_intent(): void
    {
        $fixture = new RecoveryFixture();
        $participant = new InMemoryEmailChangeTransitionParticipant($fixture->unitOfWork);
        $admin = UserId::generate();
        $fixture->requestEmail(participant: $participant, requesterId: $admin, audit: false);
        $fixture->cancelEmail();
        $fixture->requestEmail(credential: 'successor', participant: $participant, audit: false);

        $confirmation = $fixture->emailChangeConfirmation($participant, audit: false);
        foreach (['email-fixture', 'invalid'] as $rejectedCredential) {
            try {
                $confirmation->confirmEmail($fixture->userId, $rejectedCredential);
                self::fail('Stale or wrong proof accepted.');
            } catch (AuthenticationRequired) {
                self::assertSame(2, $participant->invocations);
            }
        }

        $confirmation->confirmEmail($fixture->userId, 'successor');
        $fixture->requestEmail('third@example.test', 'third', $participant, audit: false);
        /** @var list<EmailChangeTransition> $intents */
        $intents = array_values($participant->intents);
        self::assertCount(4, $intents);
        self::assertNotEquals($intents[0]->grantId, $intents[2]->grantId);
        self::assertEquals($intents[1]->grantId, $intents[2]->grantId);
        self::assertSame(3, $intents[2]->reservationRevision);
        self::assertEquals($fixture->userId, $intents[2]->origin->requesterId);
        self::assertNull($intents[2]->confirmationActorId);
        self::assertSame('recovery@example.test', $intents[2]->origin->oldMailbox);
        self::assertSame('destination@example.test', $intents[3]->origin->oldMailbox);
        self::assertCount(1, $fixture->auditEvidenceRepository->all()); // Cancellation only.
    }

    public static function failures(): iterable
    {
        foreach (['request', 'confirm'] as $operation) {
            foreach (['participant', 'audit', 'before commit', 'acknowledgement', 'publication'] as $phase) {
                yield $operation.' '.$phase => [$operation, $phase];
            }
        }
    }

    #[DataProvider('failures')]
    public function test_required_writes_and_uncertain_or_post_commit_failures(string $operation, string $phase): void
    {
        $fixture = new RecoveryFixture(auditFails: $phase === 'audit');
        $confirm = $operation === 'confirm';
        if ($confirm) {
            $fixture->authentication()->login('recovery@example.test', 'correct-secret');
            $fixture->request(audit: false);
            $fixture->requestEmail(audit: false);
        }

        $beforeReset = $confirm ? $fixture->grant() : null;
        $beforeSessions = $fixture->refreshSessionRepository->all();
        $before = $fixture->user();
        $beforeGrants = $fixture->emailChangeGrantRepository->all();
        $primary = new RuntimeException('Primary staged transition fault.');
        $participant = new InMemoryEmailChangeTransitionParticipant(
            $fixture->unitOfWork,
            $phase === 'participant' ? $primary : null
        );
        $throw = static function () use ($primary): never {
            throw $primary;
        };
        if ($phase === 'before commit') {
            $fixture->unitOfWork->beforeCommit = $throw;
        }

        if ($phase === 'acknowledgement') {
            $fixture->unitOfWork->afterCommit = $throw;
        }

        $successAttempts = 0;
        $fixture->eventDispatcher = new InMemoryEventDispatcher(
            static function ($event) use ($throw, $fixture, &$successAttempts): void {
                self::assertFalse($fixture->unitOfWork->transactionActive);
                if ($event instanceof EmailChangeRequested || $event instanceof EmailChangeConfirmed) {
                    ++$successAttempts;
                    $throw();
                }

                throw new RuntimeException('Secondary failure publication fault.');
            }
        );

        try {
            if ($confirm) {
                $fixture->emailChangeConfirmation($participant)->confirmEmail(
                    $fixture->userId,
                    'email-fixture',
                    AnonymousId::generate()
                );
            } else {
                $fixture->requestEmail(participant: $participant);
            }

            self::fail('Required fault was swallowed.');
        } catch (RuntimeException $runtimeException) {
            $expectedFault = $phase === 'audit' ? $fixture->auditEvidenceRepository->failure() : $primary;
            self::assertSame($expectedFault, $runtimeException);
        }

        self::assertSame(1, $participant->invocations);
        self::assertSame((int) ($phase === 'publication'), $successAttempts);
        $committed = in_array($phase, ['acknowledgement', 'publication'], true);
        self::assertCount((int) $committed, $participant->intents);
        self::assertCount((int) $committed, $fixture->auditEvidenceRepository->all());
        if (!$committed) {
            self::assertEquals($before, $fixture->user());
            self::assertSame($beforeGrants, $fixture->emailChangeGrantRepository->all());
            self::assertSame($beforeSessions, $fixture->refreshSessionRepository->all());
            if ($confirm) {
                self::assertSame($beforeReset, $fixture->grant());
            }
        } elseif ($confirm) {
            self::assertTrue($fixture->grant()->isRevoked());
            self::assertTrue($fixture->refreshSessionRepository->all()[0]->isRevoked());
            self::assertTrue($fixture->emailChangeGrantRepository->all()[0]->isConsumed());
            self::assertSame('destination@example.test', $fixture->user()->getEmail()->canonical());
        } else {
            self::assertTrue($fixture->emailChangeGrantRepository->all()[0]->isIssued());
            self::assertSame('destination@example.test', $fixture->user()->getPendingEmailChange()?->canonical());
        }
    }

    public function test_denied_and_duplicate_requests_and_ineligible_confirmation_do_not_stage(): void
    {
        $fixture = new RecoveryFixture();
        $participant = new InMemoryEmailChangeTransitionParticipant($fixture->unitOfWork);
        try {
            $fixture->requestEmail(participant: $participant, requesterId: UserId::generate(), authorize: false);
            self::fail('Unauthorized administrative request accepted.');
        } catch (PermissionDenied) {
            self::assertSame(0, $participant->invocations);
        }

        $fixture->requestEmail(participant: $participant);
        try {
            $fixture->requestEmail(participant: $participant);
            self::fail('A duplicate request was accepted.');
        } catch (StateConflict) {
            self::assertSame(1, $participant->invocations);
        }

        $confirmation = $fixture->emailChangeConfirmation($participant);
        try {
            $confirmation->confirmEmail($fixture->userId, 'email-fixture');
            self::fail('Missing required audit attribution was accepted.');
        } catch (AuthenticationRequired) {
            self::assertSame(1, $participant->invocations);
        }

        $confirmation->confirmEmail($fixture->userId, 'email-fixture', AnonymousId::generate());
        $this->expectException(AuthenticationRequired::class);
        try {
            $confirmation->confirmEmail($fixture->userId, 'email-fixture', AnonymousId::generate());
        } finally {
            self::assertSame(2, $participant->invocations);
            self::assertCount(2, $participant->intents);
        }
    }
}
