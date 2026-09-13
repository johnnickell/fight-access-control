<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Class ActivationDeliveryId
 *
 * Identifies one activation-delivery generation.
 */
final readonly class ActivationDeliveryId extends UniqueId
{
}
