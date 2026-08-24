<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that managed policy definitions cannot be reconciled safely.
 */
final class ManagedPolicyDefinitionException extends DomainException
{
}
