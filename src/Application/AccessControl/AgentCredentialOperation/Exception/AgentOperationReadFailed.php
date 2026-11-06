<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception;

use Fight\Common\Application\Error\Exception\ServerError;

/**
 * Class AgentOperationReadFailed
 *
 * Reports an internal read failure without retaining provider diagnostics, causes or result data.
 */
final class AgentOperationReadFailed extends ServerError
{
    /**
     * Constructs AgentOperationReadFailed
     */
    public function __construct()
    {
        parent::__construct('Agent operation read failed.');
    }
}
