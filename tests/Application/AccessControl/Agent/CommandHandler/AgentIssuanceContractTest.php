<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationRejection;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentIssuanceFailed;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentRotationConflict;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\ServerError;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\OperationFailureAssertions;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\RotationEnvironment;
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

#[CoversClass(AgentOperationRejection::class)]
#[CoversClass(AgentIssuanceFailed::class)]
#[CoversClass(AgentRotationConflict::class)]
#[CoversClass(ProvisionAgentHandler::class)]
#[CoversClass(RotateAgentCredentialHandler::class)]
final class AgentIssuanceContractTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function operations(): iterable
    {
        yield 'provision' => [false];
        yield 'rotation' => [true];
    }

    #[DataProvider('operations')]
    public function test_current_authority_refusal_uses_the_application_category(bool $rotation): void
    {
        $env = new ProvisioningEnvironment();
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? $env;
        $env->operationAuthorization->scopes = [];

        $command = $this->command($env, $rotate);
        $bus = $rotate?->bus() ?? $env->bus();
        $this->expectException(PermissionDenied::class);
        $bus->commandBus->execute($command);
    }

    #[DataProvider('operations')]
    public function test_authorization_port_rejects_out_of_contract_failure_reasons(bool $rotation): void
    {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? new ProvisioningEnvironment();
        $env->operationAuthorization->afterAuthorization = static function (): void {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        };
        $command = $this->command($env, $rotate);
        $this->expectException(ServerError::class);
        ($rotate?->bus() ?? $env->bus())->commandBus->execute($command);
    }

    #[DataProvider('operations')]
    public function test_material_provider_has_no_authorization_failure_intent(bool $rotation): void
    {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? new ProvisioningEnvironment();
        $generator = $this->createStub(HmacSharedSecretGenerator::class);
        $generator->method('generate')->willThrowException(
            new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED)
        );
        $command = $this->command($env, $rotate);
        $this->expectException(ServerError::class);
        ($rotate?->bus(generator: $generator) ?? $env->bus(generator: $generator))->commandBus->execute($command);
    }

    #[DataProvider('operations')]
    public function test_retained_lookup_enforces_its_requested_key(bool $rotation): void
    {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? new ProvisioningEnvironment();
        $key = $rotate->key ?? $env->key;
        $command = $this->command($env, $rotate, $key);
        $bus = $rotate?->bus() ?? $env->bus();
        $bus->commandBus->execute($command);

        $otherKey = new AgentOperationKey($key->getScope(), AgentOperationId::generate());
        $stored = $env->operationRepository->operations[$key->toString()];
        $env->operationRepository->operations[$otherKey->toString()] = $stored;
        $command = $this->command($env, $rotate, $otherKey);
        $this->expectException(ServerError::class);
        $bus->commandBus->execute($command);
    }

    public function test_rotation_lookup_enforces_its_requested_agent(): void
    {
        $rotate = new RotationEnvironment();
        $env = $rotate->provisioning;
        $original = $env->agentRepository->all()[0];
        $repository = $this->createStub(AgentRepository::class);
        $repository->method('getOperationContract')->willReturn($env->agentRepository->getOperationContract());
        $repository->method('getById')->willReturn(Agent::provision(
            AgentId::generate(),
            $original->getName(),
            $original->getCredentialId(),
            'test-envelope',
            $original->getCreatedAt()
        ));
        $repository->method('replace')->willReturn(true);
        $this->expectException(ServerError::class);
        $rotate->bus(agentRepository: $repository)->commandBus->execute(new RotateAgentCredential(
            $rotate->key,
            $rotate->request
        ));
    }

    /** @return iterable<string, array{bool, string, AgentOperationFailure, ?AgentOperationFailure}> */
    public static function boundaryRefusals(): iterable
    {
        foreach ([false, true] as $rotation) {
            $sources = ['cohort', 'lookup', 'reservation', 'agent', 'insertion'];
            if ($rotation) {
                $sources[] = 'replacement';
            }

            foreach ($sources as $source) {
                yield (int) $rotation.':'.$source.':unavailable' => [
                    $rotation, $source, AgentOperationFailure::UNAVAILABLE, AgentOperationFailure::UNAVAILABLE
                ];
                yield (int) $rotation.':'.$source.':invalid' => [
                    $rotation, $source, AgentOperationFailure::INVALID_REQUEST, null
                ];
            }

            yield (int) $rotation.':reservation:conflict' => [
                $rotation, 'reservation', AgentOperationFailure::CONFLICT, AgentOperationFailure::CONFLICT
            ];
            yield (int) $rotation.':insertion:capacity' => [
                $rotation, 'insertion', AgentOperationFailure::CAPACITY, AgentOperationFailure::CAPACITY
            ];
        }
    }

    #[DataProvider('boundaryRefusals')]
    public function test_only_boundary_qualified_reasons_become_application_refusals(
        bool $rotation,
        string $source,
        AgentOperationFailure $reason,
        ?AgentOperationFailure $expected
    ): void {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? new ProvisioningEnvironment();
        $operationRepository = $this->createStub(AgentCredentialOperationRepository::class);
        $agentRepository = $this->createStub(AgentRepository::class);
        $contract = $env->operationRepository->getOperationContract();
        $operationRepository->method('getOperationContract')->willReturn($contract);
        $agentRepository->method('getOperationContract')->willReturn($contract);
        $agentRepository->method('getById')->willReturn($env->agentRepository->all()[0] ?? null);
        $agentRepository->method('replace')->willReturn(true);
        $operationRepository->method('reserveDestinationWrite')->willReturn(2);
        $failure = new AgentOperationRejectedException($reason);
        if ($source === 'agent' || $source === 'replacement') {
            $method = $source === 'replacement' ? 'replace' : ($rotation ? 'getById' : 'add');
            $agentRepository->method($method)->willThrowException($failure);
        } else {
            $method = match ($source) {
                'cohort' => 'getOperationContract',
                'lookup' => 'getByKey',
                'reservation' => 'reserveDestinationWrite',
                default => 'add'
            };
            $operationRepository->method($method)->willThrowException($failure);
        }

        $bus = $env->bus(agentRepository: $agentRepository, operationRepository: $operationRepository);
        if ($rotate !== null) {
            $bus = $rotate->bus(agentRepository: $agentRepository, operationRepository: $operationRepository);
        }

        $command = $this->command($env, $rotate);
        try {
            $bus->commandBus->execute($command);
            self::fail('A refused boundary cannot return success.');
        } catch (Throwable $throwable) {
            OperationFailureAssertions::assertMatches($expected, $throwable);
            self::assertNull($throwable->getPrevious());
            $status = match ($expected) {
                null => 500,
                AgentOperationFailure::CONFLICT => 409,
                AgentOperationFailure::CAPACITY => 429,
                default => 503
            };
            self::assertSame($status, new SafeHttpErrorPolicy()->status($throwable));
            $this->assertHttpOutcome(static fn () => $bus->commandBus->execute($command), $status);
        }
    }

    #[DataProvider('operations')]
    public function test_nonpositive_repository_order_is_internal(bool $rotation): void
    {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? new ProvisioningEnvironment();
        $repository = $this->createStub(AgentCredentialOperationRepository::class);
        $repository->method('getOperationContract')->willReturn($env->operationRepository->getOperationContract());
        $repository->method('reserveDestinationWrite')->willReturn(0);
        $command = $this->command($env, $rotate);
        $this->expectException(ServerError::class);
        ($rotate?->bus(operationRepository: $repository) ?? $env->bus(operationRepository: $repository))
            ->commandBus->execute($command);
    }

    #[DataProvider('operations')]
    public function test_matching_request_requires_coherent_original_issuance(bool $rotation): void
    {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? new ProvisioningEnvironment();
        if ($rotate === null) {
            $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        }

        $original = $env->operationRepository->operations[$env->key->toString()]->getIssuance();
        $key = $rotate->key ?? $env->key;
        $request = $rotate->request ?? $env->request;
        $issuance = new AgentIssuance(
            $key,
            $original->getDeliveryId(),
            $original->getAgentId(),
            $original->getCredentialId(),
            $rotation ? 0 : 1,
            $original->getDestination(),
            1,
            $original->getIssuedAt()
        );
        $env->operationRepository->operations[$key->toString()] = new AgentCredentialOperation(
            2,
            $request->canonicalize(),
            $issuance,
            null,
            AgentDeliveryDisposition::RETIRED
        );
        $command = $this->command($env, $rotate, $key);
        $this->expectException(ServerError::class);
        ($rotate?->bus() ?? $env->bus())->commandBus->execute($command);
    }

    #[DataProvider('operations')]
    public function test_unknown_transaction_fault_is_an_internal_application_failure(bool $rotation): void
    {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate->provisioning ?? new ProvisioningEnvironment();
        $before = $env->operationRepository->operations;
        $env->operationAuthorization->afterAuthorization = static function (): void {
            throw new RuntimeException('Operation dependency failed.');
        };
        $command = $this->command($env, $rotate);
        $bus = $rotate?->bus() ?? $env->bus();
        try {
            $bus->commandBus->execute($command);
            self::fail('A failed execution must not return success.');
        } catch (Throwable $throwable) {
            self::assertInstanceOf(ServerError::class, $throwable);
            self::assertNull($throwable->getPrevious());
            self::assertSame(500, new SafeHttpErrorPolicy()->present($throwable)[0]);
        }

        self::assertSame($before, $env->operationRepository->operations);
    }

    private function command(
        ProvisioningEnvironment $env,
        ?RotationEnvironment $rotate,
        ?AgentOperationKey $key = null
    ): ProvisionAgent|RotateAgentCredential {
        if ($rotate !== null) {
            return new RotateAgentCredential($key ?? $rotate->key, $rotate->request);
        }

        return new ProvisionAgent($key ?? $env->key, $env->request);
    }

    private function assertHttpOutcome(Closure $execute, int $status): void
    {
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with(self::callback(
            static fn (Throwable $failure): bool => new SafeHttpErrorPolicy()->status($failure) === $status
        ));
        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );
        $handler = new readonly class ($execute) implements RequestHandlerInterface {
            public function __construct(private Closure $execute)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->execute)();
                throw new RuntimeException('The expected operation failure did not occur.');
            }
        };
        $response = $middleware->process(
            new ServerRequestFactory()->createServerRequest('POST', '/agent-operations'),
            $handler
        );
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('trace', (string) $response->getBody());
    }
}
