<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception;

use Fight\Common\Application\Error\Exception\ServerError;

/**
 * Class AgentIssuanceFailed
 *
 * Contains internal issuance faults without retaining provider diagnostics, causes or material.
 */
final class AgentIssuanceFailed extends ServerError
{
    /**
     * Constructs AgentIssuanceFailed
     */
    public function __construct()
    {
        parent::__construct('Agent issuance failed.');
    }
}
