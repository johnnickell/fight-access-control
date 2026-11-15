<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditManagedRoleOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditPermissionOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditPermissionState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;
use UnexpectedValueException;

/**
 * Class ReconcileManagedPolicyHandler
 *
 * Atomically applies the complete managed-policy preflight plan and optional evidence.
 */
final readonly class ReconcileManagedPolicyHandler implements CommandHandler
{
    /**
     * Constructs ReconcileManagedPolicyHandler
     */
    public function __construct(
        private PermissionRepository $permissionRepository,
        private RoleRepository $roleRepository,
        private ManagedPolicyPlanner $managedPolicyPlanner,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private Clock $clock,
        private ?AuditEvidenceRepository $auditEvidenceRepository = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return ReconcileManagedPolicy::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ReconcileManagedPolicy $command */
        $command = $commandMessage->payload();

        try {
            $correlation = new AuditCorrelation($commandMessage->id(), null);
            /** @var ManagedPolicyReconciled $event */
            $event = $this->unitOfWork->commitTransactional(function () use (
                $command,
                $correlation
            ): ManagedPolicyReconciled {
                $plan = $this->managedPolicyPlanner->plan($command->getPolicy());
                $occurredAt = $this->clock->now();
                $this->removeManagedRoles($plan, $command, $occurredAt, $correlation);
                $this->applyManagedPermissions($plan, $command, $occurredAt, $correlation);
                $this->applyManagedRoles($plan, $command, $occurredAt, $correlation);
                $this->removeManagedPermissions($plan, $command, $occurredAt, $correlation);

                return new ManagedPolicyReconciled($plan->toArray(), $occurredAt);
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent(
                    $command,
                    'Managed policy reconciliation failed.'
                ));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }

    /**
     * Creates and reconciles desired managed permissions
     */
    private function applyManagedPermissions(
        ManagedPolicyPlan $plan,
        ReconcileManagedPolicy $command,
        DateTimeImmutable $occurredAt,
        AuditCorrelation $correlation
    ): void {
        foreach ($plan->getPermissions() as $item) {
            $definition = $item->getDefinition();
            if ($item->getAction() === ManagedPolicyChangeAction::CREATE) {
                $this->assertAuditAttribution($command);
                $permission = Permission::defineManaged(
                    $definition->getId(),
                    $definition->getName(),
                    $definition->getTier(),
                    $occurredAt
                );
                $this->permissionRepository->add($permission);
                $this->auditPermission($command, null, $permission, $occurredAt, $correlation);
            }

            if (
                $item->getAction() === ManagedPolicyChangeAction::RECONCILE
                || $item->getAction() === ManagedPolicyChangeAction::UNCHANGED
            ) {
                $current = $this->permissionRepository->getById($definition->getId());
                if ($current !== null && !$current->getId()->equals($definition->getId())) {
                    throw new UnexpectedValueException('Permission lookup returned a different identity.');
                }

                if (!$current instanceof Permission) {
                    throw new StateConflict('Managed permission changed after preflight.');
                }

                $successor = $current;
                if ($item->getAction() === ManagedPolicyChangeAction::RECONCILE) {
                    $successor = $current->reconcileManaged(
                        $definition->getName(),
                        $definition->getTier(),
                        $occurredAt
                    );
                } elseif (
                    !$current->isManaged()
                    || !$current->getName()->equals($definition->getName())
                    || $current->getTier() !== $definition->getTier()
                ) {
                    throw new StateConflict('Managed permission changed after preflight.');
                }

                $changed = !($current->getName()->equals($successor->getName())
                    && $current->getTier() === $successor->getTier());
                if ($changed) {
                    $this->assertAuditAttribution($command);
                }

                if (!$this->permissionRepository->replace($current, $successor)) {
                    throw new StateConflict('Managed permission changed after preflight.');
                }

                if ($changed) {
                    $this->auditPermission($command, $current, $successor, $occurredAt, $correlation);
                }
            }
        }
    }

    /**
     * Creates and reconciles desired managed roles
     */
    private function applyManagedRoles(
        ManagedPolicyPlan $plan,
        ReconcileManagedPolicy $command,
        DateTimeImmutable $occurredAt,
        AuditCorrelation $correlation
    ): void {
        foreach ($plan->getRoles() as $item) {
            $definition = $item->getDefinition();
            if ($item->getAction() === ManagedPolicyChangeAction::CREATE) {
                $this->assertAuditAttribution($command);
                $role = Role::defineManaged(
                    $definition->getId(),
                    $definition->getName(),
                    $definition->getPermissionIds(),
                    $occurredAt
                );
                $this->roleRepository->add($role);
                $this->auditRole($command, null, $role, $occurredAt, $correlation);
            }

            if (
                $item->getAction() === ManagedPolicyChangeAction::RECONCILE
                || $item->getAction() === ManagedPolicyChangeAction::UNCHANGED
            ) {
                $current = $this->roleRepository->getById($definition->getId());
                if ($current !== null && !$current->getId()->equals($definition->getId())) {
                    throw new UnexpectedValueException('Role lookup returned a different identity.');
                }

                if (!$current instanceof Role) {
                    throw new StateConflict('Managed role changed after preflight.');
                }

                $successor = $current->reconcileManaged(
                    $definition->getName(),
                    $definition->getPermissionIds(),
                    $occurredAt
                );
                if ($item->getAction() === ManagedPolicyChangeAction::UNCHANGED && $successor !== $current) {
                    throw new StateConflict('Managed role changed after preflight.');
                }

                if ($successor !== $current) {
                    $this->assertAuditAttribution($command);
                }

                if (!$this->roleRepository->replace($current, $successor)) {
                    throw new StateConflict('Managed role changed after preflight.');
                }

                if ($successor !== $current) {
                    $this->auditRole($command, $current, $successor, $occurredAt, $correlation);
                }
            }
        }
    }

    /**
     * Removes managed permissions omitted from desired policy
     */
    private function removeManagedPermissions(
        ManagedPolicyPlan $plan,
        ReconcileManagedPolicy $command,
        DateTimeImmutable $occurredAt,
        AuditCorrelation $correlation
    ): void {
        foreach ($plan->getPermissions() as $item) {
            if ($item->getAction() !== ManagedPolicyChangeAction::REMOVE) {
                continue;
            }

            $current = $this->permissionRepository->getById($item->getDefinition()->getId());
            if ($current !== null && !$current->getId()->equals($item->getDefinition()->getId())) {
                throw new UnexpectedValueException('Permission lookup returned a different identity.');
            }

            if (!$current instanceof Permission) {
                throw new StateConflict('Managed permission changed after preflight.');
            }

            if (!$current->isManaged()) {
                throw new UnexpectedValueException('Managed Permission lookup returned custom state.');
            }

            $this->assertAuditAttribution($command);
            if (!$this->permissionRepository->remove($current)) {
                throw new StateConflict('Managed permission changed or became referenced after preflight.');
            }

            $this->auditPermission($command, $current, null, $occurredAt, $correlation);
        }
    }

    /**
     * Removes managed roles omitted from desired policy
     */
    private function removeManagedRoles(
        ManagedPolicyPlan $plan,
        ReconcileManagedPolicy $command,
        DateTimeImmutable $occurredAt,
        AuditCorrelation $correlation
    ): void {
        foreach ($plan->getRoles() as $item) {
            if ($item->getAction() !== ManagedPolicyChangeAction::REMOVE) {
                continue;
            }

            $current = $this->roleRepository->getById($item->getDefinition()->getId());
            if ($current !== null && !$current->getId()->equals($item->getDefinition()->getId())) {
                throw new UnexpectedValueException('Role lookup returned a different identity.');
            }

            if (!$current instanceof Role) {
                throw new StateConflict('Managed role changed after preflight.');
            }

            if (!$current->isManaged()) {
                throw new UnexpectedValueException('Managed Role lookup returned custom state.');
            }

            $this->assertAuditAttribution($command);
            if (!$this->roleRepository->remove($current)) {
                throw new StateConflict('Managed role changed after preflight.');
            }

            $this->auditRole($command, $current, null, $occurredAt, $correlation);
        }
    }

    /**
     * Validates provenance before a mutation requiring evidence
     */
    private function assertAuditAttribution(ReconcileManagedPolicy $command): void
    {
        if ($this->auditEvidenceRepository !== null && $command->getActorId() === null) {
            throw new InvalidInput('Managed policy audit requires explicit actor provenance.');
        }
    }

    /**
     * Records one authoritative Permission outcome inside the owning transaction
     */
    private function auditPermission(
        ReconcileManagedPolicy $command,
        ?Permission $before,
        ?Permission $after,
        DateTimeImmutable $occurredAt,
        AuditCorrelation $correlation
    ): void {
        $actorId = $command->getActorId();
        if ($this->auditEvidenceRepository === null || $actorId === null) {
            return;
        }

        /** @var Permission $subject Both absent is not an admitted mutation path */
        $subject = $after ?? $before;
        $action = match (true) {
            $before === null => AuditAction::PERMISSION_CREATED,
            $after === null => AuditAction::PERMISSION_REMOVED,
            default => AuditAction::PERMISSION_RECONCILED
        };
        $this->auditEvidenceRepository->add(AuditEvidence::record(
            $actorId,
            new AuditSubject($subject->getId()),
            $action,
            $occurredAt,
            new AuditPermissionOutcome(
                $before === null ? null : AuditPermissionState::fromPermission($before),
                $after === null ? null : AuditPermissionState::fromPermission($after)
            ),
            $correlation
        ));
    }

    /**
     * Records one authoritative managed Role outcome without a policy snapshot
     */
    private function auditRole(
        ReconcileManagedPolicy $command,
        ?Role $before,
        ?Role $after,
        DateTimeImmutable $occurredAt,
        AuditCorrelation $correlation
    ): void {
        $actorId = $command->getActorId();
        if ($this->auditEvidenceRepository === null || $actorId === null) {
            return;
        }

        /** @var Role $subject Both absent is not an admitted mutation path */
        $subject = $after ?? $before;
        $action = match (true) {
            $before === null => AuditAction::MANAGED_ROLE_CREATED,
            $after === null => AuditAction::MANAGED_ROLE_REMOVED,
            default => AuditAction::MANAGED_ROLE_RECONCILED
        };
        $this->auditEvidenceRepository->add(AuditEvidence::record(
            $actorId,
            new AuditSubject($subject->getId()),
            $action,
            $occurredAt,
            new AuditManagedRoleOutcome(
                new AuditRoleRevisions($before?->getRevision(), $after?->getRevision()),
                $before?->getPermissionIds(),
                $after?->getPermissionIds()
            ),
            $correlation
        ));
    }
}
