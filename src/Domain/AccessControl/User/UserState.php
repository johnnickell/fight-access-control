<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

/**
 * Represents the lifecycle state of a user identity.
 */
enum UserState: string
{
    case PENDING_ACTIVATION = 'pending_activation';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
    case DELETED = 'deleted';
}
