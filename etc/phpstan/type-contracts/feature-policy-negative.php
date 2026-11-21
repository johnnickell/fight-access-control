<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\CreateFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeaturePermissionHandler;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\SetFeatureStatusHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureDiscoveryResult;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler\ReconcileManagedPolicyHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

return static function (
    CreateFeatureHandler $create,
    ProvisionFeaturesHandler $provision,
    RemoveFeatureHandler $remove,
    SetFeaturePermissionHandler $permission,
    SetFeatureStatusHandler $status,
    ReconcileManagedPolicyHandler $reconcile,
    CommandMessage $message,
    FeatureDiscoveryResult $result
): void {
    echo $create->handle($message);
    echo $provision->handle($message);
    echo $remove->handle($message);
    echo $permission->handle($message);
    echo $status->handle($message);
    echo $reconcile->handle($message);
    $result->getReferences('current');
    new FeatureReferences(FeatureName::fromString('dashboard')->toString());
};
