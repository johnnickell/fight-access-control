<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

/**
 * Encrypts password-reset credentials with consumer-owned keys.
 */
interface PasswordResetDeliveryCipher
{
    /**
     * Encrypts a raw credential for bounded delivery work.
     */
    public function encrypt(string $plaintext): string;
}
