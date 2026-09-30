<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Query;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class ValidateFeaturePreparation
 *
 * Requests a fresh check of the complete candidate inventory against authoritative definitions.
 */
final readonly class ValidateFeaturePreparation implements Query
{
    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if ($data !== []) {
            throw new DomainException('Feature preparation query does not accept payload fields.');
        }

        return new self();
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [];
    }
}
