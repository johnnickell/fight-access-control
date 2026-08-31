<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeDeliveryCipher;

final class PrefixEmailChangeDeliveryCipher implements EmailChangeDeliveryCipher
{
    public function encrypt(string $plaintext): string
    {
        return 'ciphertext:'.$plaintext;
    }
}
