<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(ExpirePasswordResetDelivery::class)]
#[CoversClass(PasswordResetDeliveryConfirmed::class)]
#[CoversClass(PasswordResetDeliveryExpired::class)]
#[CoversClass(PasswordResetGrant::class)]
final class PasswordResetDeliveryLifecycleHandlerTest extends TestCase
{
    public function test_expiry_only_terminalizes_at_the_boundary_and_publishes_after_commit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $grant = $this->grant();
        $repository->add($grant);
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork, $repository, $grant): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertFalse($repository->getById($grant->getId())->getDelivery()->hasRecoverableMaterial());
        });
        $handler = new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events);
        $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'expiry',
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable('2026-08-20T12:59:59+00:00')
        )));
        self::assertSame($grant, $repository->getById($grant->getId()));
        self::assertCount(0, $events->events());
        $command = new ExpirePasswordResetDelivery(
            'expiry',
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            $grant->getExpiresAt()
        );
        $handler->handle(CommandMessage::create($command));
        $handler->handle(CommandMessage::create($command));
        self::assertSame(ExpirePasswordResetDelivery::class, $handler::commandRegistration());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetDeliveryExpired::class, $events->events()[0]);
    }

    public function test_missing_mismatched_and_lost_cas_attempts_are_no_ops(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork, replaceSucceeds: false);
        $grant = $this->grant();
        $repository->add($grant);
        $events = new InMemoryEventDispatcher();
        $handler = new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events);
        foreach (
            [
            [$grant->getUserId(), $grant->getDelivery()->getId()],
            [UserId::generate(), $grant->getDelivery()->getId()],
            [$grant->getUserId(), PasswordResetDeliveryId::generate()]
            ] as [$user, $delivery]
        ) {
            $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
                'expiry',
                $user,
                $delivery,
                $grant->getExpiresAt()
            )));
        }

        self::assertSame($grant, $repository->getById($grant->getId()));
        self::assertSame([], $events->events());
    }

    public function test_stale_expiry_cannot_mutate_a_newer_generation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $old = $this->grant('old');
        $new = $this->grant('new');
        $repository->add($old);
        self::assertTrue($repository->replaceWithSuccessor(
            $old,
            $old->revoke(new DateTimeImmutable('2026-08-20T12:10:00+00:00')),
            $new
        ));
        $events = new InMemoryEventDispatcher();
        new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events)->handle(
            CommandMessage::create(new ExpirePasswordResetDelivery(
                'expiry',
                $old->getUserId(),
                $old->getDelivery()->getId(),
                $old->getExpiresAt()
            ))
        );
        self::assertSame($new, $repository->getById($new->getId()));
        self::assertSame('ciphertext:new', $new->getDelivery()->getEncryptedMaterial()?->reveal());
        self::assertSame([], $events->events());
    }

    public function test_failures_rethrow_and_publish_command_failure(): void
    {
        $events = new InMemoryEventDispatcher();
        $repository = $this->createStub(PasswordResetGrantRepository::class);
        $fault = new RuntimeException('Password-reset persistence failed.');
        $repository->method('getByDeliveryId')->willThrowException($fault);
        $handler = new ExpirePasswordResetDeliveryHandler($repository, new InMemoryUnitOfWork(), $events);
        try {
            $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
                'expiry',
                UserId::generate(),
                PasswordResetDeliveryId::generate(),
                new DateTimeImmutable()
            )));
            self::fail('Storage failure must rethrow.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fault, $runtimeException);
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_messages_round_trip_and_reject_missing_data(): void
    {
        $grant = $this->grant();
        $at = $grant->getExpiresAt();
        $messages = [
            new ExpirePasswordResetDelivery('expiry', $grant->getUserId(), $grant->getDelivery()->getId(), $at),
            new PasswordResetDeliveryConfirmed('transport', $grant->getUserId(), $grant->getDelivery()->getId(), $at),
            new PasswordResetDeliveryExpired('expiry', $grant->getUserId(), $grant->getDelivery()->getId(), $at)
        ];
        foreach ($messages as $message) {
            self::assertEquals($message, $message::fromArray($message->toArray()));
            try {
                $message::fromArray([]);
                self::fail($message::class.' accepted missing data.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }

            self::assertSame($grant->getUserId(), $message->getUserId());
            self::assertSame($grant->getDelivery()->getId(), $message->getPasswordResetDeliveryId());
            self::assertSame($at, $message->getOccurredAt());
        }

        self::assertSame('transport', $messages[1]->getActorId());
        self::assertSame('expiry', $messages[2]->getActorId());
    }

    private function grant(string $credential = 'once'): PasswordResetGrant
    {
        return PasswordResetGrant::issue(
            UserId::fromString('018f6300-4c42-7c43-9f19-9dfac6f7a001'),
            PasswordResetCredential::fromString($credential),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:'.$credential
        );
    }
}
