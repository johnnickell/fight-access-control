<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Identifies one generation of email-change authority.
 */
final readonly class EmailChangeGrantId extends UniqueId
{
}
