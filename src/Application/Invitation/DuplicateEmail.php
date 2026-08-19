<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\Invitation;

use DomainException;

/**
 * Indicates that a canonical email remains reserved by an identity.
 */
final class DuplicateEmail extends DomainException
{
}
