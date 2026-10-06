<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Closure;
use DateInterval;
use Fiber;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ExpireInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\CancelEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\ExpireEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\CancelEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeConfirmationRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Auth\Security\TokenEncoder;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service as EmailChangeService;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(ExpireInvitationDeliveryHandler::class)]
#[CoversClass(ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(ExpireEmailChangeHandler::class)]
#[CoversClass(ResendInvitationDeliveryHandler::class)]
#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(CancelEmailChangeHandler::class)]
#[CoversClass(RequestEmailChangeHandler::class)]
#[CoversClass(AuthenticationService::class)]
final class ExpiredCredentialInterleavingTest extends TestCase
{
    #[DataProvider('cleanupWorkers')]
    public function test_two_transactions_read_the_same_predecessor_and_only_the_winning_worker_publishes(
        string $purpose,
        string $winner
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $before = $fixture->stage('reclaimed');
        $loser = $winner === 'worker:a' ? 'worker:b' : 'worker:a';
        $loserEvents = new InMemoryEventDispatcher();
        $handler = $this->pausedCleanup($fixture, $loserEvents);
        $fiber = new Fiber(static fn () => $handler->handle(CommandMessage::create(
            $fixture->command(actor: $loser)
        )));
        $fiber->start();
        self::assertTrue($fiber->isSuspended());
        self::assertSame($before, $fixture->current());
        self::assertCount(0, $fixture->events->events());
        $fixture->handler()->handle(CommandMessage::create($fixture->command(actor: $winner)));
        $persisted = $fixture->current();
        $persistedUser = $fixture->users->getById($fixture->user->getId());
        self::assertInstanceOf(User::class, $persistedUser);
        $userState = self::userState($persistedUser);
        $failure = $this->resume($fiber);
        if ($purpose === 'email_change') {
            self::assertInstanceOf(LogicException::class, $failure);
            self::assertSame('The email-change reservation changed concurrently.', $failure->getMessage());
        } else {
            self::assertNull($failure);
        }

        self::assertSame($persisted, $fixture->current());
        self::assertSame($userState, self::userState($fixture->users->getById($fixture->user->getId())));
        self::assertFalse($persisted->getDelivery()->hasRecoverableMaterial());
        self::assertNull($persisted->getDelivery()->getClaimToken());
        self::assertNull($persisted->getDelivery()->getClaimedAt());
        self::assertNull($persisted->getDelivery()->getLeaseUntil());
        self::assertSame($before->getDelivery()->getAttemptCount(), $persisted->getDelivery()->getAttemptCount());
        self::assertEquals($before->getDelivery()->getLastOutcomeAt(), $persisted->getDelivery()->getLastOutcomeAt());
        self::assertSame($before->getDelivery()->getLastFailure(), $persisted->getDelivery()->getLastFailure());
        self::assertCount(1, $fixture->events->events());
        self::assertSame($winner, $fixture->events->events()[0]->toArray()['actor_id']);
        self::assertNoSuccess($loserEvents);
        $fixture->handler()->handle(CommandMessage::create($fixture->command(actor: $loser)));
        self::assertSame($persisted, $fixture->current());
        self::assertCount(1, $fixture->events->events());
        if ($purpose === 'email_change') {
            self::assertNull($persistedUser->getPendingEmailChange());
            self::assertSame(2, $persistedUser->getEmailChangeReservationRevision());
        }
    }

