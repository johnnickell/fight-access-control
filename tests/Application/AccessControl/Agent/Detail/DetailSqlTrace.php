<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Detail;

use Psr\Log\AbstractLogger;
use Stringable;

final class DetailSqlTrace extends AbstractLogger
{
    /** @var list<string> */
    public array $queries = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->queries[] = $context['sql'];
        }
    }

    public function bodyReads(): int
    {
        return count(array_filter(
            $this->queries,
            static fn(string $sql): bool => str_starts_with($sql, 'SELECT payload FROM')
        ));
    }
}
