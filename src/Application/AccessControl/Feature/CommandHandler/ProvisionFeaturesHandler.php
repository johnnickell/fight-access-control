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
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\Event\FeatureCreated;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureProvisioningException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
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
 * Class ProvisionFeaturesHandler
 *
 * Creates only missing candidate references in one atomic pass; completion is not activation readiness.
 */
final readonly class ProvisionFeaturesHandler implements CommandHandler
{
    /**
     * Constructs ProvisionFeaturesHandler
     */
    public function __construct(
        private FeatureReferenceDiscovery $discovery,
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
        return ProvisionFeatures::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ProvisionFeatures $command */
        $command = $commandMessage->payload();
        $messageId = $commandMessage->id();

        try {
            $references = $this->discovery->discover(FeatureReferenceScope::CANDIDATE)
                ->getReferences(FeatureReferenceScope::CANDIDATE);
            /** @var list<FeatureCreated> $events */
            $events = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $references,
                $messageId
            ): array {
                $events = [];
                $permission = null;
                foreach ($references->getNames() as $name) {
                    $existing = $this->featureRepository->getByName($name);
                    if ($existing !== null) {
                        if (!$existing->getName()->equals($name)) {
                            throw new UnexpectedValueException('Feature lookup returned a different name.');
                        }

                        continue;
                    }

                    $permission ??= $this->resolveDefault($command->getDefaultPermissionName());
                    $feature = Feature::define(FeatureId::generate(), $name, $permission->getId());
                    $evidence = null;
                    if ($this->auditEvidenceRepository !== null) {
                        $actorId = $command->getActorId() ?? throw new RuleViolation(
                            'Audited Feature creation requires explicit originating provenance.'
                        );
                        $evidence = AuditEvidence::record(
                            $actorId,
                            new AuditSubject($feature->getId()),
                            AuditAction::FEATURE_CREATED,
                            $this->clock->now(),
                            new AuditFeatureOutcome(null, AuditFeatureState::fromFeature($feature)),
                            new AuditCorrelation($messageId, null)
                        );
                    }

                    try {
                        $this->featureRepository->add($feature);
                    } catch (FeatureConflictException | FeatureReferenceException $exception) {
                        throw new StateConflict(
                            'Feature provisioning conflicts with current state.',
                            previous: $exception
                        );
                    }

                    if ($evidence !== null) {
                        $this->auditEvidenceRepository->add($evidence);
                    }

                    $events[] = new FeatureCreated($feature->getId(), $name, $feature->getPermissionId());
                }

                return $events;
            });

            foreach ($events as $event) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Feature provisioning failed.'));
            } finally {
                // A failure publisher must not replace the original operation/publication failure.
                throw $throwable;
            }
        }
    }

    /**
     * Returns the required default definition without granting its authority
     */
    private function resolveDefault(?string $name): Permission
    {
        if ($name === null) {
            throw new FeatureProvisioningException('A default Permission is required to create missing Features.');
        }

        $permissionName = PermissionName::fromString($name);
        $permission = $this->permissionRepository->getByName($permissionName);
        if (!$permission instanceof Permission) {
            throw new FeatureProvisioningException('The default Permission does not exist.');
        }

        if (!$permission->getName()->equals($permissionName)) {
            throw new UnexpectedValueException('Permission lookup returned a different name.');
        }

        return $permission;
    }
}
