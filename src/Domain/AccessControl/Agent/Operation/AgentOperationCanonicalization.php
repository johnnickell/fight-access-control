<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentOperationCanonicalization
 *
 * Owns the single supported operation request representation and Unicode-aware name rules.
 */
final class AgentOperationCanonicalization
{
    public const int VERSION = 2;

    /**
     * Validates the persisted contract marker without migration or fallback
     */
    public static function assertSupported(int $version): void
    {
        if ($version !== self::VERSION) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNSUPPORTED_VERSION);
        }
    }

    /**
     * Returns the name under the fixed Unicode edge-whitespace and length rules
     *
     * Trims the fixed Unicode White_Space code points plus NUL at the edges, without folding case, internal
     * whitespace or Unicode normalization forms.
     */
    public static function name(string $name): string
    {
        $edges = '\x{0000}\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}';
        $edges .= '\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';
        $name = preg_replace('/\A['.$edges.']+|['.$edges.']+\z/u', '', $name) ?? '';

        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 120) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }

        return $name;
    }
}
