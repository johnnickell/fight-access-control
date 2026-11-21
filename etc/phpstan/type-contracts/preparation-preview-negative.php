<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRolePlanItem;

static function (FeaturePreparationResult $result, ManagedPolicyPlan $plan): void {
    (static fn(FeaturePreparationResult $expected): bool => $expected->isPrepared())($plan);
    (static fn(ManagedPolicyPlan $expected): array => $expected->getRoles())($result);
    array_map(static fn(ManagedRolePlanItem $item): string => $item->getAction()->value, $plan->getPermissions());
};
