<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

/**
 * Enum ActivationOrigin
 *
 * Identifies the immutable activation journey, not dispatch authority or credential purpose.
 */
enum ActivationOrigin: string
{
    case PRIVATE_INVITATION = 'private_invitation';
    case REGISTRATION = 'registration';
}
