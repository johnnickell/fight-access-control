<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Represents a stable user identifier.
 */
final readonly class UserId extends UniqueId
{
}
