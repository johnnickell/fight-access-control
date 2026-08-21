<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\PasswordResetDeliveryCipher;

final class PrefixPasswordResetDeliveryCipher implements PasswordResetDeliveryCipher
{
    public function encrypt(string $plaintext): string
    {
        return 'ciphertext:'.$plaintext;
    }
}
