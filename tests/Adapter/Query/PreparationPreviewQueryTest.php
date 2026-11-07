<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Query;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Type\Arrayable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use stdClass;
use Throwable;

#[CoversClass(ValidateFeaturePreparationHandler::class)]
#[CoversClass(PreviewManagedPolicyHandler::class)]
#[CoversClass(ManagedPolicyPlanner::class)]
final class PreparationPreviewQueryTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?int}> */
    public static function scenarios(): iterable
    {
        foreach (['preparation', 'preview'] as $family) {
            foreach (['empty', 'valid', 'outage', 'native', 'identity', 'unconfigured'] as $mode) {
                yield $family.'-'.$mode => [$family, $mode, in_array($mode, ['empty', 'valid'], true) ? null : 500];
            }
        }

        yield 'missing-feature' => ['preparation', 'missing', null];
        yield 'broken-binding' => ['preparation', 'binding', null];
        yield 'malformed-feature' => ['preparation', 'malformed', null];
        yield 'wrong-binding' => ['preparation', 'wrong-binding', 500];
        yield 'unavailable-discovery' => ['preparation', 'discovery', 500];
        yield 'intentional-policy-refusal' => ['preview', 'refusal', 422];
    }

    #[DataProvider('scenarios')]
    public function test_configured_fetch_serialized_dispatch_and_safe_errors_are_read_only(
        string $family,
        string $mode,
        ?int $status
    ): void {
        $failure = new RuntimeException('PRIVATE provider diagnostic', 404);
        [$query, $handler, $expected] = $this->fixture($family, $mode, $failure);
        $router = new InMemoryQueryRouter();
        if ($mode !== 'unconfigured') {
            $router->registerHandler($handler::queryRegistration(), $handler);
        }

        $bus = new RoutingQueryBus($router);
        $decoded = $query::fromArray($query->toArray());
        if ($status === null) {
            foreach ([$bus->fetch($query), $bus->dispatch(QueryMessage::create($decoded))] as $result) {
                self::assertInstanceOf(Arrayable::class, $result);
                self::assertSame($expected, $result->toArray());
                self::assertStringNotContainsString('PRIVATE', serialize($result));
            }

            return;
        }

        try {
            $bus->fetch($query);
            self::fail('The query must not fabricate a successful result.');
        } catch (Throwable $throwable) {
            self::assertSame($status, new SafeHttpErrorPolicy()->status($throwable));
            if ($mode === 'outage') {
                self::assertSame($failure, $throwable);
            }
        }

        $requestHandler = $this->createMock(RequestHandlerInterface::class);
        $requestHandler->expects(self::once())->method('handle')->willReturnCallback(
            static function () use ($bus, $decoded): ResponseInterface {
                $bus->dispatch(QueryMessage::create($decoded));

                return new ResponseFactory()->createResponse(204);
            }
        );
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with(self::isInstanceOf(Throwable::class));
        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );
        $response = $middleware->process(
            new ServerRequestFactory()->createServerRequest('POST', '/preparation'),
            $requestHandler
        );
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        self::assertStringContainsString(
            $status === 422 ? 'Rule violation.' : 'Internal server error.',
            (string) $response->getBody()
        );
    }

    /** @return array{Query, QueryHandler, array<string, mixed>} */
    private function fixture(string $family, string $mode, RuntimeException $failure): array
    {
        $now = new DateTimeImmutable('2026-01-01');
        $id = PermissionId::generate();
        $name = PermissionName::fromString('READ_CASES');
        $permission = Permission::defineManaged($id, $name, PermissionTier::ADMIN_SAFE, $now);
        $permissions = $this->createMock(PermissionRepository::class);
        foreach (['add', 'replace', 'remove'] as $method) {
            $permissions->expects(self::never())->method($method);
        }

        if ($family === 'preparation') {
            $discovery = $this->createStub(FeatureReferenceDiscovery::class);
            $discovered = FeatureDiscoveryResult::complete(
                FeatureReferenceScope::CANDIDATE,
                FeatureReferences::fromStrings(...($mode === 'empty' ? [] : ['dashboard']))
            );
            if ($mode === 'discovery') {
                $discovered = FeatureDiscoveryResult::unavailable(FeatureReferenceScope::CANDIDATE);
            }

            $discovery->method('discover')->willReturn($discovered);
            $features = $this->createMock(FeatureRepository::class);
            foreach (['add', 'replace', 'remove'] as $method) {
                $features->expects(self::never())->method($method);
            }

            $feature = Feature::define(
                FeatureId::generate(),
                FeatureName::fromString($mode === 'identity' ? 'other' : 'dashboard'),
                $id
            );
            $features->method('getByName')->willReturnCallback(static fn(): mixed => match ($mode) {
                'outage' => throw $failure,
                'native' => new stdClass(),
                'malformed' => throw new FeatureStateException('PRIVATE invalid row'),
                'missing' => null,
                default => $feature,
            });
            $permissions->method('getById')->willReturn(match ($mode) {
                'binding' => null,
                'wrong-binding' => Permission::define(PermissionId::generate(), $name, $now),
                default => $permission,
            });
            $problem = match ($mode) {
                'missing' => 'missing_feature',
                'binding' => 'broken_binding',
                'malformed' => 'invalid_definition',
                default => null,
            };

            return [new ValidateFeaturePreparation(),
                new ValidateFeaturePreparationHandler($discovery, $features, $permissions),
                ['prepared'  => $problem === null,
                    'issues' => $problem === null ? [] : [['name' => 'dashboard', 'problem' => $problem]]]];
        }

        $roles = $this->createMock(RoleRepository::class);
        foreach (['add', 'replace', 'remove'] as $method) {
            $roles->expects(self::never())->method($method);
        }

        $permissions->method('getById')->willReturnCallback(static fn(): mixed => match ($mode) {
            'outage' => throw $failure,
            'native' => new stdClass(),
            'identity' => Permission::defineManaged(PermissionId::generate(), $name, PermissionTier::ADMIN_SAFE, $now),
            'refusal' => Permission::define($id, $name, $now),
            default => null,
        });
        $definition = new ManagedPermissionDefinition($id, $name, PermissionTier::ADMIN_SAFE);
        $policy = new ManagedPolicy($mode === 'empty' ? [] : [$definition], []);

        return [new PreviewManagedPolicy($policy), new PreviewManagedPolicyHandler(new ManagedPolicyPlanner(
            $permissions,
            $roles,
            $this->createStub(UserRepository::class),
            $this->createStub(AgentRepository::class)
        )), [
            'permissions' => $mode === 'empty' ? [] : [[...$definition->toArray(), 'action' => 'CREATE']],
            'roles'       => []
        ]];
    }
}
