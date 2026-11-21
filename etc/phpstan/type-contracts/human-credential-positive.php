<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryAttemptResult;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryProvider;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetCredentialGenerator;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;

(static function (
    ActivationCredentialGenerator $activation,
    PasswordResetCredentialGenerator $reset,
    EmailChangeCredentialGenerator $email,
    CredentialDeliveryCipher $cipher,
    CredentialDeliveryProvider $provider,
    CredentialDeliveryInvocation $invocation,
    CredentialDeliveryAttemptResult $result,
    EncryptedCredentialMaterial $material
): void {
    \PHPStan\dumpType($activation->generate());
    \PHPStan\dumpType($reset->generate());
    \PHPStan\dumpType($email->generate());
    \PHPStan\dumpType($cipher->encrypt('fixture'));
    \PHPStan\dumpType($cipher->decrypt($material));
    \PHPStan\dumpType($provider->deliver($invocation));
    \PHPStan\dumpType($result->getOutcome());
    \PHPStan\dumpType($result->getFailure());
    \PHPStan\dumpType($invocation->getPurpose());
    \PHPStan\dumpType($invocation->getActivationOrigin());
    \PHPStan\dumpType($invocation->__debugInfo());
});
