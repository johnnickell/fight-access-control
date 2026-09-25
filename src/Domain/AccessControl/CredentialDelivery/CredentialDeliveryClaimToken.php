<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Class CredentialDeliveryClaimToken
 *
 * Identifies one opaque credential-delivery lease claim.
 */
final readonly class CredentialDeliveryClaimToken extends UniqueId
{
}
