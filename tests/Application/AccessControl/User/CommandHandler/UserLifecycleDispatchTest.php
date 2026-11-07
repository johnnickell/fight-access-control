<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Closure;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Throwable;

#[CoversClass(CorrectPendingInvitationHandler::class)]
#[CoversClass(DeleteUserHandler::class)]
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(EnableUserHandler::class)]
#[CoversClass(InvitePendingUserHandler::class)]
#[CoversClass(RecoverPendingRegistrationHandler::class)]
#[CoversClass(RegisterUserHandler::class)]
#[CoversClass(RestoreUserHandler::class)]
final class UserLifecycleDispatchTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['register', 'invite', 'recover', 'correct', 'enable', 'disable', 'delete', 'restore'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function test_real_registered_routing_round_trips_void_success_after_atomic_commit(string $operation): void
    {
        $fixture = new LifecycleDispatchFixture($operation);
        $message = CommandMessage::arrayDeserialize(CommandMessage::create($fixture->command)->arraySerialize());
        $fixture->bus->dispatch($message);
        self::assertTrue($fixture->registration->unitOfWork->transactionCompleted);
        self::assertCount(1, $fixture->registration->userRepository->all());
        self::assertCount(1, $fixture->audit->all());
        self::assertCount(1, $fixture->registration->eventDispatcher->events());
        self::assertNotInstanceOf(CommandFailedEvent::class, $fixture->registration->eventDispatcher->events()[0]);
    }

    #[DataProvider('operations')]
    public function test_late_audit_failure_rolls_back_despite_secondary_notification_failure(string $operation): void
    {
        $attempts = [];
        $secondary = new RuntimeException('Synthetic secondary notification diagnostic');
        $dispatcher = new InMemoryEventDispatcher(static function ($event) use (&$attempts, $secondary): void {
            $attempts[] = $event;
            throw $secondary;
        });
        $fixture = new LifecycleDispatchFixture($operation, true, $dispatcher);
        $usersBefore = array_map(UserView::fromUser(...), $fixture->registration->userRepository->all());
        $grantsBefore = $fixture->registration->activationGrantRepository->all();
        try {
            $fixture->bus->execute($fixture->command);
            self::fail('A required audit failure was swallowed.');
        } catch (RuntimeException $runtimeException) {
            self::assertNotSame($secondary, $runtimeException);
            self::assertFalse($fixture->registration->unitOfWork->transactionCompleted);
            self::assertEquals(
                $usersBefore,
                array_map(UserView::fromUser(...), $fixture->registration->userRepository->all())
            );
            self::assertEquals($grantsBefore, $fixture->registration->activationGrantRepository->all());
            self::assertSame([], $fixture->audit->all());
            self::assertCount(1, $attempts);
            self::assertInstanceOf(CommandFailedEvent::class, $attempts[0]);
            self::assertStringNotContainsString($runtimeException->getMessage(), serialize($attempts[0]->toArray()));
            self::assertSame(500, new SafeHttpErrorPolicy()->status($runtimeException));
        }
    }

    #[DataProvider('operations')]
    public function test_unknown_commit_acknowledgement_preserves_either_actual_outcome(string $operation): void
    {
        foreach ([false, true] as $committed) {
            $fixture = null;
            $failure = new RuntimeException('Synthetic lost commit acknowledgement');
            $transaction = static function (callable $callback) use (&$fixture, $committed, $failure): mixed {
                self::assertInstanceOf(LifecycleDispatchFixture::class, $fixture);
                if ($committed) {
                    $fixture->registration->unitOfWork->commitTransactional($callback);
                    throw $failure;
                }

                return $fixture->registration->unitOfWork->commitTransactional(
                    static function () use ($callback, $failure): void {
                        $callback();
                        throw $failure;
                    }
                );
            };
            $unitOfWork = new readonly class ($transaction) implements TransactionalUnitOfWork {
                public function __construct(private Closure $transaction)
                {
                }

                public function commitTransactional(callable $operation): mixed
                {
                    return ($this->transaction)($operation);
                }

                public function isClosed(): bool
                {
                    return false;
                }
            };
            $fixture = new LifecycleDispatchFixture($operation, unitOfWork: $unitOfWork);
            $before = array_map(UserView::fromUser(...), $fixture->registration->userRepository->all());
            $grantsBefore = $fixture->registration->activationGrantRepository->all();
            try {
                $fixture->bus->execute($fixture->command);
                self::fail('Unknown acknowledgement became an invented success.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
                self::assertSame($committed, $fixture->registration->unitOfWork->transactionCompleted);
                self::assertCount($committed ? 1 : 0, $fixture->audit->all());
                if (!$committed) {
                    self::assertEquals(
                        $before,
                        array_map(UserView::fromUser(...), $fixture->registration->userRepository->all())
                    );
                    self::assertEquals($grantsBefore, $fixture->registration->activationGrantRepository->all());
                }

                self::assertCount(1, $fixture->registration->eventDispatcher->events());
                self::assertInstanceOf(CommandFailedEvent::class, $fixture->registration->eventDispatcher->events()[0]);
            }
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function failures(): iterable
    {
        yield 'missing' => ['missing', 404];
        yield 'state' => ['state', 409];
        yield 'precondition' => ['precondition', 412];
        yield 'denial' => ['denial', 403];
        yield 'configuration' => ['configuration', 500];
        yield 'storage' => ['storage', 500];
        yield 'unregistered' => ['unregistered', 500];
    }

    #[DataProvider('failures')]
    public function test_safe_error_composition_preserves_intent_without_diagnostics(string $case, int $status): void
    {
        $operation = match ($case) {
            'state' => 'disable',
            'precondition' => 'recover',
            'configuration' => 'register',
            default => 'enable'
        };
        $fixture = new LifecycleDispatchFixture($operation);
        $bus = $fixture->bus;
        $command = $fixture->command;
        if ($case === 'missing') {
            $fixture->registration->userState->users = [];
        } elseif ($case === 'state') {
            $user = $fixture->registration->userRepository->all()[0];
            $fixture->registration->userState->users = [UserFixture::withIdAndAuthenticationVersion(
                $user->getId(),
                'current@example.test',
                UserState::PENDING_ACTIVATION,
                1
            )];
        } elseif ($case === 'configuration') {
            $fixture->registration->roleState->roles = [];
        } elseif ($case === 'storage') {
            $fixture->registration->unitOfWork->failNextCommit = true;
        } elseif ($case === 'unregistered') {
            $bus = new RoutingCommandBus(new InMemoryCommandRouter());
        } elseif ($case === 'denial') {
            $recovery = new RegistrationRecoveryFixture();
            $router = new InMemoryCommandRouter();
            $handler = $recovery->handler();
            $router->registerHandler($handler::commandRegistration(), $handler);
            $bus = new RoutingCommandBus($router);
            $command = $recovery->command(actor: UserId::generate());
        } else {
            self::assertInstanceOf(RecoverPendingRegistration::class, $command);
            $command = new RecoverPendingRegistration(
                $command->getActorId(),
                $command->getUserId(),
                ActivationDeliveryId::generate(),
                $command->getEmail()
            );
        }

        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with(self::callback(
            static fn (Throwable $failure): bool => new SafeHttpErrorPolicy()->status($failure) === $status
        ));
        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );
        $handler = new readonly class (static fn () => $bus->execute($command)) implements RequestHandlerInterface {
            public function __construct(private Closure $execute)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->execute)();

                throw new RuntimeException('The expected operation failure did not occur.');
            }
        };
        $response = $middleware->process(new ServerRequestFactory()->createServerRequest('POST', '/account'), $handler);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('trace', (string) $response->getBody());
        self::assertStringNotContainsString('current@example.test', (string) $response->getBody());
        self::assertStringNotContainsString('Role', (string) $response->getBody());
    }

    #[DataProvider('operations')]
    public function test_double_publication_failure_preserves_primary_commit_and_safe_payload(string $operation): void
    {
        $primary = new RuntimeException('Synthetic success subscriber diagnostic', 409);
        $secondary = new RuntimeException('Synthetic failure subscriber diagnostic', 404);
        $attempts = [];
        $onTrigger = static function ($event) use (&$attempts, $primary, $secondary): void {
            $attempts[] = $event;
            throw $event instanceof CommandFailedEvent ? $secondary : $primary;
        };
        $dispatcher = new InMemoryEventDispatcher($onTrigger);
        $fixture = new LifecycleDispatchFixture($operation, eventDispatcher: $dispatcher);
        try {
            $fixture->bus->execute($fixture->command);
            self::fail('Committed publication failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($primary, $runtimeException);
            self::assertTrue($fixture->registration->unitOfWork->transactionCompleted);
            self::assertCount(1, $fixture->audit->all());
            self::assertCount(1, $fixture->registration->userRepository->all());
            self::assertCount(2, $attempts);
            self::assertInstanceOf(CommandFailedEvent::class, $attempts[1]);
            $serialized = serialize(CommandFailedEvent::fromArray($attempts[1]->toArray())->toArray());
            self::assertStringNotContainsString($primary->getMessage(), $serialized);
            self::assertStringNotContainsString($secondary->getMessage(), $serialized);
            [$status, $envelope] = new SafeHttpErrorPolicy()->present($runtimeException);
            self::assertSame(500, $status);
            self::assertStringNotContainsString($primary->getMessage(), $envelope->toJson());
        }
    }
}
