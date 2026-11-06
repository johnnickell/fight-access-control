<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query;

use Fight\Common\Domain\Type\Arrayable;

/**
 * Class CredentialDeliveryAbsent
 *
 * Represents an absent optional status read, not rollback or permission to issue work.
 */
final readonly class CredentialDeliveryAbsent implements Arrayable
{
    /**
     * Returns the explicit absence representation
     *
     * @phpstan-return array{state: 'absent'}
     */
    public function toArray(): array
    {
        return ['state' => 'absent'];
    }
}
