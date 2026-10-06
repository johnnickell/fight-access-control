<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;

/**
 * Consumer adapter binding for persisted Permission/Feature reference conflicts.
 * Each operation is a committed transaction unless it rejects; reads must be authoritative.
 */
interface PermissionFeatureReferenceEnvironment
{
    public function storePermission(Permission $permission): void;

    public function storeFeature(Feature $feature): void;

    public function removePermission(Permission $permission): bool;

    public function permission(Permission $permission): ?Permission;

    public function feature(Feature $feature): ?Feature;
}
