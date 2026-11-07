<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationAdministrationAuthorization;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\InvitePendingUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RecoverPendingRegistrationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Application\AccessControl\User\Service\RegistrationRecoveryAuthorization;

return static function (
    RegisterUserHandler $register,
    InvitePendingUserHandler $invite,
    RecoverPendingRegistrationHandler $recover,
    CorrectPendingInvitationHandler $correct,
    EnableUserHandler $enable,
    DisableUserHandler $disable,
    DeleteUserHandler $delete,
    RestoreUserHandler $restore,
    RegistrationRecoveryAuthorization $recoveryAuthorization,
    InvitationAdministrationAuthorization $invitationAuthorization
): void {
    PHPStan\dumpType($register->handle(...));
    PHPStan\dumpType($invite->handle(...));
    PHPStan\dumpType($recover->handle(...));
    PHPStan\dumpType($correct->handle(...));
    PHPStan\dumpType($enable->handle(...));
    PHPStan\dumpType($disable->handle(...));
    PHPStan\dumpType($delete->handle(...));
    PHPStan\dumpType($restore->handle(...));
    PHPStan\dumpType($recoveryAuthorization->assertCanRecoverRegistration(...));
    PHPStan\dumpType($invitationAuthorization->assertCanCorrectInvitation(...));
};
