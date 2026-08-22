<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Identifies one generation of password-reset authority.
 */
final readonly class PasswordResetGrantId extends UniqueId
{
}
