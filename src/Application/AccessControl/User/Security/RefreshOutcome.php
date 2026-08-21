<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Security;

/**
 * Identifies whether refresh rotated authority or observed a bounded conflict.
 */
enum RefreshOutcome: string
{
    case CONFLICT = 'conflict';

    case ROTATED = 'rotated';
}
