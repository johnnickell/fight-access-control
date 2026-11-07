<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler as Activation;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler as Email;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler as Reset;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\{
    FixedEmailChangeAdministrationAuthorization
};
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
final class HumanCredentialCompositionTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function operations(): iterable
    {
        foreach (
            [
            'activation'     => ['deliver', 'expire', 'resend', 'retry'],
            'password_reset' => ['deliver', 'expire', 'request'],
            'email_change'   => ['deliver', 'expire', 'request', 'cancel']
            ] as $purpose => $operations
        ) {
            foreach ($operations as $operation) {
                foreach ([false, true] as $decoded) {
                    yield $purpose.'.'.$operation.'.'.(int) $decoded => [$purpose, $purpose.'.'.$operation, $decoded];
                }
            }
        }
    }

    #[DataProvider('operations')]
    public function test_all_eleven_void_handlers_route_real_commands_and_decoded_messages(
        string $purpose,
        string $operation,
        bool $decoded
    ): void {
        $fixture = new HumanCredentialContractFixture($purpose);
        if ($operation === 'activation.retry') {
            $fixture->state->stage('retry');
        }

        if ($operation === 'email_change.request') {
            $grant = $fixture->state->initial;
            self::assertTrue($fixture->state->replace($grant, $grant->revoke($fixture->state->issuedAt)));
            $user = clone $fixture->state->user;
            $user->cancelEmailChange($fixture->state->issuedAt);
            self::assertTrue($fixture->state->userRepository->replaceEmailChangeReservation(
                $fixture->state->user,
                $user
            ));
        }

        $bus = $this->bus($fixture->handler($operation));
        $command = $fixture->command($operation);
        $message = CommandMessage::arrayDeserialize(CommandMessage::create($command)->arraySerialize());
        self::assertEquals($command, $message->payload());
        if ($decoded) {
            $bus->dispatch($message);
        } else {
            $bus->execute($command);
        }

        self::assertCount(1, $fixture->state->eventDispatcher->events());
        self::assertFalse($fixture->state->unitOfWork->transactionActive);
        self::assertSame(str_ends_with($operation, '.deliver') ? 2 : 1, $fixture->state->unitOfWork->transactions);
        self::assertCount((int) str_ends_with($operation, '.deliver'), $fixture->provider->invocations());
        foreach ($fixture->state->eventDispatcher->events() as $event) {
            self::assertStringNotContainsString('fixture:encrypted', serialize($event->toArray()));
            self::assertStringNotContainsString('ciphertext:', serialize($event->toArray()));
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function failures(): iterable
    {
        yield 'missing target' => ['missing', 404];
        yield 'administrative denial' => ['denied', 403];
        yield 'state conflict' => ['conflict', 409];
        yield 'wrong identity' => ['identity', 500];
        yield 'storage outage' => ['storage', 500];
        yield 'native contract violation' => ['native', 500];
        yield 'unconfigured routing' => ['routing', 500];
    }

    #[DataProvider('failures')]
    public function test_safe_error_composition_separates_intent_from_internal_faults(string $case, int $expected): void
    {
        $fixture = new HumanCredentialContractFixture('email_change');
        $users = $this->createStub(UserRepository::class);
        $failure = new RuntimeException('private-diagnostic-sentinel', 404);
        if ($case === 'identity') {
            $users->method('getById')->willReturn(UserFixture::withState('other@example.test', UserState::ACTIVE));
        } elseif ($case === 'storage') {
            $users->method('getById')->willThrowException($failure);
        } elseif ($case === 'native') {
            $users->method('getById')->willReturnCallback(static fn (): bool => false);
        } elseif ($case === 'conflict') {
            $users = $fixture->state->userRepository;
        }

        $authorization = new FixedEmailChangeAdministrationAuthorization($case !== 'denied');
        $handler = $fixture->handler('email_change.request', users: $users, authorization: $authorization);
        $bus = $this->bus($case === 'routing' ? null : $handler);
        $command = $fixture->command('email_change.request');
        if ($case === 'denied') {
            $command = new RequestEmailChange(
                UserId::generate(),
                $fixture->state->user->getId(),
                EmailAddress::fromString('another@example.test')
            );
        }

        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report');
        $boundary = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );
        $next = new readonly class (static fn () => $bus->execute($command)) implements RequestHandlerInterface {
            public function __construct(private Closure $operation)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->operation)();

                throw new RuntimeException('Expected operation failure.');
            }
        };
        $response = $boundary->process(
            new ServerRequestFactory()->createServerRequest('POST', '/protected-credential-operation'),
            $next
        );
        self::assertSame($expected, $response->getStatusCode());
        self::assertStringNotContainsString('private-diagnostic-sentinel', (string) $response->getBody());
        self::assertStringNotContainsString('another identity', (string) $response->getBody());
        self::assertStringNotContainsString('trace', (string) $response->getBody());
        self::assertSame([], $fixture->provider->invocations());
        self::assertSame(0, $fixture->state->current()->getRevision());
    }

    public function test_unknown_reset_request_stays_successful_void_through_real_routing(): void
    {
        $fixture = new HumanCredentialContractFixture('password_reset');
        $users = $this->createStub(UserRepository::class);
        $this->bus($fixture->handler('password_reset.request', users: $users))->execute(
            $fixture->command('password_reset.request')
        );
        self::assertSame([], $fixture->state->eventDispatcher->events());
        self::assertSame([], $fixture->provider->invocations());
        self::assertSame(0, $fixture->state->current()->getRevision());
    }

    private function bus(?CommandHandler $handler): RoutingCommandBus
    {
        $router = new InMemoryCommandRouter();
        if ($handler instanceof CommandHandler) {
            $router->registerHandler($handler::commandRegistration(), $handler);
        }

        return new RoutingCommandBus($router);
    }
}
