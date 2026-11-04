<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeaturePermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeaturePermissionChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
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
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?FeaturePermissionChanged {
                $feature = $this->featureRepository->getById($command->getFeatureId());
                if (!$feature instanceof Feature) {
                    throw new FeatureNotFoundException('The Feature does not exist.');
                }

                if ($feature->getRevision() !== $command->getExpectedRevision()) {
                    throw new FeatureRevisionException('The Feature changed concurrently.');
                }

                if (!$this->permissionRepository->getById($command->getPermissionId()) instanceof Permission) {
                    throw new FeatureReferenceException('The selected testing Permission does not exist.');
                }

                $successor = $feature->withPermission($command->getPermissionId());
                if (!$this->featureRepository->replace($feature, $successor)) {
                    throw new FeatureRevisionException('The Feature changed concurrently.');
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
            if ($event instanceof FeaturePermissionChanged) {
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
