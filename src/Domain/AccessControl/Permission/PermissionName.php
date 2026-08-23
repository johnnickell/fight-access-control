<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

use Fight\AccessControl\Domain\AccessControl\Permission\Exception\PermissionNameException;
use Fight\Common\Domain\Value\ValueObject;

/**
 * Represents a canonical uppercase permission name.
 */
final readonly class PermissionName extends ValueObject
{
    /**
     * Constructs a validated permission name.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a permission name from its serialized representation.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Z]+(?:_[A-Z]+)*$/', $value) !== 1) {
            throw new PermissionNameException(
                'A permission name must contain uppercase words separated by underscores.'
            );
        }

        return new self($value);
    }

    /**
     * Returns the serialized permission name.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
