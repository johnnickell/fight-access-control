<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureProvisioningException
 *
 * Rejects creation when required default Permission configuration is absent or unresolved.
 */
final class FeatureProvisioningException extends DomainException
{
}
