<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use Closure;
use Doctrine\DBAL\Connection;

final class InterleavedSubscriberConnection extends Connection
{
    public ?Closure $afterBatch = null;

    /**
     * @inheritDoc
     */
    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $rows = parent::fetchAllAssociative($query, $params, $types);
        $afterBatch = $this->afterBatch;
        $this->afterBatch = null;
        if ($afterBatch !== null) {
            $afterBatch();
        }

        return $rows;
    }
}
