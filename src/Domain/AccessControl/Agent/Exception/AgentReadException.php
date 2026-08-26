<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports an unsafe or incomplete Agent administrative read.
 */
final class AgentReadException extends DomainException
{
}
