<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryCipher;

/**
 * Interface PasswordResetDeliveryCipher
 *
 * Encrypts password-reset credentials with consumer-owned keys.
 */
interface PasswordResetDeliveryCipher extends CredentialDeliveryCipher
{
}
