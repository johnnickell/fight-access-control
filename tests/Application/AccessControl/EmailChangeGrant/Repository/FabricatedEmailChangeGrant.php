<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;

final class FabricatedEmailChangeGrant extends EmailChangeGrant
{
    public static function fromGrant(EmailChangeGrant $grant): self
    {
        return new self(
            $grant->getId(),
            $grant->getUserId(),
            str_repeat('f', 64),
            $grant->getExpiresAt(),
            $grant->getDelivery(),
            $grant->getConsumedAt(),
            $grant->getRevokedAt(),
            $grant->getExpiredAt(),
            $grant->getRevision()
        );
    }
}
