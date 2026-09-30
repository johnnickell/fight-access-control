<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;

/**
 * Consumer-bindable setup: write changes through owned disposable storage and read through the real package service.
 * Bind real adapter instances to verify fresh reads; this interface does not model framework enforcement.
 */
interface FeatureAvailabilityEnvironment
{
    public function permission(string $name): PermissionId;

    public function setFeature(string $name, FeatureStatus $status, PermissionId $binding): void;

    public function evaluator(): FeatureAvailability;
}
