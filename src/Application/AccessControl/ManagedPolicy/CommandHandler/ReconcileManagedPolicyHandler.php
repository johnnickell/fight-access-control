<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ManagedPolicy\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
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
 * Atomically applies the complete managed-policy preflight plan.
 */
final readonly class ReconcileManagedPolicyHandler implements CommandHandler
{
    /**
     * Constructs ReconcileManagedPolicyHandler
     *
     * Creates the managed-policy reconciliation handler.
     */
    public function __construct(
        private PermissionRepository $permissionRepository,
        private RoleRepository $roleRepository,
        private ManagedPolicyPlanner $managedPolicyPlanner,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private Clock $clock
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
            /** @var ManagedPolicyReconciled $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ManagedPolicyReconciled {
                $plan = $this->managedPolicyPlanner->plan($command->getPolicy());
                $occurredAt = $this->clock->now();
                $this->removeManagedRoles($plan);
                $this->applyManagedPermissions($plan, $occurredAt);
                $this->applyManagedRoles($plan, $occurredAt);
                $this->removeManagedPermissions($plan);

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
    private function applyManagedPermissions(ManagedPolicyPlan $plan, DateTimeImmutable $occurredAt): void
    {
        foreach ($plan->getPermissions() as $item) {
            $definition = $item->getDefinition();
            if ($item->getAction() === ManagedPolicyChangeAction::CREATE) {
                $this->permissionRepository->add(Permission::defineManaged(
                    $definition->getId(),
                    $definition->getName(),
                    $definition->getTier(),
                    $occurredAt
                ));
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

                if (!$this->permissionRepository->replace($current, $successor)) {
                    throw new StateConflict('Managed permission changed after preflight.');
                }
            }
        }
    }

    /**
     * Creates and reconciles desired managed roles
     */
    private function applyManagedRoles(ManagedPolicyPlan $plan, DateTimeImmutable $occurredAt): void
    {
        foreach ($plan->getRoles() as $item) {
            $definition = $item->getDefinition();
            if ($item->getAction() === ManagedPolicyChangeAction::CREATE) {
                $this->roleRepository->add(Role::defineManaged(
                    $definition->getId(),
                    $definition->getName(),
                    $definition->getPermissionIds(),
                    $occurredAt
                ));
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

                if (!$this->roleRepository->replace($current, $successor)) {
                    throw new StateConflict('Managed role changed after preflight.');
                }
            }
        }
    }

    /**
     * Removes managed permissions omitted from desired policy
     */
    private function removeManagedPermissions(ManagedPolicyPlan $plan): void
    {
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

            if (!$this->permissionRepository->remove($current)) {
                throw new StateConflict('Managed permission changed or became referenced after preflight.');
            }
        }
    }

    /**
     * Removes managed roles omitted from desired policy
     */
    private function removeManagedRoles(ManagedPolicyPlan $plan): void
    {
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

            if (!$this->roleRepository->remove($current)) {
                throw new StateConflict('Managed role changed after preflight.');
            }
        }
    }
}
