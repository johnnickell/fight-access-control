<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryCipher;

/**
 * Interface InvitationDeliveryCipher
 *
 * Encrypts recoverable delivery content with consumer-owned keys.
 */
interface InvitationDeliveryCipher extends CredentialDeliveryCipher
{
}
