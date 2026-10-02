<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;

/**
 * Binds real consumer repositories, one transaction and an observable publisher to management scenarios.
 */
interface FeatureManagementEnvironment
{
    public function features(): FeatureRepository;

    public function permissions(): PermissionRepository;

    public function unitOfWork(): TransactionalUnitOfWork;

    public function events(): EventDispatcher;

    public function storePermission(Permission $permission): void;

    public function removePermission(Permission $permission): bool;
}