    #[DataProvider('replacementOrders')]
    public function test_replacement_between_authoritative_read_and_CAS_preserves_the_winner_and_allows_retry(
        string $purpose,
        bool $cleanupWins
    ): void {
        $fixture = new ExpiredCredentialFixture($purpose);
        $before = $fixture->stage('reclaimed');
        $loserEvents = new InMemoryEventDispatcher();
        $cleanup = $fixture->handler(events: $cleanupWins ? $fixture->events : $loserEvents);
        $replacement = $this->replacement($fixture, $cleanupWins, $cleanupWins ? $loserEvents : $fixture->events);
        if (!$cleanupWins) {
            $cleanup = $this->pausedCleanup($fixture, $loserEvents);
        }

        $cleanupCall = static fn () => $cleanup->handle(CommandMessage::create($fixture->command()));
        $fiber = new Fiber($cleanupWins ? $replacement : $cleanupCall);
        $fiber->start();
        self::assertTrue($fiber->isSuspended());
        self::assertSame($before, $fixture->current());
        ($cleanupWins ? $cleanupCall : $replacement)();
        $persisted = $fixture->current();
        $failure = $this->resume($fiber);
        if ($cleanupWins) {
            self::assertInstanceOf(LogicException::class, $failure);
            $message = 'Password-reset authority changed concurrently.';
            if ($purpose === 'activation') {
                $message = 'The activation grant changed concurrently.';
            }

            self::assertSame($message, $failure->getMessage());
        } else {
            self::assertNull($failure);
        }

        self::assertSame($persisted, $fixture->current());
        self::assertNoSuccess($loserEvents);
        self::assertCount(1, $fixture->events->events());
        if ($cleanupWins) {
            self::assertTrue($persisted->getId()->equals($before->getId()));
            self::assertSame(CredentialDeliveryStatus::EXPIRED, $persisted->getDelivery()->getStatus());
            self::assertSame($before->getDelivery()->getLastFailure(), $persisted->getDelivery()->getLastFailure());
            // Replacement may retry from the cleaned, still-issued delivery-only generation.
            ($this->replacement($fixture, false, $fixture->events))();
            $persisted = $fixture->current();
        }

        self::assertFalse($persisted->getId()->equals($before->getId()));
        self::assertFalse($persisted->getDelivery()->getId()->equals($before->getDelivery()->getId()));
        self::assertTrue($persisted->isIssued());
        self::assertTrue($persisted->getDelivery()->hasRecoverableMaterial());
        self::assertSame(0, $persisted->getDelivery()->getAttemptCount());
        self::assertNull($persisted->getDelivery()->getLastFailure());
        $old = $purpose === 'activation' ? $fixture->activation->all()[0] : $fixture->reset->all()[0];
        self::assertTrue($old->isRevoked());
        self::assertFalse($old->getDelivery()->hasRecoverableMaterial());
        self::assertSame($before->getDelivery()->getAttemptCount(), $old->getDelivery()->getAttemptCount());
        self::assertSame($before->getDelivery()->getLastFailure(), $old->getDelivery()->getLastFailure());
        $count = count($fixture->events->events());
        $fixture->handler()->handle(CommandMessage::create($fixture->command($persisted->getExpiresAt())));
        self::assertSame($persisted, $fixture->current());
        self::assertCount($count, $fixture->events->events());
        self::assertSame([], $fixture->discovery()->handle(QueryMessage::create(
            new FindExpiredCredentialDeliveries($fixture->expiresAt, 50)
        )));
    }

