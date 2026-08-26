<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\Common\Domain\Value\ValueObject;

/**
 * Represents a normalized operator-facing Agent name.
 */
final readonly class AgentName extends ValueObject
{
    private const int MAXIMUM_LENGTH = 120;

    /**
     * Constructs a validated Agent name.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates an Agent name from its operator-supplied representation.
     */
    public static function fromString(string $value): self
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '' || mb_strlen($normalizedValue) > self::MAXIMUM_LENGTH) {
            throw new AgentNameException('An Agent name must be non-empty and at most 120 characters long.');
        }

        return new self($normalizedValue);
    }

    /**
     * Returns the normalized operator-facing name.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
