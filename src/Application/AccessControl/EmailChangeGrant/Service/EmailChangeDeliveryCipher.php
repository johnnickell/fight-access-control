<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

/**
 * Encrypts email-change credentials with consumer-owned keys.
 */
interface EmailChangeDeliveryCipher
{
    /**
     * Encrypts raw confirmation material for bounded delivery work.
     */
    public function encrypt(string $plaintext): string;
}
