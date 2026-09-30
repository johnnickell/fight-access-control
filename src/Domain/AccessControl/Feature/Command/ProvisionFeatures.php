<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ProvisionFeatures
 *
 * Requests creation of missing candidate-code Features, not activation or runtime availability.
 */
final readonly class ProvisionFeatures implements Command
{
    /**
     * Constructs ProvisionFeatures
     *
     * Keep raw configuration until creation is needed; unused invalid configuration must not reject a no-op pass.
     */
    public function __construct(private ?string $defaultPermissionName)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('default_permission_name', $data)) {
            throw new DomainException('Missing required key "default_permission_name" in data array');
        }

        $name = $data['default_permission_name'];
        if ($name !== null && !is_string($name)) {
            throw new DomainException('The default Permission configuration must be a string or null.');
        }

        return new self($name);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['default_permission_name' => $this->defaultPermissionName];
    }

    /**
     * Returns the consumer-supplied configuration without reading the process environment
     */
    public function getDefaultPermissionName(): ?string
    {
        return $this->defaultPermissionName;
    }
}
