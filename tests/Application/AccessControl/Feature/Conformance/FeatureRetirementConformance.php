<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use PHPUnit\Framework\TestCase;

/**
 * Consumer-bindable public-port retirement and stale-state scenarios; actual scanner and database proof is separate.
 */
abstract class FeatureRetirementConformance extends TestCase
{
    public function test_current_reference_cleanup_then_retirement_releases_permission_guard(): void
    {
        $environment = $this->environment();
        $permission = $this->permission('FIRST');
        $environment->storePermission($permission);
        $name = FeatureName::fromString('dashboard');
        $featureRepository = $environment->features();
        new CreateFeatureHandler(
            $featureRepository,
            $environment->permissions(),
            $environment->unitOfWork(),
            $environment->events()
        )
            ->handle(CommandMessage::create(new CreateFeature($name, $permission->getId())));
        $stored = $featureRepository->getByName($name);
        self::assertInstanceOf(Feature::class, $stored);
        self::assertFalse($environment->removePermission($permission));
        $command = CommandMessage::create(new RemoveFeature($stored->getId(), 1));
        try {
            new RemoveFeatureHandler(
                $environment->discovery(['dashboard']),
                $featureRepository,
                $environment->unitOfWork(),
                $environment->events()
            )
                ->handle($command);
            self::fail('Current registration must block retirement.');
        } catch (RuleViolation) {
            self::assertSame($stored->getId()->toString(), $featureRepository->getByName($name)?->getId()->toString());
            self::assertFalse($environment->removePermission($permission));
        }

        new RemoveFeatureHandler(
            $environment->discovery([]),
            $featureRepository,
            $environment->unitOfWork(),
            $environment->events()
        )
            ->handle($command);
        self::assertNull($featureRepository->getByName($name));
        self::assertTrue($environment->removePermission($permission));
        self::assertNull($environment->permissions()->getById($permission->getId()));
    }

    public function test_stale_delete_cannot_remove_newer_settings_or_reintroduced_identity(): void
    {
        $environment = $this->environment();
        $permission = $this->permission('FIRST');
        $environment->storePermission($permission);
        $featureRepository = $environment->features();
        $name = FeatureName::fromString('dashboard');
        $create = new CreateFeatureHandler(
            $featureRepository,
            $environment->permissions(),
            $environment->unitOfWork(),
            $environment->events()
        );
        $create->handle(CommandMessage::create(new CreateFeature($name, $permission->getId())));

        $first = $featureRepository->getByName($name);
        self::assertInstanceOf(Feature::class, $first);
        new SetFeatureStatusHandler($featureRepository, $environment->unitOfWork(), $environment->events())
            ->handle(CommandMessage::create(new SetFeatureStatus($first->getId(), FeatureStatus::ON, 1)));
        $delete = new RemoveFeatureHandler(
            $environment->discovery([]),
            $featureRepository,
            $environment->unitOfWork(),
            $environment->events()
        );
        try {
            $delete->handle(CommandMessage::create(new RemoveFeature($first->getId(), 1)));
            self::fail('Stale revision must reject.');
        } catch (PreconditionFailed) {
            self::assertSame(FeatureStatus::ON, $featureRepository->getByName($name)?->getStatus());
        }

        $delete->handle(CommandMessage::create(new RemoveFeature($first->getId(), 2)));
        $create->handle(CommandMessage::create(new CreateFeature($name, $permission->getId())));
        $second = $featureRepository->getByName($name);
        self::assertInstanceOf(Feature::class, $second);
        self::assertNotSame($second->getId()->toString(), $first->getId()->toString());
        self::assertSame(FeatureStatus::OFF, $second->getStatus());
        self::assertSame(1, $second->getRevision());
        try {
            $delete->handle(CommandMessage::create(new RemoveFeature($first->getId(), 2)));
            self::fail('An old identity must not delete its successor.');
        } catch (ResourceNotFound) {
            self::assertSame($second->getId()->toString(), $featureRepository->getByName($name)->getId()->toString());
        }
    }

    public function test_repository_removal_compares_full_expected_state_not_just_id_and_revision(): void
    {
        $environment = $this->environment();
        $permission = $this->permission('FIRST');
        $other = $this->permission('OTHER');
        $environment->storePermission($permission);
        $environment->storePermission($other);

        $stored = Feature::define(FeatureId::generate(), FeatureName::fromString('dashboard'), $permission->getId());
        $unit = $environment->unitOfWork();
        $featureRepository = $environment->features();
        $unit->commitTransactional(static function () use ($featureRepository, $stored): void {
            $featureRepository->add($stored);
        });
        $mismatches = [
            Feature::define(FeatureId::generate(), $stored->getName(), $permission->getId()),
            Feature::define($stored->getId(), FeatureName::fromString('other-name'), $permission->getId()),
            Feature::define($stored->getId(), $stored->getName(), $other->getId()),
            Feature::reconstitute($stored->getId(), $stored->getName(), $permission->getId(), FeatureStatus::ON, 1),
            Feature::reconstitute($stored->getId(), $stored->getName(), $permission->getId(), FeatureStatus::OFF, 2)
        ];
        foreach ($mismatches as $expected) {
            self::assertFalse($unit->commitTransactional(static fn(): bool => $featureRepository->remove($expected)));
            $current = $featureRepository->getById($stored->getId());
            self::assertInstanceOf(Feature::class, $current);
            self::assertSame(1, $current->getRevision());
            self::assertSame(FeatureStatus::OFF, $current->getStatus());
            self::assertSame($stored->getName()->toString(), $current->getName()->toString());
            self::assertTrue($permission->getId()->equals($current->getPermissionId()));
            self::assertFalse($environment->removePermission($permission));
        }

        self::assertTrue($unit->commitTransactional(static fn(): bool => $featureRepository->remove($stored)));
        self::assertNull($featureRepository->getById($stored->getId()));
        self::assertTrue($environment->removePermission($permission));
    }

    abstract protected function environment(): FeatureRetirementEnvironment;

    private function permission(string $name): Permission
    {
        return Permission::define(
            PermissionId::generate(),
            PermissionName::fromString($name),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }
}
