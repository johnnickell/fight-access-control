<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception;

use RuntimeException;

/**
 * Class AgentOperationUncertainException
 *
 * Reports lost commit acknowledgement without claiming either rollback or confirmed issuance.
 */
final class AgentOperationUncertainException extends RuntimeException
{
    /**
     * Constructs AgentOperationUncertainException
     */
    public function __construct()
    {
        parent::__construct('Agent operation commit is indeterminate; retry the original key and request.');
    }
}
