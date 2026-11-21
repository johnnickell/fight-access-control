<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryAttemptResult;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryProvider;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;

(static function (
    CredentialDeliveryProvider $provider,
    CredentialDeliveryCipher $cipher,
    EncryptedCredentialMaterial $material,
    CredentialDeliveryInvocation $invocation
): void {
    $provider->deliver($material);
    $cipher->decrypt($invocation);
    $credential = static function (PasswordResetCredential $credential): void {
    };
    $credential($cipher->decrypt($material));
});

(static function (CredentialDeliveryAttemptResult $result): CredentialDeliveryFailure {
    return $result->getFailure();
});
