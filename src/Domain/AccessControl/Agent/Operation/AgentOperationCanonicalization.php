<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;

/**
 * Class AgentOperationCanonicalization
 *
 * Owns immutable historical request semantics independently of the current Agent display-name policy.
 */
final class AgentOperationCanonicalization
{
    public const array VERSIONS = [1, 2];

    /**
     * Validates that the package retains the recorded reader without falling back to another version
     */
    public static function assertSupported(int $version): void
    {
        if (!in_array($version, self::VERSIONS, true)) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNSUPPORTED_VERSION);
        }
    }

    /**
     * Returns the name under frozen version-specific normalization and length rules
     *
     * Version one trims only PHP's original six ASCII edge bytes. Version two additionally trims the fixed
     * Unicode White_Space code points at the edges; neither version folds internal whitespace or letter case.
     */
    public static function name(string $name, int $version): string
    {
        self::assertSupported($version);
        if ($version === 2) {
            $edges = '\x{0000}\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}';
            $edges .= '\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';
            $name = preg_replace('/\A['.$edges.']+|['.$edges.']+\z/u', '', $name) ?? '';
        } else {
            $name = trim($name, " \t\n\r\0\x0B");
        }

        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 120) {
            throw new AgentOperationRejectedException(AgentOperationFailure::INVALID_REQUEST);
        }

        return $name;
    }
}
