<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

final class IsolatedController
{
    public function handle(): void
    {
    }

    public function __invoke(): void
    {
    }
}
