<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;
use Throwable;

final class NativeTrace
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<SecurityContext|null> */
    public array $contexts = [];

    /** @var list<class-string<Throwable>> */
    public array $reports = [];

    public bool $sinkFails = false;

    public bool $authenticationPresent = false;

    public int $deliveries = 0;

    public ?Throwable $failure = null;

    /** @return list<string> */
    public function snapshot(): array
    {
        return $this->events;
    }
}
