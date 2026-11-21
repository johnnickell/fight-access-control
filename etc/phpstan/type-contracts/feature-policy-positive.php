<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeaturePermissionHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureAvailability;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;

return static function (
    CreateFeatureHandler $create,
    ProvisionFeaturesHandler $provision,
    RemoveFeatureHandler $remove,
    SetFeaturePermissionHandler $permission,
    SetFeatureStatusHandler $status,
    ReconcileManagedPolicyHandler $reconcile,
    FeatureAvailability $availability,
    FeatureName $name,
    FeatureReferenceDiscovery $discovery,
    FeatureDiscoveryResult $result,
    FeatureReferences $references
): void {
    PHPStan\dumpType($create->handle(...));
    PHPStan\dumpType($provision->handle(...));
    PHPStan\dumpType($remove->handle(...));
    PHPStan\dumpType($permission->handle(...));
    PHPStan\dumpType($status->handle(...));
    PHPStan\dumpType($reconcile->handle(...));
    PHPStan\dumpType($availability->isAvailable($name, null));
    PHPStan\dumpType($discovery->discover(FeatureReferenceScope::CURRENT));
    PHPStan\dumpType($result->getReferences(FeatureReferenceScope::CURRENT));
    PHPStan\dumpType($references->merge($references)->getNames());
};
