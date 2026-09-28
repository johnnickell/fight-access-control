<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

/**
 * Enum AgentOperationFailure
 *
 * Classifies rejections without retaining unsafe dependency diagnostics.
 */
enum AgentOperationFailure: string
{
    case INVALID_REQUEST = 'invalid_request';
    case UNAUTHORIZED = 'unauthorized';
    case CONFLICT = 'conflict';
    case UNSUPPORTED_VERSION = 'unsupported_version';
    case CAPACITY = 'capacity';
    case CONTENTION = 'contention';
    case UNAVAILABLE = 'unavailable';
}
