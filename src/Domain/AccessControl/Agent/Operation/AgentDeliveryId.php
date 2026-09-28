<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Class AgentDeliveryId
 *
 * Identifies one issuance globally across scopes and delivery attempts.
 */
final readonly class AgentDeliveryId extends UniqueId
{
}
