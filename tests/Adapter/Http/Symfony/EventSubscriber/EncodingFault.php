<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

final class EncodingFault
{
    public static int $remaining = 0;

    public static int $calls = 0;
}
