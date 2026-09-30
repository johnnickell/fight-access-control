<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Repository\InMemoryFeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\Feature\Service\FixtureFeatureDiscovery;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use RuntimeException;

/**
 * Controlled repository binding, not a consumer database or production scanner.
 */
final class ControlledPreparationEnvironment implements FeaturePreparationEnvironment
{
    private readonly InMemoryUnitOfWork $unitOfWork;

    private readonly InMemoryFeatureRepository $features;

    private readonly InMemoryPermissionRepository $permissions;

    private FeatureReferenceDiscovery $discovery;

    private InMemoryEventDispatcher $events;

    public function __construct()
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->features = new InMemoryFeatureRepository($this->unitOfWork);
        $this->permissions = new InMemoryPermissionRepository($this->unitOfWork);
        $this->events = new InMemoryEventDispatcher();
        foreach (['DEFAULT', 'OTHER'] as $name) {
            $this->permissions->add(Permission::define(
                PermissionId::generate(),
                PermissionName::fromString($name),
                new DateTimeImmutable()
            ));
        }

        $this->references([]);
    }

    public function references(array $registrations, bool $complete = true): void
    {
        $code = new class {
        };
        if (in_array('dashboard', $registrations, true)) {
            $code = new class {
                #[FeatureFlag('dashboard')]
                public function show(): void
                {
                }
            };
        }

        $this->discovery = new FixtureFeatureDiscovery($code, $registrations, static fn(): bool => $complete);
    }

    public function provision(?string $default): void
    {
        new ProvisionFeaturesHandler(
            $this->discovery,
            $this->features,
            $this->permissions,
            $this->unitOfWork,
            $this->events
        )->handle(CommandMessage::create(new ProvisionFeatures($default)));
    }

    public function validate(): FeaturePreparationResult
    {
        return new ValidateFeaturePreparationHandler($this->discovery, $this->features, $this->permissions)
            ->handle(QueryMessage::create(new ValidateFeaturePreparation()));
    }

    public function definition(string $name): ?Feature
    {
        return $this->features->getByName(FeatureName::fromString($name));
    }

    public function stored(string $name, FeatureStatus $status, string $permission): Feature
    {
        $found = $this->permissions->getByName(PermissionName::fromString($permission));
        $id = $found?->getId() ?? PermissionId::generate();
        $feature = Feature::reconstitute(FeatureId::generate(), FeatureName::fromString($name), $id, $status, 4);
        $this->features->seed($feature);

        return $feature;
    }

    public function failNextInsertion(string $name): void
    {
        $this->features->beforeAdd = static function (Feature $feature) use ($name): void {
            if ($feature->getName()->toString() === $name) {
                throw new RuntimeException('Controlled insertion failure.');
            }
        };
    }

    public function seedWinnerOnInsertion(string $name, FeatureStatus $status, string $permission): Feature
    {
        $winner = Feature::reconstitute(
            FeatureId::generate(),
            FeatureName::fromString($name),
            $this->permissions->getByName(PermissionName::fromString($permission))->getId(),
            $status,
            5
        );
        $this->features->beforeAdd = function (Feature $feature) use ($winner): void {
            if ($feature->getName()->equals($winner->getName())) {
                $this->features->seed($winner);
            }
        };

        return $winner;
    }

    public function clearInsertionHook(): void
    {
        $this->features->beforeAdd = null;
    }

    public function failNextCreationPublication(): void
    {
        $failed = false;
        $this->events = new InMemoryEventDispatcher(static function (Event $event) use (&$failed): void {
            if (!$failed && $event instanceof FeatureCreated) {
                $failed = true;
                throw new RuntimeException('Controlled post-commit publication failure.');
            }
        });
    }

    public function writes(): int
    {
        return $this->features->writes;
    }
}
