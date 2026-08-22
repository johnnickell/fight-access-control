<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Identifies one generation of activation authority.
 */
final readonly class ActivationGrantId extends UniqueId
{
}
