<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

static function (FeatureId $featureId, ProvisionFeatures $command): void {
    new ProvisionFeatures(null, $featureId);
    new ProvisionFeatures(null, 'system');
    (static function (UserId $actor): void {
    })($command->getActorId());
};
