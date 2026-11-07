<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\Service\RegistrationRecoveryAuthorization;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

return static function (
    EnableUserHandler $enable,
    RegistrationRecoveryAuthorization $recoveryAuthorization,
    InvitationAdministrationAuthorization $invitationAuthorization,
    UserId $userId,
    RoleId $roleId,
    CommandMessage $message
): void {
    $recoveryAuthorization->assertCanRecoverRegistration($roleId, $userId);
    $invitationAuthorization->assertCanCorrectInvitation($userId, $roleId);
    (static fn (UserId $result): UserId => $result)($enable->handle($message));
};
