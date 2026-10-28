<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Application\AccessControl\Authorization\Service\SecurityContext;

final class NativeTrace
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<SecurityContext|null> */
    public array $contexts = [];

    /** @var list<array{type: class-string}> */
    public array $reports = [];

    public bool $sinkFails = false;

    public bool $nativeGranted = true;

    public int $depth = 0;

    /** @return list<string> */
    public function snapshot(): array
    {
        return $this->events;
    }
}
