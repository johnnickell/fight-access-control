<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Class AgentOperationId
 *
 * Identifies a caller-retained operation within its originating scope.
 */
final readonly class AgentOperationId extends UniqueId
{
}
