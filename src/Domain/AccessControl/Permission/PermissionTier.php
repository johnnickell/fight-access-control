<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

/**
 * Classifies where a managed permission may safely be granted.
 */
enum PermissionTier: string
{
    case ADMIN_SAFE = 'ADMIN_SAFE';
    case SUPER_ADMIN_ONLY = 'SUPER_ADMIN_ONLY';
}
