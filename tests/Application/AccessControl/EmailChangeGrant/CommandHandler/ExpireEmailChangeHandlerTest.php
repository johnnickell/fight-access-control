<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\ExpireEmailChangeHandler;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\ExpireEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeExpired;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpireEmailChangeHandler::class)]
#[CoversClass(ExpireEmailChange::class)]
#[CoversClass(EmailChangeExpired::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(User::class)]
final class ExpireEmailChangeHandlerTest extends TestCase
{
    public function test_expiry_terminalizes_current_authority_and_clears_only_its_matching_reservation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($users->replaceEmailChangeReservation($user, $reserved));
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $grant = EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        );
        self::assertTrue($grants->add($grant));
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new ExpireEmailChangeHandler($users, $grants, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new ExpireEmailChange(
            'email-change-expiry',
            $user->getId(),
            $grant->getId(),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00')
        )));

        $storedUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame('old@example.test', $storedUser->getEmail()->canonical());
        self::assertNull($storedUser->getPendingEmailChange());
        self::assertSame(2, $storedUser->getEmailChangeReservationRevision());
        self::assertTrue($grants->all()[0]->isExpired());
        self::assertSame('2026-08-22T13:00:00+00:00', $grants->all()[0]->getExpiredAt()?->format(DATE_ATOM));
        self::assertFalse($grants->all()[0]->getDelivery()->isRecoverable());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(EmailChangeExpired::class, $events->events()[0]);
    }

    public function test_command_and_success_event_round_trip_with_explicit_occurrence_time(): void
    {
        [$handler, , , $events, $userId, $grant] = $this->fixture();
        $command = $this->command($userId, $grant);

        self::assertSame(ExpireEmailChange::class, ExpireEmailChangeHandler::commandRegistration());
        self::assertEquals($command, ExpireEmailChange::fromArray($command->toArray()));
        self::assertSame('email-change-expiry', $command->getActorId());
        self::assertSame($userId, $command->getUserId());
        self::assertSame($grant->getId(), $command->getEmailChangeGrantId());
        self::assertSame('2026-08-22T13:00:00+00:00', $command->getOccurredAt()->format(DATE_ATOM));

        $handler->handle(CommandMessage::create($command));

        $event = $events->events()[0];
        self::assertInstanceOf(EmailChangeExpired::class, $event);
        self::assertEquals($event, EmailChangeExpired::fromArray($event->toArray()));
        self::assertSame('email-change-expiry', $event->getActorId());
        self::assertSame($userId, $event->getUserId());
        self::assertSame($grant->getId(), $event->getEmailChangeGrantId());
        self::assertSame('2026-08-22T13:00:00+00:00', $event->getOccurredAt()->format(DATE_ATOM));
        self::assertArrayNotHasKey('credential', $event->toArray());
        self::assertArrayNotHasKey('ciphertext', $event->toArray());
    }

    public function test_missing_command_and_event_data_is_rejected(): void
    {
        foreach (['actor_id', 'user_id', 'email_change_grant_id', 'occurred_at'] as $missing) {
            $data = [
                'actor_id' => 'email-change-expiry',
                'user_id' => UserId::generate()->toString(),
                'email_change_grant_id' => EmailChangeGrantId::generate()->toString(),
                'occurred_at' => '2026-08-22T13:00:00+00:00',
            ];
            unset($data[$missing]);

            try {
                ExpireEmailChange::fromArray($data);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }

            try {
                EmailChangeExpired::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(8);
    }

    public function test_early_stale_unknown_and_mismatched_expiry_are_mutation_free(): void
    {
        foreach (['early', 'stale grant', 'unknown user', 'mismatched reservation'] as $case) {
            [$handler, $users, $grants, $events, $userId, $grant] = $this->fixture(
                grantEmail: $case === 'mismatched reservation' ? 'other@example.test' : 'new@example.test'
            );
            $commandUserId = $case === 'unknown user' ? UserId::generate() : $userId;
            $grantId = $case === 'stale grant' ? EmailChangeGrantId::generate() : $grant->getId();
            $occurredAt = new DateTimeImmutable('2026-08-22T13:00:00+00:00');
            if ($case === 'early') {
                $occurredAt = new DateTimeImmutable('2026-08-22T12:59:59+00:00');
            }

            $handler->handle(CommandMessage::create(new ExpireEmailChange(
                'email-change-expiry',
                $commandUserId,
                $grantId,
                $occurredAt
            )));

            self::assertSame(
                'new@example.test',
                $users->getById($userId)?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($grants->all()[0]->isIssued());
            self::assertSame([], $events->events());
        }
    }

    public function test_repeated_expiry_is_mutation_free(): void
    {
        [$handler, $users, $grants, $events, $userId, $grant] = $this->fixture();
        $command = $this->command($userId, $grant);
        $handler->handle(CommandMessage::create($command));
        $expired = $grants->all()[0];

        $handler->handle(CommandMessage::create($command));

        self::assertSame($expired, $grants->all()[0]);
        self::assertNull($users->getById($userId)?->getPendingEmailChange());
        self::assertCount(1, $events->events());
    }

    public function test_a_lost_reservation_or_grant_cas_rolls_back_the_coupled_transition(): void
    {
        foreach ([false, true] as $grantCasLoss) {
            [$handler, $users, $grants, $events, $userId, $grant] = $this->fixture(
                grantReplaceSucceeds: !$grantCasLoss,
                userReplaceSucceeds: $grantCasLoss
            );

            try {
                $handler->handle(CommandMessage::create($this->command($userId, $grant)));
                self::fail('A lost expiry CAS succeeded.');
            } catch (LogicException) {
                self::assertSame(
                    'new@example.test',
                    $users->getById($userId)?->getPendingEmailChange()?->canonical()
                );
                self::assertTrue($grants->all()[0]->isIssued());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    /**
     * @return array{ExpireEmailChangeHandler, InMemoryUserRepository, InMemoryEmailChangeGrantRepository,
     *     InMemoryEventDispatcher, UserId, EmailChangeGrant}
     */
    private function fixture(
        bool $grantReplaceSucceeds = true,
        bool $userReplaceSucceeds = true,
        string $grantEmail = 'new@example.test'
    ): array {
        $unitOfWork = new InMemoryUnitOfWork();
        $state = new InMemoryUserRepositoryState();
        $seedUsers = new InMemoryUserRepository($unitOfWork, state: $state);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $seedUsers->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($seedUsers->replaceEmailChangeReservation($user, $reserved));
        $users = new InMemoryUserRepository(
            $unitOfWork,
            state: $state,
            replaceEmailChangeReservationSucceeds: $userReplaceSucceeds
        );
        $grants = new InMemoryEmailChangeGrantRepository(
            $unitOfWork,
            replaceSucceeds: $grantReplaceSucceeds
        );
        $grant = EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString($grantEmail),
            'ciphertext:change-once'
        );
        self::assertTrue($grants->add($grant));
        $events = new InMemoryEventDispatcher();
        $handler = new ExpireEmailChangeHandler($users, $grants, $unitOfWork, $events);

        return [$handler, $users, $grants, $events, $user->getId(), $grant];
    }

    private function command(UserId $userId, EmailChangeGrant $grant): ExpireEmailChange
    {
        return new ExpireEmailChange(
            'email-change-expiry',
            $userId,
            $grant->getId(),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00')
        );
    }
}
