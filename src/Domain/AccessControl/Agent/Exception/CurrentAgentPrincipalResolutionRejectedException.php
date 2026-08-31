<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnostic;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates one generic caller-facing Agent-principal resolution denial.
 */
final class CurrentAgentPrincipalResolutionRejectedException extends DomainException
{
    /**
     * Creates the generic denial while retaining only its safe server diagnostic.
     */
    public function __construct(private readonly AgentAuthenticationDiagnostic $diagnostic)
    {
        parent::__construct('Agent authentication rejected.');
    }

    /**
     * Returns the secret-free server-observable resolution diagnostic.
     */
    public function getDiagnostic(): AgentAuthenticationDiagnostic
    {
        return $this->diagnostic;
    }
}
