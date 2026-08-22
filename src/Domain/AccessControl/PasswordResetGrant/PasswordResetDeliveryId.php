<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Identifies one password-reset delivery generation.
 */
final readonly class PasswordResetDeliveryId extends UniqueId
{
}
