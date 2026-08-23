<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Identifies one email-change delivery generation.
 */
final readonly class EmailChangeDeliveryId extends UniqueId
{
}
