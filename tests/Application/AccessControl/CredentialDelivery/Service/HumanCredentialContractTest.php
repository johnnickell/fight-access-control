<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler as Activation;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler as Email;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler as Reset;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Activation\DeliverUserInvitationHandler::class)]
#[CoversClass(Activation\ExpireInvitationDeliveryHandler::class)]
#[CoversClass(Activation\ResendInvitationDeliveryHandler::class)]
#[CoversClass(Activation\RetryInvitationDeliveryHandler::class)]
#[CoversClass(Reset\DeliverPasswordResetHandler::class)]
#[CoversClass(Reset\ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(Reset\RequestPasswordResetHandler::class)]
#[CoversClass(Email\DeliverEmailChangeHandler::class)]
#[CoversClass(Email\ExpireEmailChangeHandler::class)]
#[CoversClass(Email\RequestEmailChangeHandler::class)]
#[CoversClass(Email\CancelEmailChangeHandler::class)]
final class HumanCredentialContractTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function latestOperations(): iterable
    {
        foreach (
            [
            'activation'     => ['deliver', 'expire', 'resend', 'retry'],
            'password_reset' => ['deliver', 'expire', 'request'],
            'email_change'   => ['deliver', 'expire', 'cancel']
            ] as $purpose => $operations
        ) {
            foreach ($operations as $operation) {
                yield $purpose.'.'.$operation => [$purpose, $purpose.'.'.$operation];
            }
        }
    }

    #[DataProvider('latestOperations')]
    public function test_wrong_latest_owner_is_an_internal_fault_not_absence_or_refusal(
        string $purpose,
        string $operation
    ): void {
        $fixture = new HumanCredentialContractFixture($purpose);
        $other = new HumanCredentialContractFixture($purpose);
        $activation = null;
        $reset = null;
        $email = null;
        if ($purpose === 'activation') {
            $activation = $this->createStub(ActivationGrantRepository::class);
            $activation->method('getByDeliveryId')->willReturn($fixture->state->initial);
            $activation->method('getLatestByUserId')->willReturn($other->state->initial);
        } elseif ($purpose === 'password_reset') {
            $reset = $this->createStub(PasswordResetGrantRepository::class);
            $reset->method('getByDeliveryId')->willReturn($fixture->state->initial);
            $reset->method('getLatestByUserId')->willReturn($other->state->initial);
        } else {
            $email = $this->createStub(EmailChangeGrantRepository::class);
            $email->method('getByDeliveryId')->willReturn($fixture->state->initial);
            $email->method('getLatestByUserId')->willReturn($other->state->initial);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The credential repository returned another User's generation.");
        try {
            $fixture->handler($operation, $activation, $reset, $email)->handle(
                CommandMessage::create($fixture->command($operation))
            );
        } finally {
            self::assertSame([], $fixture->provider->invocations());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->state->eventDispatcher->events()[0] ?? null);
            self::assertSame(0, $fixture->state->current()->getRevision());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function deliveryLookups(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach (['deliver', 'expire'] as $operation) {
                if ($purpose === 'email_change' && $operation === 'expire') {
                    continue;
                }

                yield $purpose.'.'.$operation => [$purpose, $purpose.'.'.$operation];
            }
        }
    }

    #[DataProvider('deliveryLookups')]
    public function test_wrong_delivery_lookup_is_an_internal_fault(string $purpose, string $operation): void
    {
        $fixture = new HumanCredentialContractFixture($purpose);
        $other = new HumanCredentialContractFixture($purpose);
        $activation = null;
        $reset = null;
        $email = null;
        if ($purpose === 'activation') {
            $activation = $this->createStub(ActivationGrantRepository::class);
            $activation->method('getByDeliveryId')->willReturn($other->state->initial);
            $activation->method('getLatestByUserId')->willReturn($fixture->state->initial);
        } elseif ($purpose === 'password_reset') {
            $reset = $this->createStub(PasswordResetGrantRepository::class);
            $reset->method('getByDeliveryId')->willReturn($other->state->initial);
            $reset->method('getLatestByUserId')->willReturn($fixture->state->initial);
        } else {
            $email = $this->createStub(EmailChangeGrantRepository::class);
            $email->method('getByDeliveryId')->willReturn($other->state->initial);
            $email->method('getLatestByUserId')->willReturn($fixture->state->initial);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The credential repository returned another delivery.');
        $fixture->handler($operation, $activation, $reset, $email)->handle(
            CommandMessage::create($fixture->command($operation))
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function userLookups(): iterable
    {
        yield 'reset claim' => ['password_reset', 'password_reset.deliver'];
        yield 'email request' => ['email_change', 'email_change.request'];
        yield 'email cancel' => ['email_change', 'email_change.cancel'];
    }

    #[DataProvider('userLookups')]
    public function test_wrong_user_lookup_is_an_internal_fault(string $purpose, string $operation): void
    {
        $fixture = new HumanCredentialContractFixture($purpose);
        $users = $this->createStub(UserRepository::class);
        $users->method('getById')->willReturn(UserFixture::withState('other@example.test', UserState::ACTIVE));
        $users->method('validateRecoveryAuthority')->willReturn(true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The User repository returned another identity.');
        $fixture->handler($operation, users: $users)->handle(CommandMessage::create($fixture->command($operation)));
    }

    public function test_reset_email_lookup_cannot_issue_for_an_unrelated_canonical_address(): void
    {
        $fixture = new HumanCredentialContractFixture('password_reset');
        $users = $this->createStub(UserRepository::class);
        $users->method('getByEmail')->willReturn(UserFixture::withState('other@example.test', UserState::ACTIVE));
        $users->method('validateRecoveryAuthority')->willReturn(true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The User repository returned another canonical email.');
        $fixture->handler('password_reset.request', users: $users)->handle(
            CommandMessage::create($fixture->command('password_reset.request'))
        );
    }

    /** @return iterable<string, array{string}> */
    public static function families(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            yield $purpose => [$purpose];
        }
    }

    #[DataProvider('families')]
    public function test_invocation_clock_failure_is_not_an_unexpected_provider_result(string $purpose): void
    {
        $fixture = new HumanCredentialContractFixture($purpose);
        $command = $fixture->command($purpose.'.deliver');
        $reset = null;
        if ($purpose === 'password_reset') {
            $grant = PasswordResetGrant::issue(
                $fixture->state->user->getId(),
                PasswordResetCredential::fromString('fixture'),
                $fixture->state->issuedAt,
                $fixture->state->expiresAt,
                $fixture->state->user->getEmail(),
                'ciphertext:fixture',
                $fixture->state->user->getRecoveryAuthority()
            );
            $reset = new InMemoryPasswordResetGrants($fixture->state->unitOfWork);
            self::assertTrue($reset->add($grant));
            $command = new DeliverPasswordReset($fixture->actorId, $grant->getUserId(), $grant->getDelivery()->getId());
        }

        $failure = new RuntimeException('synthetic-clock-fault');
        $clock = $this->createStub(Clock::class);
        $calls = 0;
        $clock->method('now')->willReturnCallback(static function () use (&$calls, $failure, $fixture) {
            if (++$calls === 2) {
                throw $failure;
            }

            return $fixture->state->issuedAt;
        });
        try {
            $fixture->handler($purpose.'.deliver', reset: $reset, clock: $clock)->handle(
                CommandMessage::create($command)
            );
            self::fail('An internal clock fault became a provider outcome.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        self::assertSame([], $fixture->provider->invocations());
        self::assertInstanceOf(CommandFailedEvent::class, $fixture->state->eventDispatcher->events()[0]);
    }

    #[DataProvider('families')]
    public function test_same_revision_cannot_describe_another_claim_at_outcome(string $purpose): void
    {
        $fixture = new HumanCredentialContractFixture($purpose);
        $grant = $fixture->state->initial;
        $command = $fixture->command($purpose.'.deliver');
        if ($purpose === 'password_reset') {
            $grant = PasswordResetGrant::issue(
                $fixture->state->user->getId(),
                PasswordResetCredential::fromString('fixture'),
                $fixture->state->issuedAt,
                $fixture->state->expiresAt,
                $fixture->state->user->getEmail(),
                'ciphertext:fixture',
                $fixture->state->user->getRecoveryAuthority()
            );
            $command = new DeliverPasswordReset($fixture->actorId, $grant->getUserId(), $grant->getDelivery()->getId());
        }

        $otherClaim = $grant->claimDelivery(
            CredentialDeliveryClaimToken::generate(),
            $fixture->state->issuedAt,
            $fixture->state->issuedAt->modify('+5 minutes')
        );
        $activation = null;
        $reset = null;
        $email = null;
        if ($purpose === 'activation') {
            $activation = $this->createStub(ActivationGrantRepository::class);
            $activation->method('getByDeliveryId')->willReturn($grant, $otherClaim);
            $activation->method('getLatestByUserId')->willReturn($grant, $otherClaim);
            $activation->method('replace')->willReturn(true);
        } elseif ($purpose === 'password_reset') {
            $reset = $this->createStub(PasswordResetGrantRepository::class);
            $reset->method('getByDeliveryId')->willReturn($grant, $otherClaim);
            $reset->method('getLatestByUserId')->willReturn($grant, $otherClaim);
            $reset->method('replace')->willReturn(true);
        } else {
            $email = $this->createStub(EmailChangeGrantRepository::class);
            $email->method('getByDeliveryId')->willReturn($grant, $otherClaim);
            $email->method('getLatestByUserId')->willReturn($grant, $otherClaim);
            $email->method('replace')->willReturn(true);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The credential repository changed claim state without a revision.');
        $fixture->handler($purpose.'.deliver', $activation, $reset, $email)->handle(CommandMessage::create($command));
    }

    /** @return iterable<string, array{string, string}> */
    public static function timeBoundaries(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            foreach (['claim', 'invocation', 'outcome'] as $phase) {
                yield $purpose.'.'.$phase => [$purpose, $phase];
            }
        }
    }

    #[DataProvider('timeBoundaries')]
    public function test_exact_claim_expiry_and_lease_boundaries_remain_conflicts(string $purpose, string $phase): void
    {
        $fixture = new HumanCredentialContractFixture($purpose);
        $issued = $fixture->state->issuedAt->format('Y-m-d\TH:i:s.uP');
        $expired = $fixture->state->expiresAt->format('Y-m-d\TH:i:s.uP');
        $leaseEnd = $fixture->state->issuedAt->modify('+5 minutes')->format('Y-m-d\TH:i:s.uP');
        $clock = match ($phase) {
            'claim' => new FixedClock($expired),
            'invocation' => new FixedClock($issued, $leaseEnd),
            'outcome' => new FixedClock($issued, $issued, $leaseEnd),
            default => throw new LogicException('Unknown fixture phase.')
        };
        try {
            $fixture->handler($purpose.'.deliver', clock: $clock)->handle(
                CommandMessage::create($fixture->command($purpose.'.deliver'))
            );
            self::fail('A closed time boundary was accepted.');
        } catch (StateConflict) {
            self::assertCount($phase === 'outcome' ? 1 : 0, $fixture->provider->invocations());
            self::assertSame($phase === 'claim' ? 0 : 1, $fixture->state->current()->getRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $fixture->state->eventDispatcher->events()[0]);
        }
    }

    public function test_outcome_cannot_use_a_different_User_from_the_requested_identity(): void
    {
        $fixture = new HumanCredentialContractFixture('password_reset');
        $users = $this->createStub(UserRepository::class);
        $users->method('getById')->willReturn(
            $fixture->state->user,
            UserFixture::withState('other@example.test', UserState::ACTIVE)
        );
        $users->method('validateRecoveryAuthority')->willReturn(true);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The User repository returned another identity.');
        $fixture->handler('password_reset.deliver', users: $users)->handle(
            CommandMessage::create($fixture->command('password_reset.deliver'))
        );
    }

    public function test_request_rejects_another_Users_predecessor_and_rolls_back_its_reservation(): void
    {
        $fixture = new HumanCredentialContractFixture('email_change');
        $user = clone $fixture->state->user;
        $user->cancelEmailChange($fixture->state->issuedAt);
        self::assertTrue($fixture->state->userRepository->replaceEmailChangeReservation($fixture->state->user, $user));
        $other = new HumanCredentialContractFixture('email_change');
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $email->method('getLatestByUserId')->willReturn($other->state->initial);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("The credential repository returned another User's generation.");
        try {
            $fixture->handler('email_change.request', email: $email)->handle(
                CommandMessage::create($fixture->command('email_change.request'))
            );
        } finally {
            self::assertNull($fixture->state->userRepository->getById($user->getId())?->getPendingEmailChange());
        }
    }

    public function test_inactive_cancellation_is_a_conflict_without_clearing_the_reservation(): void
    {
        $fixture = new HumanCredentialContractFixture('email_change');
        $user = clone $fixture->state->user;
        $user->disable($fixture->state->issuedAt);
        self::assertTrue($fixture->state->userRepository->replaceLifecycleState($fixture->state->user, $user));
        $this->expectException(StateConflict::class);
        try {
            $fixture->handler('email_change.cancel')->handle(
                CommandMessage::create($fixture->command('email_change.cancel'))
            );
        } finally {
            self::assertNotNull($fixture->state->userRepository->getById($user->getId())?->getPendingEmailChange());
            self::assertSame(0, $fixture->state->current()->getRevision());
        }
    }

    public function test_cancellation_requires_the_exact_reservation_generation_not_only_equal_email(): void
    {
        $fixture = new HumanCredentialContractFixture('email_change');
        $grant = EmailChangeGrant::issue(
            $fixture->state->user->getId(),
            EmailChangeCredential::fromString('fixture'),
            $fixture->state->issuedAt,
            $fixture->state->expiresAt,
            EmailAddress::fromString('next@example.test'),
            'fixture:encrypted',
            $fixture->state->user->getEmailChangeReservationRevision() + 2
        );
        $email = $this->createStub(EmailChangeGrantRepository::class);
        $email->method('getLatestByUserId')->willReturn($grant);
        $email->method('replace')->willReturn(true);

        $this->expectException(LogicException::class);
        try {
            $fixture->handler('email_change.cancel', email: $email)->handle(
                CommandMessage::create($fixture->command('email_change.cancel'))
            );
        } finally {
            self::assertNotNull($fixture->state->userRepository->getById(
                $fixture->state->user->getId()
            )?->getPendingEmailChange());
        }
    }
}
