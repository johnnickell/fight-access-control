<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleNameException;
use Fight\Common\Domain\Value\ValueObject;

/**
 * Represents a canonical role name.
 */
final readonly class RoleName extends ValueObject
{
    /**
     * Constructs a validated role name.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a role name from its serialized representation.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^ROLE_[A-Z_]+$/', $value) !== 1) {
            throw new RoleNameException(
                'A role name must start with ROLE_ and contain only uppercase letters and underscores.'
            );
        }

        return new self($value);
    }

    /**
     * Returns the serialized role name.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
