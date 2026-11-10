<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use Doctrine\DBAL\Connection;
use RuntimeException;

final class UncertainNoticeConnection extends Connection
{
    public ?string $failure = null;

    public function commit(): void
    {
        if ($this->failure === 'before') {
            throw new RuntimeException('Private lost commit acknowledgement.');
        }

        parent::commit();
        if ($this->failure === 'after') {
            throw new RuntimeException('Private lost commit acknowledgement.');
        }
    }
}
