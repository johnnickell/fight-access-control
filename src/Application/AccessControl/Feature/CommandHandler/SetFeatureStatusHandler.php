<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureStatusChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
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
 * Class SetFeatureStatusHandler
 *
 * Persists an optimistic availability transition with post-commit publication.
 */
final readonly class SetFeatureStatusHandler implements CommandHandler
{
    /**
     * Constructs SetFeatureStatusHandler
     */
    public function __construct(
        private FeatureRepository $featureRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return SetFeatureStatus::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var SetFeatureStatus $command */
        $command = $commandMessage->payload();
        try {
            /** @var FeatureStatusChanged|null $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?FeatureStatusChanged {
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

                $successor = $feature->withStatus($command->getStatus());
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

                return new FeatureStatusChanged($feature->getId(), $successor->getStatus(), $successor->getRevision());
            });
            if ($event !== null) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Feature status update failed.'));
            } finally {
                throw $throwable;
            }
        }
    }
}