    #[DataProvider('emailOrders')]
    public function test_coupled_email_cancel_consume_and_same_email_replacement_interleave_with_expiry(
        string $competitor,
        bool $cleanupWins
    ): void {
        $fixture = new ExpiredCredentialFixture('email_change');
        $before = $fixture->stage('reclaimed');
        self::assertInstanceOf(EmailChangeGrant::class, $before);
        $loserEvents = new InMemoryEventDispatcher();
        $cleanup = $fixture->handler(events: $cleanupWins ? $fixture->events : $loserEvents);
        $other = $this->emailCompetitor(
            $fixture,
            $competitor,
            $cleanupWins,
            $cleanupWins ? $loserEvents : $fixture->events
        );
        if (!$cleanupWins) {
            $cleanup = $this->pausedCleanup($fixture, $loserEvents);
        }

        $cleanupCall = static fn () => $cleanup->handle(CommandMessage::create($fixture->command()));
        $fiber = new Fiber($cleanupWins ? $other : $cleanupCall);
        $fiber->start();
        self::assertTrue($fiber->isSuspended());
        self::assertSame($before, $fixture->current());
        self::assertNotNull($fixture->users->getById($fixture->user->getId())?->getPendingEmailChange());
        ($cleanupWins ? $cleanupCall : $other)();
        $persisted = $fixture->current();
        $persistedUser = $fixture->users->getById($fixture->user->getId());
        self::assertInstanceOf(User::class, $persistedUser);
        $userState = self::userState($persistedUser);
        $failure = $this->resume($fiber);
        if ($cleanupWins && $competitor === 'consume') {
            self::assertInstanceOf(EmailChangeConfirmationRejectedException::class, $failure);
            self::assertSame('Email change confirmation rejected.', $failure->getMessage());
        } else {
            self::assertInstanceOf(LogicException::class, $failure);
            self::assertSame('The email-change reservation changed concurrently.', $failure->getMessage());
        }

        self::assertSame($persisted, $fixture->current());
        self::assertSame($userState, self::userState($fixture->users->getById($fixture->user->getId())));
        self::assertNoSuccess($loserEvents);
        self::assertCount(!$cleanupWins && $competitor === 'replacement' ? 2 : 1, $fixture->events->events());
        self::assertSame($fixture->user->getState(), $persistedUser->getState());
        self::assertEquals($fixture->user->getPasswordHash(), $persistedUser->getPasswordHash());
        self::assertEquals($fixture->user->getRoleIds(), $persistedUser->getRoleIds());
        self::assertSame(
            $fixture->user->getAuthorizationAssignmentRevision(),
            $persistedUser->getAuthorizationAssignmentRevision()
        );
        $old = $fixture->email->all()[0];
        self::assertFalse($old->getDelivery()->hasRecoverableMaterial());
        self::assertNull($old->getDelivery()->getClaimToken());
        self::assertNull($old->getDelivery()->getClaimedAt());
        self::assertNull($old->getDelivery()->getLeaseUntil());
        self::assertSame($before->getDelivery()->getAttemptCount(), $old->getDelivery()->getAttemptCount());
        self::assertSame($before->getDelivery()->getLastFailure(), $old->getDelivery()->getLastFailure());
        self::assertEquals($before->getDelivery()->getLastOutcomeAt(), $old->getDelivery()->getLastOutcomeAt());
        if (!$cleanupWins && $competitor === 'consume') {
            self::assertTrue($persisted->isConsumed());
            self::assertSame('next@example.test', $persistedUser->getEmail()->canonical());
            self::assertSame(
                $fixture->user->getAuthenticationVersion() + 1,
                $persistedUser->getAuthenticationVersion()
            );
            self::assertSame(
                $fixture->user->getAuthenticationAuthorityRevision() + 1,
                $persistedUser->getAuthenticationAuthorityRevision()
            );
        } else {
            self::assertSame($fixture->user->getEmail()->canonical(), $persistedUser->getEmail()->canonical());
            self::assertSame($fixture->user->getAuthenticationVersion(), $persistedUser->getAuthenticationVersion());
            self::assertSame(
                $fixture->user->getAuthenticationAuthorityRevision(),
                $persistedUser->getAuthenticationAuthorityRevision()
            );
        }

        if (!$cleanupWins && $competitor === 'replacement') {
            self::assertFalse($persisted->getId()->equals($before->getId()));
            self::assertTrue($persisted->isIssued());
            self::assertTrue($persisted->getDelivery()->hasRecoverableMaterial());
            self::assertSame(3, $persistedUser->getEmailChangeReservationRevision());
            self::assertSame(3, $persisted->getEmailChangeReservationRevision());
            self::assertSame('next@example.test', $persistedUser->getPendingEmailChange()?->canonical());
            self::assertTrue($fixture->email->all()[0]->isRevoked());
        } else {
            self::assertFalse($persisted->getDelivery()->hasRecoverableMaterial());
            self::assertNull($persistedUser->getPendingEmailChange());
            self::assertSame(2, $persistedUser->getEmailChangeReservationRevision());
            self::assertSame($before->getDelivery()->getAttemptCount(), $persisted->getDelivery()->getAttemptCount());
            self::assertSame($before->getDelivery()->getLastFailure(), $persisted->getDelivery()->getLastFailure());
            self::assertEquals(
                $before->getDelivery()->getLastOutcomeAt(),
                $persisted->getDelivery()->getLastOutcomeAt()
            );
            self::assertSame($cleanupWins, $persisted->isExpired());
            self::assertSame(!$cleanupWins && $competitor === 'cancel', $persisted->isRevoked());
        }

        // Replaying the old exact command cannot affect either terminal authority or an ABA successor.
        $count = count($fixture->events->events());
        $fixture->handler()->handle(CommandMessage::create($fixture->command($fixture->expiresAt->modify('+2 hours'))));
        self::assertSame($persisted, $fixture->current());
        self::assertSame($userState, self::userState($fixture->users->getById($fixture->user->getId())));
        self::assertCount($count, $fixture->events->events());
        if ($cleanupWins && $competitor === 'replacement') {
            $this->requestEmail($fixture);
            $successor = $fixture->current();
            self::assertInstanceOf(EmailChangeGrant::class, $successor);
            self::assertSame(3, $successor->getEmailChangeReservationRevision());
            $fixture->handler()->handle(CommandMessage::create($fixture->command($successor->getExpiresAt())));
            self::assertSame($successor, $fixture->current());
            self::assertSame(
                'next@example.test',
                $fixture->users->getById($fixture->user->getId())?->getPendingEmailChange()?->canonical()
            );
        }
    }

