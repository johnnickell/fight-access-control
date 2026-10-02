<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Command;

use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class CreateFeature
 *
 * Requests manual OFF creation with an existing testing Permission.
 */
final readonly class CreateFeature implements Command
{
    /**
     * Constructs CreateFeature
     */
    public function __construct(private FeatureName $name, private PermissionId $permissionId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['name', 'permission_id'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid required string "%s" in data array', $key));
            }
        }

        return new self(FeatureName::fromString($data['name']), PermissionId::fromString($data['permission_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['name' => $this->name->toString(), 'permission_id' => $this->permissionId->toString()];
    }

    /**
     * Returns the new Feature name
     */
    public function getName(): FeatureName
    {
        return $this->name;
    }

    /**
     * Returns the chosen testing Permission identity
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }
}
