<?php

declare(strict_types=1);

namespace Fight\Common\Application\Http\JSend;

use Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber\EncodingFault;
use JsonException;

/**
 * Injects one encoding fault only in the isolated native-renderer test process
 */
function json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    ++EncodingFault::$calls;
    if (EncodingFault::$remaining > 0) {
        --EncodingFault::$remaining;
        throw new JsonException('PRIVATE ENCODER');
    }

    return \json_encode($value, $flags, $depth);
}
