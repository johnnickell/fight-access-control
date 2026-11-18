<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferences;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Rejects a serialized string list where identity objects are required
 *
 * @param array{role_ids: list<RoleId>} $data
 */
function consumeRoleIdentityShape(array $data): void
{
}

/**
 * Exercises incompatible uses of real package contracts
 */
function rejectIncompatibleUses(
    UserView $userView,
    UserRepository $userRepository,
    UserId $userId,
    RoleId $roleId,
    EmailAddress $email,
    UserState $state,
    DateTimeImmutable $now
): void {
    $userRepository->getById($roleId);
    new UserView($userId, $email, $state, [$userId], $now, $now);
    consumeRoleIdentityShape($userView->toArray());
    (static function (User $user): void {
    })($userRepository->getById($userId));
    new FeatureReferences($roleId);
    array_map(static fn(RoleId $id): string => $id->toString(), $userView->toArray()['role_ids']);
}
