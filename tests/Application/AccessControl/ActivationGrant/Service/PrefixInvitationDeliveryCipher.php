<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;

final class PrefixInvitationDeliveryCipher implements InvitationDeliveryCipher
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
