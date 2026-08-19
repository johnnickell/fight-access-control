<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User;

/**
 * Encrypts recoverable delivery content with consumer-owned keys.
 */
interface ActivationDeliveryCipher
{
    /**
     * Encrypts a raw credential for bounded delivery work.
     */
    public function encrypt(string $plaintext): string;
}
