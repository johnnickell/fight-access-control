<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class CreateFeatureHandler
 *
 * Creates a manually selected OFF Feature without granting authority.
 */
final readonly class CreateFeatureHandler implements CommandHandler
{
    /**
     * Constructs CreateFeatureHandler
     */
    public function __construct(
        private FeatureRepository $features,
        private PermissionRepository $permissions,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $events
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return CreateFeature::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var CreateFeature $command */
        $command = $commandMessage->payload();
        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): FeatureCreated {
                if (!$this->permissions->getById($command->getPermissionId()) instanceof Permission) {
                    throw new FeatureReferenceException('The selected testing Permission does not exist.');
                }

                $feature = Feature::define(FeatureId::generate(), $command->getName(), $command->getPermissionId());
                $this->features->add($feature);

                return new FeatureCreated($feature->getId(), $feature->getName(), $feature->getPermissionId());
            });
            $this->events->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->events->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            } finally {
                throw $throwable;
            }
        }
    }
}
