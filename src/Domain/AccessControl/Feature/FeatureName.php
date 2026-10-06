<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNameException;
use Fight\Common\Domain\Value\ValueObject;

/**
 * Class FeatureName
 *
 * Identifies a Feature without normalizing developer-supplied names.
 */
final readonly class FeatureName extends ValueObject
{
    /**
     * Constructs FeatureName
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Creates a validated Feature name
     */
    public static function fromString(string $value): self
    {
        if (strlen($value) > 128 || preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $value) !== 1) {
            throw new FeatureNameException(
                'Feature names must start with a letter and use 1–128 lowercase ASCII letters/digits or single hyphens.'
            );
        }

        return new self($value);
    }

    /**
     * Returns the unchanged Feature name
     */
    public function toString(): string
    {
        return $this->value;
    }
}
