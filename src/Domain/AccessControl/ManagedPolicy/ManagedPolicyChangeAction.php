<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy;

/**
 * Describes the mutation required to reach one managed definition.
 */
enum ManagedPolicyChangeAction: string
{
    case CREATE = 'CREATE';
    case RECONCILE = 'RECONCILE';
    case REMOVE = 'REMOVE';
    case UNCHANGED = 'UNCHANGED';
}
