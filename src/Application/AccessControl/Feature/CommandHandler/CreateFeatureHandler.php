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
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\Common\Application\Error\Exception\InvalidInput;
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
        return CreateFeature::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var CreateFeature $command */
        $command = $commandMessage->payload();
        $messageId = $commandMessage->id();
        try {
            /** @var FeatureCreated $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $messageId): FeatureCreated {
                $permission = $this->permissionRepository->getById($command->getPermissionId());
                if ($permission === null) {
                    throw new ResourceNotFound('The selected testing Permission does not exist.');
                }

                if (!$permission->getId()->equals($command->getPermissionId())) {
                    throw new UnexpectedValueException('Permission lookup returned a different identity.');
                }

                $actorId = $command->getActorId();
                if ($this->auditEvidenceRepository !== null && $actorId === null) {
                    throw new InvalidInput('Enabled Feature audit requires originating actor provenance.');
                }

                $feature = Feature::define(FeatureId::generate(), $command->getName(), $command->getPermissionId());
                try {
                    $this->featureRepository->add($feature);
                } catch (FeatureConflictException | FeatureReferenceException $exception) {
                    throw new StateConflict('Feature creation conflicts with current state.', previous: $exception);
                }

                if ($this->auditEvidenceRepository !== null) {
                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $actorId,
                        new AuditSubject($feature->getId()),
                        AuditAction::FEATURE_CREATED,
                        $this->clock->now(),
                        new AuditFeatureOutcome(null, AuditFeatureState::fromFeature($feature)),
                        new AuditCorrelation($messageId, null)
                    ));
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