    #[DataProvider('emailDeliveryOrders')]
    public function test_email_delivery_winner_rolls_back_partial_expiry_and_fresh_cleanup_drains(
        string $outcome,
        bool $cleanupWins
    ): void {
        $fixture = new ExpiredCredentialFixture('email_change');
        $before = $fixture->stage('reclaimed');
        self::assertInstanceOf(EmailChangeGrant::class, $before);
        $token = $before->getDelivery()->getClaimToken();
        self::assertNotNull($token);
        $at = $fixture->issuedAt->modify('+121 seconds');
        $delivery = match ($outcome) {
            'delivered' => $before->confirmDelivery($token, $at),
            'retry' => $before->failDelivery($token, $at, CredentialDeliveryFailure::RETRYABLE_PROVIDER),
            'permanent' => $before->failDeliveryPermanently($token, $at),
            default => $before->expireDeliveryAt($fixture->expiresAt),
        };
        $repository = $fixture->email;
        if ($cleanupWins) {
            $repository = $this->proxy(EmailChangeGrantRepository::class, $repository, 'replace', $fixture);
        }

        // A recorded delivery outcome competes through the repository transaction seam;
        // no provider is invoked and no worker success publication is claimed here.
        $deliveryCall = static fn (): mixed => $fixture->unitOfWork->commitTransactional(
            static fn (): bool => $repository->replace($before, $delivery)
        );
        $loserEvents = new InMemoryEventDispatcher();
        $cleanup = $fixture->handler();
        if (!$cleanupWins) {
            $cleanup = $this->pausedCleanup($fixture, $loserEvents);
        }

        $cleanupCall = static fn () => $cleanup->handle(CommandMessage::create($fixture->command()));
        $fiber = new Fiber($cleanupWins ? $deliveryCall : $cleanupCall);
        $fiber->start();
        self::assertTrue($fiber->isSuspended());
        ($cleanupWins ? $cleanupCall : $deliveryCall)();
        $persisted = $fixture->current();
        $userState = self::userState($fixture->users->getById($fixture->user->getId()));
        $failure = $this->resume($fiber);
        self::assertSame($persisted, $fixture->current());
        self::assertSame($userState, self::userState($fixture->users->getById($fixture->user->getId())));
        if ($cleanupWins) {
            self::assertNull($failure);
            self::assertFalse($fiber->getReturn());
            self::assertTrue($persisted->isExpired());
            self::assertNull($fixture->users->getById($fixture->user->getId())?->getPendingEmailChange());
        } else {
            self::assertInstanceOf(LogicException::class, $failure);
            self::assertSame('Email-change authority changed concurrently.', $failure->getMessage());
            self::assertSame($delivery, $persisted);
            self::assertSame(self::userState($fixture->user), $userState);
            self::assertCount(0, $fixture->events->events());
            self::assertNoSuccess($loserEvents);
            $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        }

        $expired = $fixture->current();
        self::assertTrue($expired->isExpired());
        self::assertFalse($expired->getDelivery()->hasRecoverableMaterial());
        self::assertNull($expired->getDelivery()->getClaimToken());
        self::assertNull($fixture->users->getById($fixture->user->getId())?->getPendingEmailChange());
        self::assertSame(2, $fixture->users->getById($fixture->user->getId())?->getEmailChangeReservationRevision());
        $expectedStatus = $delivery->getDelivery()->getStatus();
        if ($cleanupWins || $outcome === 'retry') {
            $expectedStatus = CredentialDeliveryStatus::INVALIDATED;
        }

        self::assertSame($expectedStatus, $expired->getDelivery()->getStatus());
        self::assertEquals(
            ($cleanupWins ? $before : $delivery)->getDelivery()->getLastOutcomeAt(),
            $expired->getDelivery()->getLastOutcomeAt()
        );
        self::assertSame(
            ($cleanupWins ? $before : $delivery)->getDelivery()->getLastFailure(),
            $expired->getDelivery()->getLastFailure()
        );
        self::assertCount(1, $fixture->events->events());
        $fixture->handler()->handle(CommandMessage::create($fixture->command()));
        self::assertSame($expired, $fixture->current());
        self::assertCount(1, $fixture->events->events());
    }

