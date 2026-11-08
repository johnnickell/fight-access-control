<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeaturePermissionChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
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
 * Class SetFeaturePermissionHandler
 *
 * Rebinds a Feature under the same reference fence as Permission removal.
 */
final readonly class SetFeaturePermissionHandler implements CommandHandler
{
    /**
     * Constructs SetFeaturePermissionHandler
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
        return SetFeaturePermission::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var SetFeaturePermission $command */
        $command = $commandMessage->payload();
        try {
            /** @var FeaturePermissionChanged|null $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?FeaturePermissionChanged {
                $feature = $this->featureRepository->getById($command->getFeatureId());
                if ($feature === null) {
                    throw new ResourceNotFound('The Feature does not exist.');
                }

                if (!$feature->getId()->equals($command->getFeatureId())) {
                    throw new UnexpectedValueException('Feature lookup returned a different identity.');
                }

                if ($feature->getRevision() !== $command->getExpectedRevision()) {
                    throw new PreconditionFailed('The expected Feature revision does not match.');
                }

                $permission = $this->permissionRepository->getById($command->getPermissionId());
                if ($permission === null) {
                    throw new ResourceNotFound('The selected testing Permission does not exist.');
                }

                if (!$permission->getId()->equals($command->getPermissionId())) {
                    throw new UnexpectedValueException('Permission lookup returned a different identity.');
                }

                $successor = $feature->withPermission($command->getPermissionId());
                try {
                    if (!$this->featureRepository->replace($feature, $successor)) {
                        throw new StateConflict('The Feature changed concurrently.');
                    }
                } catch (FeatureReferenceException $featureReferenceException) {
                    throw new StateConflict(
                        'The Feature Permission changed concurrently.',
                        previous: $featureReferenceException
                    );
                }

                if ($successor === $feature) {
                    return null;
                }

                return new FeaturePermissionChanged(
                    $feature->getId(),
                    $successor->getPermissionId(),
                    $successor->getRevision()
                );
            });
            if ($event !== null) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Feature permission update failed.'));
            } finally {
                throw $throwable;
            }
        }
    }
}
