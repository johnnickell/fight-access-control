<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureBindingException
 *
 * Signals a missing stored testing Permission without replacing it or granting availability.
 */
final class FeatureBindingException extends DomainException
{
}
