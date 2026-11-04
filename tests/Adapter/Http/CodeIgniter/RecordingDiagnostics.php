<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Throwable;

final class RecordingDiagnostics implements SafeHttpDiagnostics
{
    public int $calls = 0;

    public function report(Throwable $failure): void
    {
        ++$this->calls;
    }
}
