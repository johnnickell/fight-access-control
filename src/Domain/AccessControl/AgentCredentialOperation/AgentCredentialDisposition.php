<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation;

/**
 * Enum AgentCredentialDisposition
 *
 * Describes the originally issued credential, never a successor credential or permission to use it.
 */
enum AgentCredentialDisposition: string
{
    case CURRENT = 'current';
    case SUPERSEDED = 'superseded';
    case REVOKED = 'revoked';
}
