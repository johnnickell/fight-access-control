<?php

declare(strict_types=1);

namespace Fight\AccessControl\Adapter\Http\CodeIgniter;

use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * Class SafeNativeLogger
 *
 * Explicit PSR ingress replacing native formatting/cache/handlers, not a second sink.
 */
final class SafeNativeLogger extends AbstractLogger
{
    private bool $failed = false;

    private bool $reporting = false;

    /**
     * Constructs SafeNativeLogger
     */
    public function __construct(private readonly SafeHttpDiagnostics $diagnostics)
    {
    }

    /**
     * Reports native records without interpolation or arbitrary object conversion
     *
     * Only bounded string context and an actual original Throwable are retained. Nothing is forwarded
     * as PSR exception context. The same trusted owner as SafeErrorResponder redacts it.
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->reporting) {
            $this->failed = true;

            return;
        }

        $this->reporting = true;
        try {
            $failure = $context['exception'] ?? null;
            if (!$failure instanceof Throwable) {
                $strings = [];
                foreach (array_slice($context, 0, 16, true) as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $strings[substr($key, 0, 128)] = substr($value, 0, 4096);
                    }
                }

                $failure = new NativeDiagnostic(is_string($message) ? substr($message, 0, 4096) : null, $strings);
            }

            $this->diagnostics->report($failure);
        } catch (Throwable) {
            $this->failed = true;
        } finally {
            $this->reporting = false;
        }
    }

    /**
     * Returns whether reporting failed during the selected request
     */
    public function failed(): bool
    {
        return $this->failed;
    }

    /**
     * Clears request-local failure state without retaining native records
     */
    public function reset(): void
    {
        $this->failed = false;
    }
}
