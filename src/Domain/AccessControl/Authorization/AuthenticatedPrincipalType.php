<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization;

/**
 * Enum AuthenticatedPrincipalType
 *
 * Classifies the package-owned authenticated authority selected for a request.
 */
enum AuthenticatedPrincipalType: string
{
    case USER = 'user';
    case AGENT = 'agent';
}
