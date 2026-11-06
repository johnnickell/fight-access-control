<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeaturePermissionChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureRemoved;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureStatusChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationIssue;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeatureView;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\GetFeatureById;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ListFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\Common\Domain\Repository\Pagination;

use function PHPStan\dumpType;

static function (
    Feature $feature,
    FeatureId $id,
    FeatureRepository $repository,
    Pagination $pagination,
    CreateFeature $create,
    ProvisionFeatures $provision,
    RemoveFeature $remove,
    SetFeaturePermission $permission,
    SetFeatureStatus $status,
    FeatureCreated $created,
    FeaturePermissionChanged $permissionChanged,
    FeatureRemoved $removed,
    FeatureStatusChanged $statusChanged,
    FeaturePreparationIssue $issue,
    FeaturePreparationResult $result,
    FeatureView $view,
    GetFeatureById $get,
    ListFeatures $list,
    ValidateFeaturePreparation $validate
): void {
    dumpType($feature->getId());
    dumpType($feature->getPermissionId());
    dumpType($feature->getStatus());
    dumpType($repository->getById($id));
    dumpType($repository->getByName($feature->getName()));
    dumpType($repository->getAll($pagination));
    dumpType($create->toArray());
    dumpType($provision->toArray());
    dumpType($remove->toArray());
    dumpType($permission->toArray());
    dumpType($status->toArray());
    dumpType($created->toArray());
    dumpType($permissionChanged->toArray());
    dumpType($removed->toArray());
    dumpType($statusChanged->toArray());
    dumpType($issue->toArray());
    dumpType($result->getIssues());
    dumpType($result->toArray());
    dumpType($view->toArray());
    dumpType($get->toArray());
    dumpType($list->toArray());
    dumpType($validate->toArray());
    dumpType(array_map(
        static fn(FeaturePreparationIssue $item): string => $item->getName()->toString(),
        $result->getIssues()
    ));
};
