<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Command;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Requests revocation of the authenticated caller's current refresh session.
 */
final readonly class LogoutCurrentSession implements Command
{
    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if ($data !== []) {
            throw new DomainException('Logout current session does not accept command data');
        }

        return new static();
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [];
    }
}
