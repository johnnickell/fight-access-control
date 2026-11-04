<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\Common\Domain\Value\ValueObject;

/**
 * Class AgentName
 *
 * Represents a normalized operator-facing Agent name.
 */
final readonly class AgentName extends ValueObject
{
    private const int MAXIMUM_LENGTH = 120;

    /**
     * Constructs AgentName
     *
     * Constructs a validated Agent name.
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates an Agent name from its operator-supplied representation
     *
     * Trims the fixed Unicode White_Space code points plus NUL at the edges, without folding case, internal
     * whitespace or Unicode normalization forms.
     */
    public static function fromString(string $value): self
    {
        $edges = '\x{0000}\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}';
        $edges .= '\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';
        $normalizedValue = preg_replace('/\A['.$edges.']+|['.$edges.']+\z/u', '', $value) ?? '';

        if (
            $normalizedValue === '' || !mb_check_encoding($normalizedValue, 'UTF-8')
            || mb_strlen($normalizedValue, 'UTF-8') > self::MAXIMUM_LENGTH
        ) {
            throw new AgentNameException('An Agent name must be non-empty and at most 120 characters long.');
        }

        return new self($normalizedValue);
    }

    /**
     * Returns the normalized operator-facing name
     */
    public function toString(): string
    {
        return $this->value;
    }
}
