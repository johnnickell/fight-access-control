<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Closure;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler\FindInvitationDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindCredentialDeliveryStatusHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\FindDueCredentialDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\{
    FindExpiredCredentialDeliveriesHandler
};
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryAbsent;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Http\JSend\JSendEnvelope;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use LogicException;
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

#[CoversClass(FindInvitationDeliveryStatusHandler::class)]
#[CoversClass(FindCredentialDeliveryStatusHandler::class)]
#[CoversClass(FindDueCredentialDeliveriesHandler::class)]
#[CoversClass(FindExpiredCredentialDeliveriesHandler::class)]
#[CoversClass(CredentialDeliveryAbsent::class)]
final class CredentialQueryCompositionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function families(): iterable
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            yield $purpose => [$purpose];
        }
    }

    #[DataProvider('families')]
    public function test_real_routing_round_trips_all_four_read_only_paths(string $purpose): void
    {
        $fixture = new ExpiredCredentialFixture($purpose);
        $bus = $this->bus(
            $fixture->activationGrantRepository,
            $fixture->passwordResetGrantRepository,
            $fixture->emailChangeGrantRepository
        );
        $before = $fixture->current();
        $userBefore = UserView::fromUser($fixture->user)->toArray();
        $authorityBefore = $fixture->user->getRecoveryAuthority();
        $transactions = $fixture->unitOfWork->transactions;
        $query = new FindCredentialDeliveryStatus($purpose, $fixture->initial->getDelivery()->getId()->toString());
        $status = $bus->fetch($query);
        self::assertInstanceOf(CredentialDeliveryStatusView::class, $status);
        self::assertSame($query->getDeliveryId(), $status->getDeliveryId());
        $message = QueryMessage::arrayDeserialize(QueryMessage::create($query)->arraySerialize());
        self::assertEquals($status, $bus->dispatch($message));
        self::assertInstanceOf(CredentialDeliveryAbsent::class, $bus->fetch(new FindCredentialDeliveryStatus(
            $purpose,
            UserId::generate()->toString()
        )));
        $invitation = $bus->fetch(new FindInvitationDeliveryStatus($fixture->user->getId()));
        self::assertInstanceOf(
            $purpose === 'activation' ? InvitationDeliveryStatusView::class : CredentialDeliveryAbsent::class,
            $invitation
        );
        $absent = $bus->fetch(new FindInvitationDeliveryStatus(UserId::generate()));
        self::assertSame(['state' => 'absent'], $absent->toArray());
        foreach ([$fixture->issuedAt, $fixture->expiresAt] as $at) {
            $due = new FindDueCredentialDeliveries($at, 2);
            $expired = new FindExpiredCredentialDeliveries($at, 2);
            $dueResult = $bus->fetch($due);
            $expiredResult = $bus->fetch($expired);
            self::assertCount($at === $fixture->issuedAt ? 1 : 0, $dueResult);
            self::assertCount($at === $fixture->expiresAt ? 1 : 0, $expiredResult);
            self::assertEquals($dueResult, $bus->dispatch(QueryMessage::create(
                FindDueCredentialDeliveries::fromArray($due->toArray())
            )));
            self::assertEquals($expiredResult, $bus->dispatch(QueryMessage::create(
                FindExpiredCredentialDeliveries::fromArray($expired->toArray())
            )));
        }

        self::assertSame($before, $fixture->current());
        self::assertSame($userBefore, UserView::fromUser($fixture->userRepository->all()[0])->toArray());
        self::assertEquals($authorityBefore, $fixture->userRepository->all()[0]->getRecoveryAuthority());
        self::assertSame($transactions, $fixture->unitOfWork->transactions);
        self::assertSame([], $fixture->eventDispatcher->events());
    }

    /** @return iterable<string, array{string}> */
    public static function readMethods(): iterable
    {
        foreach (['getLatestByUserId', 'getByDeliveryId', 'findDue', 'findExpired'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('readMethods')]
    public function test_storage_failure_is_not_absence_or_empty_work_and_is_safely_rendered(string $method): void
    {
        $fixture = new ExpiredCredentialFixture('activation');
        $activation = $this->createStub(ActivationGrantRepository::class);
        $failure = new RuntimeException('private-storage-sentinel', 404);
        $activation->method($method)->willThrowException($failure);
        $bus = $this->bus($activation, $fixture->passwordResetGrantRepository, $fixture->emailChangeGrantRepository);
        $query = match ($method) {
            'getLatestByUserId' => new FindInvitationDeliveryStatus($fixture->user->getId()),
            'getByDeliveryId' => new FindCredentialDeliveryStatus(
                'activation',
                $fixture->initial->getDelivery()->getId()->toString()
            ),
            'findDue' => new FindDueCredentialDeliveries($fixture->issuedAt, 2),
            'findExpired' => new FindExpiredCredentialDeliveries($fixture->expiresAt, 2),
            default => throw new LogicException('Unknown read fixture.')
        };
        try {
            $bus->fetch($query);
            self::fail('Storage failure became a successful result.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with(self::identicalTo($failure));
        $responses = new JSendResponseFactory(new ResponseFactory(), new StreamFactory());
        $boundary = new SafeJSendErrorMiddleware($responses, new SafeHttpErrorPolicy(), $diagnostics);
        $handler = new readonly class (static fn (): mixed => $bus->fetch($query)) implements RequestHandlerInterface {
            public function __construct(private Closure $read)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->read)();

                throw new RuntimeException('Expected the read to fail.');
            }
        };
        $request = new ServerRequestFactory()->createServerRequest('GET', '/protected-status');
        $response = $boundary->process($request, $handler);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            ['status' => 'error', 'message' => 'Internal server error.'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertStringNotContainsString('private-storage-sentinel', (string) $response->getBody());
        $absentResponse = $responses->fromEnvelope(JSendEnvelope::success(new CredentialDeliveryAbsent()), 200);
        self::assertSame(200, $absentResponse->getStatusCode());
        self::assertSame(
            ['status' => 'success', 'data' => ['state' => 'absent']],
            json_decode((string) $absentResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function test_internal_identity_fault_stays_a_safe_server_failure_not_a_not_found(): void
    {
        $fixture = new ExpiredCredentialFixture('activation');
        $activation = $this->createStub(ActivationGrantRepository::class);
        $activation->method('getLatestByUserId')->willReturn($fixture->initial);
        $bus = $this->bus($activation, $fixture->passwordResetGrantRepository, $fixture->emailChangeGrantRepository);
        try {
            $bus->fetch(new FindInvitationDeliveryStatus(UserId::generate()));
            self::fail('An unrelated status escaped.');
        } catch (LogicException $logicException) {
            [$status, $envelope] = new SafeHttpErrorPolicy()->present($logicException);
            self::assertSame(500, $status);
            self::assertStringNotContainsString($logicException->getMessage(), $envelope->toJson());
        }
    }

    private function bus(
        ActivationGrantRepository $activation,
        PasswordResetGrantRepository $reset,
        EmailChangeGrantRepository $email
    ): RoutingQueryBus {
        $router = new InMemoryQueryRouter();
        $handlers = [
            new FindInvitationDeliveryStatusHandler($activation),
            new FindCredentialDeliveryStatusHandler($activation, $reset, $email),
            new FindDueCredentialDeliveriesHandler($activation, $reset, $email),
            new FindExpiredCredentialDeliveriesHandler($activation, $reset, $email)
        ];
        foreach ($handlers as $handler) {
            $router->registerHandler($handler::queryRegistration(), $handler);
        }

        return new RoutingQueryBus($router);
    }
}
