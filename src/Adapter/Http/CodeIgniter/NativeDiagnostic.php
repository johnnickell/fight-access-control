<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use RuntimeException;

/**
 * Class NativeDiagnostic
 *
 * A native record without an original Throwable, not a recovered routing exception.
 * The bounded, uninterpolated message remains private input to the redaction owner.
 */
final class NativeDiagnostic extends RuntimeException
{
    /**
     * Constructs NativeDiagnostic
     *
     * @phpstan-param array<string, string> $nativeContext
     */
    public function __construct(
        public readonly ?string $nativeMessage,
        public readonly array $nativeContext = []
    ) {
        parent::__construct('CodeIgniter native diagnostic.');
    }
}
