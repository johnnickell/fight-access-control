<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\InvitationDeliveryCipher;

final class PrefixInvitationDeliveryCipher implements InvitationDeliveryCipher
{
    public function encrypt(string $plaintext): string
    {
        return 'ciphertext:'.$plaintext;
    }
}
