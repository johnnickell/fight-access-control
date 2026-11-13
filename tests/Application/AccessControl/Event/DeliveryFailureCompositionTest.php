<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Event;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\DeliverEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EventSubscriber\EmailChangeDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\EventSubscriber\PasswordResetDeliverySubscriber;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeDelivered;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Application\Messaging\Event\EventDispatchFailed;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\AllEvents;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Utility\ClassName;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service as EmailService;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\FixedEmailChangeCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\PrefixEmailChangeDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverEmailChangeHandler::class)]
#[CoversClass(RequestEmailChangeHandler::class)]
#[CoversClass(EmailChangeDeliverySubscriber::class)]
#[CoversClass(DeliverPasswordResetHandler::class)]
#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(PasswordResetDeliverySubscriber::class)]
final class DeliveryFailureCompositionTest extends TestCase
{
    /** @return iterable<string, array{bool, string, bool}> */
    public static function phases(): iterable
    {
        foreach ([false, true] as $emailChange) {
            foreach (['claim', 'outcome', 'publication'] as $phase) {
                foreach ([false, true] as $realDispatcher) {
                    yield (int) $emailChange.$phase.(int) $realDispatcher => [$emailChange, $phase, $realDispatcher];
                }
            }
        }
    }

    #[DataProvider('phases')]
    public function test_nested_failure_notifications_do_not_rewrite_the_committed_request(
        bool $emailChange,
        string $phase,
        bool $realDispatcher
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('person@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $primary = new RuntimeException('Synthetic operation detail. delivery-once ciphertext:delivery-once');
        $unitOfWork->beforeCommit = static function () use ($phase, $unitOfWork, $primary): void {
            if (
                ($phase === 'claim' && $unitOfWork->transactions === 2)
                || ($phase === 'outcome' && $unitOfWork->transactions === 3)
            ) {
                throw $primary;
            }
        };
        $attempts = [];
        $subscriber = null;
        $observe = static function (Event $event) use (
            &$attempts,
            &$subscriber,
            $primary,
            $realDispatcher
        ): void {
            $attempts[] = $event;
            if (
                $realDispatcher
                && ($event instanceof PasswordResetRequested || $event instanceof EmailChangeRequested)
            ) {
                return;
            }

            if ($event instanceof PasswordResetRequested) {
                self::assertInstanceOf(PasswordResetDeliverySubscriber::class, $subscriber);
                $subscriber->onPasswordResetRequested(EventMessage::create($event));

                return;
            }

            if ($event instanceof EmailChangeRequested) {
                self::assertInstanceOf(EmailChangeDeliverySubscriber::class, $subscriber);
                $subscriber->onEmailChangeRequested(EventMessage::create($event));

                return;
            }

            if ($event instanceof PasswordResetDeliveryConfirmed || $event instanceof EmailChangeDelivered) {
                throw $primary;
            }

            throw new RuntimeException('Synthetic notification failure.');
        };
        $eventDispatcher = new InMemoryEventDispatcher($observe);
        if ($realDispatcher) {
            $eventDispatcher = new SimpleEventDispatcher();
            $eventDispatcher->addHandler(
                ClassName::underscore(AllEvents::class),
                static function (EventMessage $message) use ($observe): void {
                    $event = $message->payload();
                    self::assertInstanceOf(Event::class, $event);
                    $observe($event);
                }
            );
        }

        $clock = new FixedClock('2026-08-23T11:00:00+00:00');
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use ($unitOfWork): void {
            self::assertFalse($unitOfWork->transactionActive);
        });
        if ($emailChange) {
            $passwordResetGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
            $worker = new DeliverEmailChangeHandler(
                $passwordResetGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                new PrefixEmailChangeDeliveryCipher(),
                $provider,
                $clock,
                $eventDispatcher
            );
            $request = new RequestEmailChangeHandler(
                $userRepository,
                $passwordResetGrantRepository,
                new EmailService\FixedEmailChangeAdministrationAuthorization(true),
                $auditEvidenceRepository,
                $unitOfWork,
                new FixedEmailChangeCredentialGenerator('delivery-once'),
                new PrefixEmailChangeDeliveryCipher(),
                $clock,
                $eventDispatcher
            );
            $command = new RequestEmailChange(
                UserId::generate(),
                $user->getId(),
                EmailAddress::fromString('new@example.test')
            );
        } else {
            $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
            $worker = new DeliverPasswordResetHandler(
                $passwordResetGrantRepository,
                $unitOfWork,
                new PrefixPasswordResetDeliveryCipher(),
                $provider,
                $clock,
                $eventDispatcher,
                $userRepository,
                $auditEvidenceRepository
            );
            $request = new RequestPasswordResetHandler(
                $userRepository,
                $passwordResetGrantRepository,
                $unitOfWork,
                new FixedPasswordResetCredentialGenerator('delivery-once'),
                new PrefixPasswordResetDeliveryCipher(),
                $clock,
                $eventDispatcher,
                $auditEvidenceRepository
            );
            $command = new RequestPasswordReset($user->getEmail());
        }

        $router = new InMemoryCommandRouter();
        $router->registerHandler($worker::commandRegistration(), $worker);
        $router->registerHandler($request::commandRegistration(), $request);

        $synchronousCommandBus = new RoutingCommandBus($router);
        if ($emailChange) {
            $subscriber = new EmailChangeDeliverySubscriber($synchronousCommandBus);
        } else {
            $subscriber = new PasswordResetDeliverySubscriber($synchronousCommandBus);
        }

        if ($eventDispatcher instanceof SimpleEventDispatcher) {
            $eventDispatcher->register($subscriber);
        }

        $caught = null;
        try {
            $synchronousCommandBus->dispatch(CommandMessage::create($command));
        } catch (RuntimeException $runtimeException) {
            $caught = $runtimeException;
        }

        if ($realDispatcher) {
            self::assertInstanceOf(EventDispatchFailed::class, $caught);
            self::assertCount(1, $caught->failures());
            $workerFailure = $caught->failures()[0]->throwable();
            if ($phase === 'publication') {
                self::assertInstanceOf(EventDispatchFailed::class, $workerFailure);
                self::assertCount(1, $workerFailure->failures());
                $workerFailure = $workerFailure->failures()[0]->throwable();
            }

            self::assertSame($primary, $workerFailure);
        } else {
            self::assertSame($primary, $caught);
        }

        self::assertFalse($unitOfWork->transactionActive);
        $grant = $passwordResetGrantRepository->getLatestByUserId($user->getId());
        self::assertNotNull($grant);
        self::assertTrue($grant->isIssued());
        $status = match ($phase) {
            'claim' => CredentialDeliveryStatus::PENDING,
            'outcome' => CredentialDeliveryStatus::CLAIMED,
            'publication' => CredentialDeliveryStatus::DELIVERED,
            default => throw new LogicException('Unsupported delivery phase.')
        };
        self::assertSame($status, $grant->getDelivery()->getStatus());
        self::assertSame($phase !== 'publication', $grant->getDelivery()->hasRecoverableMaterial());
        self::assertCount(1 + (int) ($phase === 'publication'), $auditEvidenceRepository->all());
        self::assertCount((int) ($phase !== 'claim'), $provider->invocations());
        if ($emailChange) {
            self::assertSame(
                'new@example.test',
                $userRepository->getById($user->getId())->getPendingEmailChange()?->canonical()
            );
        }

        $failures = array_values(array_filter(
            $attempts,
            static fn(Event $event): bool => $event instanceof CommandFailedEvent
        ));
        self::assertCount(2, $failures);
        $expectedWorker = DeliverPasswordReset::class;
        $expectedText = 'Password reset delivery failed.';
        $expectedRequestText = 'Password reset request failed.';
        if ($emailChange) {
            $expectedWorker = DeliverEmailChange::class;
            $expectedText = 'Email change delivery failed.';
            $expectedRequestText = 'Email change request failed.';
        }

        self::assertInstanceOf($expectedWorker, $failures[0]->getCommand());
        self::assertSame($expectedText, $failures[0]->getErrorMessage());
        self::assertSame($command, $failures[1]->getCommand());
        self::assertSame($expectedRequestText, $failures[1]->getErrorMessage());
        foreach ($failures as $failure) {
            self::assertStringNotContainsString('delivery-once', serialize($failure->toArray()));
            self::assertSame($failure->toArray(), CommandFailedEvent::fromArray($failure->toArray())->toArray());
        }

        $due = $passwordResetGrantRepository->findDue(new DateTimeImmutable('2026-08-23T11:06:00+00:00'), 10);
        self::assertCount((int) ($phase !== 'publication'), $due);
        if ($due !== []) {
            self::assertSame($grant->getDelivery()->getId(), $due[0]->getDeliveryId());
        }
    }
}
