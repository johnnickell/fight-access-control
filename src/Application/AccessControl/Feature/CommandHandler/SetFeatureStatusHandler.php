<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditFeatureState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\SetFeatureStatus;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureStatusChanged;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\Common\Application\Error\Exception\InvalidInput;
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
            $messageId = $commandMessage->id();
            /** @var FeatureStatusChanged|null $event */
            $event = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $messageId
            ): ?FeatureStatusChanged {
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
                $actorId = $command->getActorId();
                if ($successor !== $feature && $this->auditEvidenceRepository !== null && $actorId === null) {
                    throw new InvalidInput('Feature audit requires explicit actor provenance.');
                }

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

                if ($this->auditEvidenceRepository !== null && $actorId !== null) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $actorId,
                        new AuditSubject($feature->getId()),
                        AuditAction::FEATURE_STATUS_CHANGED,
                        $this->clock->now(),
                        new AuditFeatureOutcome(
                            AuditFeatureState::fromFeature($feature),
                            AuditFeatureState::fromFeature($successor)
                        ),
                        new AuditCorrelation($messageId, null)
                    ));
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
