<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\CommandHandler;

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
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(CreateFeatureHandler::class)]
#[CoversClass(ProvisionFeaturesHandler::class)]
#[CoversClass(RemoveFeatureHandler::class)]
#[CoversClass(SetFeaturePermissionHandler::class)]
#[CoversClass(SetFeatureStatusHandler::class)]
final class FeatureMutationContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function mismatches(): iterable
    {
        foreach (
            ['create-permission', 'provision-existing', 'provision-default', 'remove', 'status',
            'permission-feature', 'permission-binding'] as $mode
        ) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('mismatches')]
    public function test_wrong_requested_identity_is_internal_before_any_write_or_noop(string $mode): void
    {
        $permissionId = PermissionId::generate();
        $featureId = FeatureId::generate();
        $name = FeatureName::fromString('dashboard');
        $feature = Feature::define($featureId, $name, $permissionId);
        if (in_array($mode, ['remove', 'status', 'permission-feature'], true)) {
            $feature = Feature::define(FeatureId::generate(), $name, $permissionId);
        } elseif ($mode === 'provision-existing') {
            $feature = Feature::define($featureId, FeatureName::fromString('unrelated'), $permissionId);
        }

        $permission = Permission::define(
            $mode === 'provision-default' ? $permissionId : PermissionId::generate(),
            PermissionName::fromString('UNRELATED'),
            new DateTimeImmutable('2026-01-01')
        );
        $features = $this->createMock(FeatureRepository::class);
        $features->method('getById')->willReturn($feature);
        $features->method('getByName')->willReturn($mode === 'provision-default' ? null : $feature);
        $permissions = $this->createMock(PermissionRepository::class);
        $permissions->method('getById')->willReturn($permission);
        $permissions->method('getByName')->willReturn($permission);
        foreach ([$features, $permissions] as $repository) {
            $repository->expects(self::never())->method('add');
            $repository->expects(self::never())->method('replace');
            $repository->expects(self::never())->method('remove');
        }

        $discovery = $this->createStub(FeatureReferenceDiscovery::class);
        $discovery->method('discover')->willReturnCallback(
            static fn(FeatureReferenceScope $scope): FeatureDiscoveryResult => FeatureDiscoveryResult::complete(
                $scope,
                FeatureReferences::fromStrings('dashboard')
            )
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $operation = match ($mode) {
            'create-permission' => [
                new CreateFeatureHandler($features, $permissions, new FixedClock('2026-01-01'), $unitOfWork, $events),
                new CreateFeature($name, $permissionId)
            ],
            'provision-existing', 'provision-default' => [
                new ProvisionFeaturesHandler($discovery, $features, $permissions, $unitOfWork, $events),
                new ProvisionFeatures('TEST_FEATURES')
            ],
            'remove' => [
                new RemoveFeatureHandler($discovery, $features, new FixedClock('2026-01-01'), $unitOfWork, $events),
                new RemoveFeature($featureId, 1)
            ],
            'status' => [
                new SetFeatureStatusHandler($features, $unitOfWork, $events),
                new SetFeatureStatus($featureId, FeatureStatus::OFF, 1)
            ],
            default => [
                new SetFeaturePermissionHandler($features, $permissions, $unitOfWork, $events),
                new SetFeaturePermission($featureId, $permissionId, 1)
            ]
        };
        [$handler, $command] = $operation;
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('A wrong lookup identity cannot become a mutation or a successful no-op.');
        } catch (UnexpectedValueException) {
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($command, $events->events()[0]->getCommand());
        }
    }
}
