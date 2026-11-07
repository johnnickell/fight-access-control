<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Command;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler\RevokeSessionHandler;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\RevokeSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\SessionRevocationReason;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedSessionAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use stdClass;
use Throwable;

#[CoversClass(RevokeSessionHandler::class)]
final class SessionCommandTest extends TestCase
{
    /** @return iterable<string, array{string, int, bool}> */
    public static function scenarios(): iterable
    {
        $scenarios = [
            'self'         => 204,
            'admin'        => 204,
            'absent'       => 404,
            'current'      => 422,
            'denied'       => 403,
            'reason'       => 400,
            'conflict'     => 409,
            'identity'     => 500,
            'outage'       => 500,
            'native'       => 500,
            'unconfigured' => 500
        ];
        foreach ($scenarios as $mode => $status) {
            foreach ([false, true] as $decoded) {
                yield $mode.($decoded ? '-dispatch' : '-execute') => [$mode, $status, $decoded];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function test_real_command_routing_safe_failures_and_committed_void_results(
        string $mode,
        int $status,
        bool $decoded
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $now = new DateTimeImmutable('2026-08-19T12:00:00Z');
        $actor = UserId::generate();
        $owner = in_array($mode, ['admin', 'denied', 'reason'], true) ? UserId::generate() : $actor;
        $session = RefreshSession::start(
            RefreshSessionId::generate(),
            $owner,
            RefreshCredential::fromString(str_repeat('a', 64)),
            $now->modify('-1 hour'),
            $now->modify('+1 day'),
            $now->modify('+2 days'),
            1,
            false
        );
        $stored = new InMemoryRefreshSessionRepository($unitOfWork);
        $stored->add($session);

        $repository = $stored;
        $primary = new RuntimeException('PRIVATE provider diagnostic', 404);
        if (in_array($mode, ['conflict', 'identity', 'outage', 'native'], true)) {
            $repository = $this->createStub(RefreshSessionRepository::class);
            $repository->method('getById')->willReturnCallback(static fn(): mixed => match ($mode) {
                'outage' => throw $primary,
                'native' => new stdClass(),
                default => $session
            });
            $repository->method('replace')->willReturn(false);
        }

        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RevokeSessionHandler(
            $repository,
            new FixedClock($now),
            new FixedSessionAdministrationAuthorization($mode !== 'denied'),
            $audit,
            $unitOfWork,
            $events
        );
        $router = new InMemoryCommandRouter();
        if ($mode !== 'unconfigured') {
            $router->registerHandler($handler::commandRegistration(), $handler);
        }

        $bus = new RoutingCommandBus($router);
        $target = in_array($mode, ['absent', 'identity'], true) ? RefreshSessionId::generate() : $session->getId();
        $command = new RevokeSession(
            $actor,
            $mode === 'current' ? $session->getId() : RefreshSessionId::generate(),
            $target,
            $mode === 'admin' ? SessionRevocationReason::fromString('Security review') : null
        );
        $message = CommandMessage::create(RevokeSession::fromArray($command->toArray()));
        $requestHandler = $this->createMock(RequestHandlerInterface::class);
        $requestHandler->expects(self::once())->method('handle')->willReturnCallback(
            static function () use ($bus, $command, $message, $decoded) {
                if ($decoded) {
                    $bus->dispatch($message);
                } else {
                    $bus->execute($command);
                }

                return new ResponseFactory()->createResponse(204);
            }
        );
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        if ($status === 204) {
            $diagnostics->expects(self::never())->method('report');
        } else {
            $diagnostics->expects(self::once())->method('report')->with(
                $mode === 'outage' ? self::identicalTo($primary) : self::isInstanceOf(Throwable::class)
            );
        }

        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );
        $response = $middleware->process(
            new ServerRequestFactory()->createServerRequest('POST', '/sessions/revoke'),
            $requestHandler
        );
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        self::assertSame($status === 204, $stored->all()[0]->isRevoked());
        self::assertCount($mode === 'admin' ? 1 : 0, $audit->all());
        if ($status === 204) {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertCount(1, $events->events());
        } else {
            self::assertStringContainsString(
                $status === 500 ? 'Internal server error.' : '"status":"fail"',
                (string) $response->getBody()
            );
        }
    }
}
