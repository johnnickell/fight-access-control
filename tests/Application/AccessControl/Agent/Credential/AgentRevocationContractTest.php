<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
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
use Throwable;

#[CoversClass(AgentCredentialLifecycleService::class)]
final class AgentRevocationContractTest extends TestCase
{
    /** @return iterable<string, array{string, class-string}> */
    public static function refusals(): iterable
    {
        yield 'absent' => ['absent', ResourceNotFound::class];
        yield 'stale' => ['stale', StateConflict::class];
        yield 'already revoked' => ['revoked', StateConflict::class];
    }

    #[DataProvider('refusals')]
    public function test_intentional_absence_and_conflict_have_application_categories(string $case, string $type): void
    {
        $env = new ProvisioningEnvironment();
        $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        $agent = $env->agentRepository->all()[0];
        $repository = $this->createStub(AgentRepository::class);
        $repository->method('getOperationContract')->willReturn($env->agentRepository->getOperationContract());
        $repository->method('getById')->willReturn(match ($case) {
            'absent' => null,
            'revoked' => $agent->revoke($agent->getCreatedAt()),
            default => $agent
        });
        $repository->method('replace')->willReturn(false);
        $before = $env->operationRepository->operations;
        try {
            new AgentCredentialLifecycleService(
                $repository,
                $env->auditEvidenceRepository,
                new FixedClock($agent->getCreatedAt()),
                $env->transaction,
                $env->eventDispatcher
            )->revoke(UserId::generate(), $agent->getId());
            self::fail('Expected classified refusal.');
        } catch (Throwable $throwable) {
            self::assertInstanceOf($type, $throwable);
            self::assertNull($throwable->getPrevious());
            $this->assertSafeHttpFailure($throwable, $case === 'absent' ? 404 : 409);
        }

        self::assertSame($before, $env->operationRepository->operations);
        self::assertSame($agent, $env->agentRepository->all()[0]);
        self::assertCount(1, $env->auditEvidenceRepository->all());
        self::assertInstanceOf(AgentCredentialLifecycleFailed::class, array_last($env->eventDispatcher->events()));
    }

    public function test_wrong_lookup_identity_never_reaches_coupled_retirement(): void
    {
        $env = new ProvisioningEnvironment();
        $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        $other = new ProvisioningEnvironment();
        $other->bus()->commandBus->execute(new ProvisionAgent($other->key, $other->request));
        $repository = $this->createMock(AgentRepository::class);
        $repository->method('getOperationContract')->willReturn($env->agentRepository->getOperationContract());
        $repository->method('getById')->willReturn($other->agentRepository->all()[0]);
        $repository->expects(self::never())->method('replace');
        try {
            new AgentCredentialLifecycleService(
                $repository,
                $env->auditEvidenceRepository,
                new FixedClock($env->agentRepository->all()[0]->getCreatedAt()),
                $env->transaction,
                $env->eventDispatcher
            )->revoke(UserId::generate(), $env->agentRepository->all()[0]->getId());
            self::fail('Wrong identity must remain internal.');
        } catch (LogicException $logicException) {
            $this->assertSafeHttpFailure($logicException, 500);
        }
    }

    public function test_primary_fault_and_durable_state_survive_both_failure_publishers(): void
    {
        foreach ([false, true] as $postCommit) {
            $env = new ProvisioningEnvironment();
            $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
            $agent = $env->agentRepository->all()[0];
            $before = $env->operationRepository->operations;
            $primary = new RuntimeException('synthetic-provider-detail');
            $attempted = [];
            $dispatcher = new InMemoryEventDispatcher(static function (object $event) use (
                $primary,
                &$attempted
            ): void {
                if ($event instanceof AgentCredentialLifecycleFailed) {
                    $attempted[] = $event->toArray();
                    throw new RuntimeException('secondary-notification-detail');
                }

                throw $primary;
            });
            $repository = $env->agentRepository;
            if (!$postCommit) {
                $repository = $this->createStub(AgentRepository::class);
                $repository->method('getOperationContract')->willReturn($env->agentRepository->getOperationContract());
                $repository->method('getById')->willThrowException($primary);
            }

            try {
                new AgentCredentialLifecycleService(
                    $repository,
                    $env->auditEvidenceRepository,
                    new FixedClock($agent->getCreatedAt()),
                    $env->transaction,
                    $dispatcher
                )->revoke(UserId::generate(), $agent->getId());
                self::fail('Revocation does not contain its primary fault as issuance success.');
            } catch (RuntimeException $failure) {
                self::assertSame($primary, $failure);
                $this->assertSafeHttpFailure($failure, 500);
            }

            self::assertCount(1, $attempted);
            self::assertSame($attempted[0], AgentCredentialLifecycleFailed::fromArray($attempted[0])->toArray());
            self::assertStringNotContainsString('synthetic-provider-detail', serialize($attempted));
            self::assertStringNotContainsString('secondary-notification-detail', serialize($attempted));
            self::assertCount($postCommit ? 2 : 1, $env->auditEvidenceRepository->all());
            if ($postCommit) {
                self::assertNull($env->operationRepository->operations[$env->key->toString()]->getMaterial());
                self::assertNotSame($agent, $env->agentRepository->all()[0]);
            } else {
                self::assertSame($before, $env->operationRepository->operations);
                self::assertSame($agent, $env->agentRepository->all()[0]);
            }
        }
    }

    private function assertSafeHttpFailure(Throwable $failure, int $status): void
    {
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with($failure);
        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );
        $handler = new readonly class ($failure) implements RequestHandlerInterface {
            public function __construct(private Throwable $failure)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->failure;
            }
        };
        $response = $middleware->process(
            new ServerRequestFactory()->createServerRequest('POST', '/agent-revocation'),
            $handler
        );
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('synthetic-provider-detail', (string) $response->getBody());
        self::assertStringNotContainsString('trace', (string) $response->getBody());
    }
}
