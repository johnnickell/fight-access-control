<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Command;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeaturePermissionHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Throwable;

#[CoversClass(CreateFeatureHandler::class)]
#[CoversClass(ProvisionFeaturesHandler::class)]
#[CoversClass(RemoveFeatureHandler::class)]
#[CoversClass(SetFeaturePermissionHandler::class)]
#[CoversClass(SetFeatureStatusHandler::class)]
#[CoversClass(ReconcileManagedPolicyHandler::class)]
final class FeaturePolicyCommandTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, bool}> */
    public static function scenarios(): iterable
    {
        $families = [
            'create'     => ['success', 'absent', 'conflict', 'wrong', 'outage'],
            'provision'  => ['success', 'noop', 'empty', 'unavailable', 'default', 'conflict', 'wrong', 'outage'],
            'remove'     => ['success', 'absent', 'stale', 'conflict', 'referenced', 'unavailable', 'wrong', 'outage'],
            'status'     => ['success', 'noop', 'absent', 'stale', 'conflict', 'binding', 'wrong', 'outage'],
            'permission' => ['success', 'noop', 'absent', 'stale', 'conflict', 'binding', 'wrong', 'outage'],
            'policy'     => ['success', 'noop', 'conflict', 'refused', 'wrong', 'outage']
        ];
        foreach ($families as $family => $modes) {
            foreach ($modes as $mode) {
                $status = match ($mode) {
                    'success', 'noop', 'empty' => 204,
                    'absent' => 404,
                    'stale' => 412,
                    'conflict', 'binding' => 409,
                    'referenced', 'refused' => 422,
                    default => 500
                };
                foreach ([false, true] as $decoded) {
                    yield $family.'-'.$mode.'-'.(int) $decoded => [$family, $mode, $status, $decoded];
                }
            }
        }
    }

    #[DataProvider('scenarios')]
    public function test_real_routing_and_opt_in_safe_errors(
        string $family,
        string $mode,
        int $status,
        bool $decoded
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $now = new DateTimeImmutable('2026-01-01');
        $storedPermissions = new InMemoryPermissionRepository($unitOfWork);
        $permission = Permission::defineManaged(
            PermissionId::generate(),
            PermissionName::fromString('TEST_FEATURES'),
            PermissionTier::ADMIN_SAFE,
            $now
        );
        $storedPermissions->add($permission);
        $selectedPermission = $permission;
        if ($family === 'permission' && $mode === 'success') {
            $selectedPermission = Permission::define(
                PermissionId::generate(),
                PermissionName::fromString('SECOND'),
                $now
            );
            $storedPermissions->add($selectedPermission);
        }

        $storedFeatures = new InMemoryFeatureRepository($unitOfWork);
        $feature = Feature::define(FeatureId::generate(), FeatureName::fromString('dashboard'), $permission->getId());
        if (!in_array($family, ['create', 'provision'], true) || $mode === 'noop') {
            $storedFeatures->seed($feature);
        }

        $features = $storedFeatures;
        $permissions = $storedPermissions;
        $primary = new RuntimeException('PRIVATE operational diagnostic', 404);
        if (in_array($mode, ['absent', 'conflict', 'binding', 'wrong', 'outage'], true)) {
            if ($family === 'create' || $family === 'policy') {
                $permissions = $this->createStub(PermissionRepository::class);
                $permissions->method('getById')->willReturnCallback(static fn(): ?Permission => match ($mode) {
                    'absent' => null,
                    'outage' => throw $primary,
                    'wrong' => Permission::define(PermissionId::generate(), $permission->getName(), $now),
                    default => $permission
                });
                $permissions->method('replace')->willReturn(false);
            } else {
                $features = $this->createStub(FeatureRepository::class);
                $returned = $feature;
                if ($mode === 'wrong') {
                    $returned = Feature::define(
                        FeatureId::generate(),
                        FeatureName::fromString('unrelated'),
                        $permission->getId()
                    );
                }

                $features->method('getById')->willReturnCallback(static fn(): ?Feature => match ($mode) {
                    'absent' => null,
                    'outage' => throw $primary,
                    default => $returned
                });
                $features->method('getByName')->willReturnCallback(static fn(): ?Feature => match ($mode) {
                    'conflict' => null,
                    'outage' => throw $primary,
                    default => $returned
                });
                $features->method('add')->willThrowException(new FeatureConflictException('Concurrent Feature.'));
                $features->method('replace')->willReturnCallback(static function () use ($mode): bool {
                    if ($mode === 'binding') {
                        throw new FeatureReferenceException('Concurrent Permission removal.');
                    }

                    return false;
                });
                $features->method('remove')->willReturn(false);
            }
        }

        if ($family === 'create' && $mode === 'conflict') {
            $storedFeatures->seed($feature);
        }

        if ($family === 'policy' && $mode === 'refused') {
            $permissions = $this->createStub(PermissionRepository::class);
            $permissions->method('getById')->willReturn(Permission::define(
                $permission->getId(),
                $permission->getName(),
                $now
            ));
        }

        $discovery = $this->createStub(FeatureReferenceDiscovery::class);
        $discovery->method('discover')->willReturnCallback(static function (FeatureReferenceScope $scope) use (
            $mode,
            $family
        ): FeatureDiscoveryResult {
            if ($mode === 'unavailable') {
                return FeatureDiscoveryResult::unavailable($scope);
            }

            return FeatureDiscoveryResult::complete(
                $scope,
                FeatureReferences::fromStrings(...match (true) {
                    $mode === 'empty' => [],
                    $family === 'provision', $mode === 'referenced' => ['dashboard'],
                    default => []
                })
            );
        });
        $events = new InMemoryEventDispatcher();
        $revision = $mode === 'stale' ? 2 : 1;
        $roles = new InMemoryRoleRepository($unitOfWork);
        $handler = match ($family) {
            'create' => new CreateFeatureHandler(
                $features,
                $permissions, new FixedClock('2026-01-01'), $unitOfWork, $events
            ),
            'provision' => new ProvisionFeaturesHandler($discovery, $features, $permissions, $unitOfWork, $events),
            'remove' => new RemoveFeatureHandler(
                $discovery,
                $features, new FixedClock('2026-01-01'), $unitOfWork, $events
            ),
            'status' => new SetFeatureStatusHandler($features, $unitOfWork, $events),
            'permission' => new SetFeaturePermissionHandler($features, $permissions, $unitOfWork, $events),
            default => new ReconcileManagedPolicyHandler(
                $permissions,
                $roles,
                new ManagedPolicyPlanner(
                    $permissions,
                    $roles,
                    new InMemoryUserRepository($unitOfWork),
                    new InMemoryAgentRepository($unitOfWork)
                ),
                $unitOfWork,
                $events,
                new FixedClock($now)
            )
        };
        $command = match ($family) {
            'create' => new CreateFeature($feature->getName(), $permission->getId()),
            'provision' => new ProvisionFeatures($mode === 'default' ? null : 'TEST_FEATURES'),
            'remove' => new RemoveFeature($feature->getId(), $revision),
            'status' => new SetFeatureStatus(
                $feature->getId(),
                $mode === 'noop' ? FeatureStatus::OFF : FeatureStatus::ON,
                $revision
            ),
            'permission' => new SetFeaturePermission($feature->getId(), $selectedPermission->getId(), $revision),
            default => new ReconcileManagedPolicy(new ManagedPolicy([new ManagedPermissionDefinition(
                $permission->getId(),
                $mode === 'noop' ? $permission->getName() : PermissionName::fromString('RENAMED'),
                PermissionTier::ADMIN_SAFE
            )], []))
        };
        $router = new InMemoryCommandRouter();
        $router->registerHandler($handler::commandRegistration(), $handler);

        $bus = new RoutingCommandBus($router);
        $message = CommandMessage::create($command::fromArray($command->toArray()));
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

        $response = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        )->process(new ServerRequestFactory()->createServerRequest('POST', '/feature-policy'), $requestHandler);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE', (string) $response->getBody());
        self::assertSame($status === 204, $unitOfWork->transactionCompleted);
        if ($status !== 204) {
            self::assertStringContainsString(
                $status === 500 ? 'Internal server error.' : '"status":"fail"',
                (string) $response->getBody()
            );
        } else {
            if ($mode === 'noop' || $mode === 'empty') {
                self::assertSame(0, $storedFeatures->writes);
                self::assertCount($family === 'policy' ? 1 : 0, $events->events());
            }

            $current = $storedFeatures->getByName($feature->getName());
            if ($family === 'policy') {
                $currentPermission = $storedPermissions->getById($permission->getId());
                self::assertNotNull($currentPermission);
                self::assertSame(
                    $mode === 'noop' ? 'TEST_FEATURES' : 'RENAMED',
                    $currentPermission->getName()->toString()
                );
                if ($mode === 'noop') {
                    self::assertSame($permission, $currentPermission);
                }
            } elseif ($family === 'remove' || $mode === 'empty') {
                self::assertNull($current);
            } else {
                self::assertNotNull($current);
                self::assertTrue($current->getPermissionId()->equals($selectedPermission->getId()));
                self::assertSame(
                    $family === 'status' && $mode !== 'noop' ? FeatureStatus::ON : FeatureStatus::OFF,
                    $current->getStatus()
                );
                self::assertSame(
                    $mode === 'success' && in_array($family, ['status', 'permission'], true) ? 2 : 1,
                    $current->getRevision()
                );
            }
        }
    }
}
