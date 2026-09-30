<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureReferenceException
 *
 * Rejects creation when its Permission reference cannot remain valid through commit.
 */
final class FeatureReferenceException extends DomainException
{
}
