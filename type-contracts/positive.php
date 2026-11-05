<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;

use function PHPStan\dumpType;

static function (
    UserView $userView,
    UserRepository $userRepository,
    UserId $userId,
    FeatureReferences $references
): void {
    dumpType($userView->getUserId());
    dumpType($userView->getRoleIds());
    dumpType($userView->toArray());
    dumpType($userRepository->getById($userId));
    dumpType($references->getNames());
    dumpType(array_map(static fn(RoleId $roleId): string => $roleId->toString(), $userView->getRoleIds()));
};
