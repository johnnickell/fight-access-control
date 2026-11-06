<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationIssue;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;

static function (
    FeatureRepository $repository,
    FeatureId $id,
    PermissionId $permissionId,
    FeaturePreparationResult $result,
    FeatureView $view
): void {
    $repository->getById($permissionId);
    new SetFeaturePermission($id, $id, 1);
    new FeaturePreparationResult($view);
    (static function (Feature $feature): void {
    })($repository->getById($id));
    array_map(static fn(FeatureId $id): string => $id->toString(), $result->getIssues());
    (static function (FeaturePreparationIssue $issue): void {
    })($result->toArray()['issues'][0]);
    (static function (int $revision): void {
    })($view->toArray()['permission_name']);
};
