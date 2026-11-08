<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception;

use Fight\Common\Application\Error\Exception\StateConflict;

/**
 * Class AgentRotationConflict
 *
 * Identifies a known predecessor refusal eligible for one resolution-only retry.
 */
final class AgentRotationConflict extends StateConflict
{
    /**
     * Constructs AgentRotationConflict
     */
    public function __construct()
    {
        parent::__construct('The expected Agent credential is no longer authoritative.');
    }
}
