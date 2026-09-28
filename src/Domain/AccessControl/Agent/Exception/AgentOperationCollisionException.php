<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use RuntimeException;

/**
 * Class AgentOperationCollisionException
 *
 * Signals a scoped-key uniqueness loser requiring complete rollback before winner resolution.
 */
final class AgentOperationCollisionException extends RuntimeException
{
    /**
     * Constructs AgentOperationCollisionException
     */
    public function __construct()
    {
        parent::__construct('The scoped operation key has a concurrent winner.');
    }
}
