<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;

final class PrefixPasswordResetDeliveryCipher implements PasswordResetDeliveryCipher
{
    public function encrypt(string $plaintext): string
    {
        return 'ciphertext:'.$plaintext;
    }

    public function decrypt(EncryptedCredentialMaterial $encryptedMaterial): string
    {
        return substr($encryptedMaterial->reveal(), strlen('ciphertext:'));
    }
}
