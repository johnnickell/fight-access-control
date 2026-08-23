<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that activation authority cannot satisfy a requested transition.
 */
final class ActivationGrantException extends DomainException
{
}
