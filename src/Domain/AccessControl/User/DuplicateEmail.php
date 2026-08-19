<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that a canonical email remains reserved by an identity.
 */
final class DuplicateEmail extends DomainException
{
}
