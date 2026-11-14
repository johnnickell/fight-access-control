<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeaturePermissionHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\GetFeatureByIdHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ListFeaturesHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * Consumer-bindable public-port scenarios; adapters must additionally prove concurrent transaction overlaps.
 */
abstract class FeatureManagementConformance extends TestCase
{
    public function test_manual_creation_revision_and_safe_page_survive_stale_cross_field_edits(): void
    {
        $environment = $this->environment();
        $first = $this->permission('FIRST');
        $second = $this->permission('SECOND');
        $environment->storePermission($first);
        $environment->storePermission($second);

        $featureRepository = $environment->features();
        $permissionRepository = $environment->permissions();
        $unit = $environment->unitOfWork();
        $eventDispatcher = $environment->events();
        $name = FeatureName::fromString('dashboard');
        new CreateFeatureHandler($featureRepository, $permissionRepository, $unit, $eventDispatcher)
            ->handle(CommandMessage::create(new CreateFeature($name, $first->getId())));
        $stored = $featureRepository->getByName($name);
        self::assertInstanceOf(Feature::class, $stored);
        self::assertSame(FeatureStatus::OFF, $stored->getStatus());
        self::assertSame(1, $stored->getRevision());

        new SetFeaturePermissionHandler(
            $featureRepository,
            $permissionRepository,
            new FixedClock(new DateTimeImmutable('2026-01-01')),
            $unit,
            $eventDispatcher
        )->handle(
            CommandMessage::create(
                new SetFeaturePermission($stored->getId(), $second->getId(), 1)
            )
        );
        try {
            new SetFeatureStatusHandler(
                $featureRepository,
                new FixedClock(new DateTimeImmutable('2026-01-01')),
                $unit,
                $eventDispatcher
            )->handle(CommandMessage::create(
                new SetFeatureStatus($stored->getId(), FeatureStatus::ON, 1)
            ));
            self::fail('Cross-field stale edit must reject.');
        } catch (PreconditionFailed) {
            self::assertSame(2, $featureRepository->getById($stored->getId())?->getRevision());
        }

        $view = new GetFeatureByIdHandler($featureRepository, $permissionRepository)
            ->handle(QueryMessage::create(new GetFeatureById($stored->getId())));
        self::assertSame($second->getId(), $view->getPermissionId());
        self::assertSame(2, $view->getRevision());
        self::assertFalse($view->isBindingMissing());
        $page = new ListFeaturesHandler($featureRepository, $permissionRepository)
            ->handle(QueryMessage::create(new ListFeatures(new Pagination(1, 1, []))));
        self::assertSame(1, $page->totalRecords());
        self::assertSame(2, $page->records()->first()->getRevision());
        self::assertFalse($environment->removePermission($second));
        self::assertTrue($environment->removePermission($first));
        self::assertNull($permissionRepository->getById($first->getId()));
        self::assertSame($stored->getId(), $featureRepository->getByName($name)?->getId());
        self::assertSame($second->getId(), $featureRepository->getById($stored->getId())?->getPermissionId());
    }

    public function test_removed_target_cannot_become_a_binding_through_management(): void
    {
        $environment = $this->environment();
        $first = $this->permission('FIRST');
        $second = $this->permission('SECOND');
        $environment->storePermission($first);
        $environment->storePermission($second);

        $featureRepository = $environment->features();
        $name = FeatureName::fromString('dashboard');
        $create = new CreateFeatureHandler(
            $featureRepository,
            $environment->permissions(),
            $environment->unitOfWork(),
            $environment->events()
        );
        $create->handle(CommandMessage::create(new CreateFeature($name, $first->getId())));

        $stored = $featureRepository->getByName($name);
        self::assertInstanceOf(Feature::class, $stored);
        self::assertTrue($environment->removePermission($second));

        try {
            new SetFeaturePermissionHandler(
                $featureRepository,
                $environment->permissions(),
                new FixedClock(new DateTimeImmutable('2026-01-01')),
                $environment->unitOfWork(),
                $environment->events()
            )->handle(CommandMessage::create(new SetFeaturePermission($stored->getId(), $second->getId(), 1)));
            self::fail('Removal of the target must prevent rebinding.');
        } catch (ResourceNotFound) {
            self::assertSame($first->getId(), $featureRepository->getById($stored->getId())?->getPermissionId());
            self::assertSame(1, $featureRepository->getById($stored->getId())->getRevision());
            self::assertFalse($environment->removePermission($first));
            self::assertNull($environment->permissions()->getById($second->getId()));
        }
    }

    abstract protected function environment(): FeatureManagementEnvironment;

    protected function permission(string $name): Permission
    {
        return Permission::define(
            PermissionId::generate(),
            PermissionName::fromString($name),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }
}
