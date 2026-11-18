<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceScope;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureRemoved;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;
use UnexpectedValueException;

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
        private FeatureRepository $featureRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
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
        $messageId = $commandMessage->id();
        try {
            /** @var FeatureRemoved $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $messageId): FeatureRemoved {
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

                $references = $this->discovery->discover(FeatureReferenceScope::CURRENT)
                    ->getReferences(FeatureReferenceScope::CURRENT);
                foreach ($references->getNames() as $name) {
                    if ($name->equals($feature->getName())) {
                        throw new RuleViolation('The Feature is still referenced by current code.');
                    }
                }

                $actorId = $command->getActorId();
                if ($this->auditEvidenceRepository !== null && $actorId === null) {
                    throw new InvalidInput('Enabled Feature audit requires originating actor provenance.');
                }

                if (!$this->featureRepository->remove($feature)) {
                    throw new StateConflict('The Feature changed concurrently.');
                }

                if ($this->auditEvidenceRepository !== null) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $actorId,
                        new AuditSubject($feature->getId()),
                        AuditAction::FEATURE_REMOVED,
                        $this->clock->now(),
                        new AuditFeatureOutcome(AuditFeatureState::fromFeature($feature), null),
                        new AuditCorrelation($messageId, null)
                    ));
                }

                return new FeatureRemoved($feature->getId(), $feature->getName());
            });
            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Feature removal failed.'));
            } finally {
                throw $throwable;
            }
        }
    }
}