    private function pausedCleanup(ExpiredCredentialFixture $fixture, InMemoryEventDispatcher $events): CommandHandler
    {
        return match ($fixture->purpose) {
            'activation' => new ExpireInvitationDeliveryHandler(
                $this->proxy(ActivationGrantRepository::class, $fixture->activation, 'replace', $fixture),
                $fixture->unitOfWork,
                $events
            ),
            'password_reset' => new ExpirePasswordResetDeliveryHandler(
                $this->proxy(PasswordResetGrantRepository::class, $fixture->reset, 'replace', $fixture),
                $fixture->unitOfWork,
                $events
            ),
            default => new ExpireEmailChangeHandler(
                $this->proxy(UserRepository::class, $fixture->users, 'replaceEmailChangeReservation', $fixture),
                $fixture->email,
                $fixture->unitOfWork,
                $events
            ),
        };
    }

    private function replacement(
        ExpiredCredentialFixture $fixture,
        bool $pause,
        InMemoryEventDispatcher $events
    ): Closure {
        $audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork);
        $clock = new FixedClock($fixture->expiresAt->format('c'));
        if ($fixture->purpose === 'activation') {
            $repository = $fixture->activation;
            if ($pause) {
                $repository = $this->proxy(
                    ActivationGrantRepository::class,
                    $repository,
                    'replaceWithSuccessor',
                    $fixture
                );
            }

            $handler = new ResendInvitationDeliveryHandler(
                $repository,
                $audit,
                $fixture->unitOfWork,
                new FixedCredentialGenerator('replacement'),
                new PrefixInvitationDeliveryCipher(),
                $clock,
                $events
            );

            return static fn () => $handler->handle(CommandMessage::create(new ResendInvitationDelivery(
                'worker:replacement',
                $fixture->user->getId()
            )));
        }

        $repository = $fixture->reset;
        if ($pause) {
            $repository = $this->proxy(
                PasswordResetGrantRepository::class,
                $repository,
                'replaceWithSuccessor',
                $fixture
            );
        }

        $handler = new RequestPasswordResetHandler(
            $fixture->users,
            $repository,
            $audit,
            $fixture->unitOfWork,
            new FixedPasswordResetCredentialGenerator('replacement'),
            new PrefixPasswordResetDeliveryCipher(),
            $clock,
            $events
        );

