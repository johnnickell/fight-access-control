<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\Identity;

/**
 * Represents the lifecycle state of a user identity.
 */
enum UserState: string
{
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Disabled = 'disabled';
    case Deleted = 'deleted';
}
