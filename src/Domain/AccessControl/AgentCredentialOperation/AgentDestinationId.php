<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Class AgentDestinationId
 *
 * Identifies a registered protected slot independently of its ownership revision.
 */
final readonly class AgentDestinationId extends UniqueId
{
}
