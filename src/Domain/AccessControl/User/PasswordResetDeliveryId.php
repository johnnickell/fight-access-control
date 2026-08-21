<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Identifies one immutable generation of password-reset delivery work.
 */
final readonly class PasswordResetDeliveryId extends UniqueId
{
}
