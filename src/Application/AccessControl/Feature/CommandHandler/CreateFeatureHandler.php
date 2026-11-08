<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;
use UnexpectedValueException;

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
            /** @var FeatureCreated $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): FeatureCreated {
                $permission = $this->permissionRepository->getById($command->getPermissionId());
                if ($permission === null) {
                    throw new ResourceNotFound('The selected testing Permission does not exist.');
                }

                if (!$permission->getId()->equals($command->getPermissionId())) {
                    throw new UnexpectedValueException('Permission lookup returned a different identity.');
                }

                $feature = Feature::define(FeatureId::generate(), $command->getName(), $command->getPermissionId());
                try {
                    $this->featureRepository->add($feature);
                } catch (FeatureConflictException | FeatureReferenceException $exception) {
                    throw new StateConflict('Feature creation conflicts with current state.', previous: $exception);
                }

                return new FeatureCreated($feature->getId(), $feature->getName(), $feature->getPermissionId());
            });
            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Feature creation failed.'));
            } finally {
                throw $throwable;
            }
        }
    }
}
