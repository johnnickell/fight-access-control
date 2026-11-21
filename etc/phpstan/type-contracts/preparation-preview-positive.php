<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler\PreviewManagedPolicyHandler;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

use function PHPStan\dumpType;

static function (
    ValidateFeaturePreparationHandler $preparation,
    PreviewManagedPolicyHandler $preview,
    ManagedPolicyPlanner $planner,
    ManagedPolicy $policy,
    QueryMessage $message
): void {
    $result = $preparation->handle($message);
    $plan = $preview->handle($message);
    dumpType($result);
    dumpType($result->getIssues());
    dumpType($result->toArray());
    dumpType($plan);
    dumpType($planner->plan($policy));
    dumpType($plan->getPermissions());
    dumpType($plan->getRoles());
    dumpType($plan->toArray());
};
