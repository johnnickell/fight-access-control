<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Event;

use DateTimeImmutable;
use Fight\Common\Domain\Exception\DomainException;

/**
 * Class AgentEventTime
 *
 * Validates explicit serialized instants without relative-time interpretation or calendar normalization.
 */
final class AgentEventTime
{
    /**
     * Creates an Agent event timestamp from an explicit RFC3339 instant
     */
    public static function fromString(string $value): DateTimeImmutable
    {
        $pattern = '/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d';
        $pattern .= '(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/';
        if (!preg_match($pattern, $value)) {
            throw new DomainException('An Agent event timestamp must be an explicit RFC3339 instant.');
        }

        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $instant = DateTimeImmutable::createFromFormat($format, $value);
        if ($instant === false || DateTimeImmutable::getLastErrors() !== false) {
            throw new DomainException('An Agent event timestamp must be a valid calendar instant.');
        }

        return $instant;
    }
}
