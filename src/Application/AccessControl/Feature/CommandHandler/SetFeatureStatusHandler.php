<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureStatusChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureRevisionException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

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
        private FeatureRepository $features,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $events
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
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ?FeatureStatusChanged {
                $feature = $this->features->getById($command->getFeatureId());
                if (!$feature instanceof Feature) {
                    throw new FeatureNotFoundException('The Feature does not exist.');
                }

                if ($feature->getRevision() !== $command->getExpectedRevision()) {
                    throw new FeatureRevisionException('The Feature changed concurrently.');
                }

                $successor = $feature->withStatus($command->getStatus());
                if (!$this->features->replace($feature, $successor)) {
                    throw new FeatureRevisionException('The Feature changed concurrently.');
                }

                if ($successor === $feature) {
                    return null;
                }

                return new FeatureStatusChanged($feature->getId(), $successor->getStatus(), $successor->getRevision());
            });
            if ($event instanceof FeatureStatusChanged) {
                $this->events->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->events->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            } finally {
                throw $throwable;
            }
        }
    }
}
