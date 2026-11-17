<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use LogicException;

/** Selected code metadata only; no native controller lifecycle is exercised */
final class NativeController
{
    public function __construct()
    {
        throw new LogicException('Metadata selection must not construct a controller.');
    }

    public function handle(): never
    {
        throw new LogicException('Admission must not execute the selected action.');
    }
}