        return static fn () => $handler->handle(CommandMessage::create(new RequestPasswordReset(
            $fixture->user->getEmail()
        )));
    }

    private function emailCompetitor(
        ExpiredCredentialFixture $fixture,
        string $competitor,
        bool $pause,
        InMemoryEventDispatcher $events
    ): Closure {
        $users = $fixture->users;
        $grants = $fixture->email;
        if ($pause) {
            if ($competitor === 'consume') {
                $grants = $this->proxy(EmailChangeGrantRepository::class, $grants, 'replace', $fixture);
            } else {
                $users = $this->proxy(UserRepository::class, $users, 'replaceEmailChangeReservation', $fixture);
            }
        }

        $audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork);
        // Confirmation reads a trusted time before expiry, then loses or wins against later cleanup.
        $clock = new FixedClock($fixture->expiresAt->modify('-1 microsecond')->format('Y-m-d\TH:i:s.uP'));
        if ($competitor === 'consume') {
            $passwordHash = $fixture->user->getPasswordHash();
            self::assertNotNull($passwordHash);
            $sessions = new InMemoryRefreshSessionRepository($fixture->unitOfWork);
            $service = new AuthenticationService(
                $users,
                $fixture->activation,
                $sessions,
                new SessionRevocationService($sessions),
                $fixture->unitOfWork,
                $clock,
                $this->createStub(LoginThrottle::class),
                $this->createStub(RefreshCredentialGenerator::class),
                $this->createStub(PasswordHasher::class),
                $this->createStub(PasswordValidator::class),
                $this->createStub(TokenEncoder::class),
                AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT1S')),
                $passwordHash,
                $events,
                $fixture->reset,
                $audit,
                $grants
            );

            return static fn () => $service->confirmEmail($fixture->user->getId(), 'fixture');
        }

        $handler = new CancelEmailChangeHandler(
            $users,
            $grants,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            $audit,
            $fixture->unitOfWork,
            $clock,
            $events
        );

        return function () use ($fixture, $handler, $competitor): void {
            $handler->handle(CommandMessage::create(new CancelEmailChange(
                $fixture->user->getId(),
                $fixture->user->getId()
            )));
            if ($competitor === 'replacement') {
                $this->requestEmail($fixture);
            }
        };
    }

    private function requestEmail(ExpiredCredentialFixture $fixture): void
    {
        $handler = new RequestEmailChangeHandler(
            $fixture->users,
            $fixture->email,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($fixture->unitOfWork),
            $fixture->unitOfWork,
            new EmailChangeService\FixedEmailChangeCredentialGenerator('replacement'),
            new EmailChangeService\PrefixEmailChangeDeliveryCipher(),
            new FixedClock($fixture->expiresAt->format('Y-m-d\TH:i:s.uP')),
            $fixture->events
        );
        $handler->handle(CommandMessage::create(new RequestEmailChange(
            $fixture->user->getId(),
            $fixture->user->getId(),
            EmailAddress::fromString('next@example.test')
        )));
    }

    /**
     * Delegates the actual repository contract and pauses once before its first CAS write
     *
     * @template T of object
     * @param class-string<T> $interface
     * @param T $repository
     * @return T
     */
    private function proxy(
        string $interface,
        object $repository,
        string $pauseMethod,
        ExpiredCredentialFixture $fixture
    ): object {
        $proxy = $this->createStub($interface);
        $armed = true;
        foreach (get_class_methods($interface) as $method) {
            $proxy->method($method)->willReturnCallback(static function (...$arguments) use (
                $repository,
                $method,
                $pauseMethod,
                $fixture,
                &$armed
            ): mixed {
                if ($armed && $method === $pauseMethod) {
                    $armed = false;
                    $fixture->unitOfWork->suspendBeforeFirstWrite();
                }

                return $repository->{$method}(...$arguments);
            });
        }

        return $proxy;
    }

    /** @param Fiber<mixed, mixed, mixed, mixed> $fiber */
    private function resume(Fiber $fiber): ?Throwable
    {
        try {
            $fiber->resume();

            return null;
        } catch (Throwable $throwable) {
            return $throwable;
        } finally {
            self::assertTrue($fiber->isTerminated());
        }
    }

    /** @return list<mixed> */
    private static function userState(?User $user): array
    {
        self::assertInstanceOf(User::class, $user);

        return [
            $user->getId()->toString(), $user->getEmail()->canonical(), $user->getState(),
            $user->getPasswordHash()?->toString(), $user->getAuthenticationVersion(),
            $user->getAuthenticationAuthorityRevision(), $user->getAuthorizationAssignmentRevision(),
            array_map(static fn (RoleId $id): string => $id->toString(), $user->getRoleIds()),
            $user->getCanonicalEmailRevision(), $user->getPendingEmailChange()?->canonical(),
            $user->getEmailChangeReservationRevision(), $user->getCreatedAt()->format('Y-m-d\\TH:i:s.uP'),
            $user->getUpdatedAt()->format('Y-m-d\\TH:i:s.uP')
        ];
    }

    private static function assertNoSuccess(InMemoryEventDispatcher $events): void
    {
        foreach ($events->events() as $event) {
            self::assertTrue($event instanceof CommandFailedEvent || $event instanceof RedactedCommandFailed);
        }
    }

    /** @return list<array{string, string}> */
    public static function cleanupWorkers(): array
    {
        return [
            ['activation', 'worker:a'], ['activation', 'worker:b'],
            ['password_reset', 'worker:a'], ['password_reset', 'worker:b'],
            ['email_change', 'worker:a'], ['email_change', 'worker:b']
        ];
    }

    /** @return list<array{string, bool}> */
    public static function replacementOrders(): array
    {
        return [['activation', true], ['activation', false], ['password_reset', true], ['password_reset', false]];
    }

    /** @return list<array{string, bool}> */
    public static function emailDeliveryOrders(): array
    {
        return [
            ['delivered', true], ['delivered', false], ['retry', true], ['retry', false],
            ['permanent', true], ['permanent', false], ['delivery-expired', true], ['delivery-expired', false]
        ];
    }

    /** @return list<array{string, bool}> */
    public static function emailOrders(): array
    {
        return [
            ['cancel', true], ['cancel', false],
            ['consume', true], ['consume', false],
            ['replacement', true], ['replacement', false]
        ];
    }
}
