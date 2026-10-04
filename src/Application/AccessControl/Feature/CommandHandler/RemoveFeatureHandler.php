<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Feature\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Feature\Service\FeatureReferenceDiscovery;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureRemoved;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
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
 * Class RemoveFeatureHandler
 *
 * Retires only an unreferenced Feature at its current identity and revision.
 */
final readonly class RemoveFeatureHandler implements CommandHandler
{
    /**
     * Constructs RemoveFeatureHandler
     */
    public function __construct(
        private FeatureReferenceDiscovery $discovery,
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
        return RemoveFeature::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var RemoveFeature $command */
        $command = $commandMessage->payload();
        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): FeatureRemoved {
                $feature = $this->features->getById($command->getFeatureId());
                if (!$feature instanceof Feature) {
                    throw new FeatureNotFoundException('The Feature does not exist.');
                }

                if ($feature->getRevision() !== $command->getExpectedRevision()) {
                    throw new FeatureRevisionException('The Feature changed concurrently.');
                }

                $references = $this->discovery->discover(FeatureReferenceScope::CURRENT)
                    ->getReferences(FeatureReferenceScope::CURRENT);
                foreach ($references->getNames() as $name) {
                    if ($name->equals($feature->getName())) {
                        throw new FeatureReferenceException('The Feature is still referenced by current code.');
                    }
                }

                if (!$this->features->remove($feature)) {
                    throw new FeatureRevisionException('The Feature changed concurrently.');
                }

                return new FeatureRemoved($feature->getId(), $feature->getName());
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
