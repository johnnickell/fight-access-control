<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureProvisioningException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class ProvisionFeaturesHandler
 *
 * Creates only missing candidate references in one atomic pass; completion is not activation readiness.
 */
final readonly class ProvisionFeaturesHandler implements CommandHandler
{
    /**
     * Constructs ProvisionFeaturesHandler
     */
    public function __construct(
        private FeatureReferenceDiscovery $discovery,
        private FeatureRepository $featureRepository,
        private PermissionRepository $permissionRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return ProvisionFeatures::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ProvisionFeatures $command */
        $command = $commandMessage->payload();

        try {
            $references = $this->discovery->discover(FeatureReferenceScope::CANDIDATE)
                ->getReferences(FeatureReferenceScope::CANDIDATE);
            $events = $this->unitOfWork->commitTransactional(function () use ($command, $references): array {
                $events = [];
                $permission = null;
                foreach ($references->getNames() as $name) {
                    if ($this->featureRepository->getByName($name) instanceof Feature) {
                        continue;
                    }

                    $permission ??= $this->resolveDefault($command->getDefaultPermissionName());
                    $feature = Feature::define(FeatureId::generate(), $name, $permission->getId());
                    $this->featureRepository->add($feature);
                    $events[] = new FeatureCreated($feature->getId(), $name, $feature->getPermissionId());
                }

                return $events;
            });

            foreach ($events as $event) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            } finally {
                // A failure publisher must not replace the original operation/publication failure.
                throw $throwable;
            }
        }
    }

    /**
     * Returns the required default definition without granting its authority
     */
    private function resolveDefault(?string $name): Permission
    {
        if ($name === null) {
            throw new FeatureProvisioningException('A default Permission is required to create missing Features.');
        }

        $permission = $this->permissionRepository->getByName(PermissionName::fromString($name));
        if (!$permission instanceof Permission) {
            throw new FeatureProvisioningException('The default Permission does not exist.');
        }

        return $permission;
    }
}
