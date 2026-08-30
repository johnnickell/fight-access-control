<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use RuntimeException;

/**
 * Indicates that authoritative Permission definitions do not match the requested identities exactly.
 *
 * @internal
 */
final class ExactPermissionResolutionException extends RuntimeException
{
}
