<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\ActivationDeliveryCipher;

final class PrefixCipher implements ActivationDeliveryCipher
{
    public function encrypt(string $plaintext): string
    {
        return 'ciphertext:'.$plaintext;
    }
}
