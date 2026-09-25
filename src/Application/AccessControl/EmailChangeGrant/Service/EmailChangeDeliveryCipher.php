<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryCipher;

/**
 * Interface EmailChangeDeliveryCipher
 *
 * Encrypts email-change credentials with consumer-owned keys.
 */
interface EmailChangeDeliveryCipher extends CredentialDeliveryCipher
{
}
