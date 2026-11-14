<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\Discovery\FeatureReferenceDiscovery;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;

use function PHPStan\dumpType;

static function (
    UserId|AgentId|SystemId|AnonymousId|null $actorId,
    FeatureReferenceDiscovery $discovery,
    FeatureRepository $featureRepository,
    PermissionRepository $permissionRepository,
    Clock $clock,
    TransactionalUnitOfWork $unitOfWork,
    EventDispatcher $eventDispatcher,
    AuditEvidenceRepository $auditEvidenceRepository
): ProvisionFeaturesHandler {
    $command = new ProvisionFeatures(defaultPermissionName: null, actorId: $actorId);
    dumpType($command->getActorId());
    $disabled = new ProvisionFeaturesHandler(
        $discovery,
        $featureRepository,
        $permissionRepository,
        $clock,
        $unitOfWork,
        $eventDispatcher
    );
    $enabled = new ProvisionFeaturesHandler(
        discovery: $discovery,
        featureRepository: $featureRepository,
        permissionRepository: $permissionRepository,
        clock: $clock,
        unitOfWork: $unitOfWork,
        eventDispatcher: $eventDispatcher,
        auditEvidenceRepository: $auditEvidenceRepository
    );

    return $command->getActorId() === null ? $disabled : $enabled;
};
