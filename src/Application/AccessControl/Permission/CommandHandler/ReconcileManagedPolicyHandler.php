<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Permission\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Permission\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Permission\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use LogicException;
use Throwable;

/**
 * Atomically applies the complete managed-policy preflight plan.
 */
final readonly class ReconcileManagedPolicyHandler implements CommandHandler
{
    /**
     * Creates the managed-policy reconciliation handler.
     */
    public function __construct(
        private PermissionRepository $permissionRepository,
        private RoleRepository $roleRepository,
        private ManagedPolicyPlanner $managedPolicyPlanner,
        private UnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher,
        private Clock $clock
    ) {
    }

    /** @inheritDoc */
    public static function commandRegistration(): string
    {
        return ReconcileManagedPolicy::class;
    }

    /** @inheritDoc */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var ReconcileManagedPolicy $command */
        $command = $commandMessage->payload();

        try {
            $event = $this->unitOfWork->commitTransactional(function () use ($command): ManagedPolicyReconciled {
                $plan = $this->managedPolicyPlanner->plan(
                    $command->getPermissions(),
                    $command->getRoles(),
                    $command->getReferencedPermissionIds()
                );
                $occurredAt = $this->clock->now();
                $this->removeManagedRoles($plan);
                $this->applyManagedPermissions($plan, $occurredAt);
                $this->applyManagedRoles($plan, $occurredAt);
                $this->removeManagedPermissions($plan);

                return new ManagedPolicyReconciled($plan->toArray(), $occurredAt);
            });

            $this->eventDispatcher->trigger($event);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));

            throw $throwable;
        }
    }

    /**
     * Creates and reconciles desired managed permissions.
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

            if ($item->getAction() === ManagedPolicyChangeAction::RECONCILE) {
                $current = $this->permissionRepository->getById($definition->getId());
                if (
                    !$current instanceof Permission || !$this->permissionRepository->replace(
                        $current,
                        $current->reconcileManaged($definition->getName(), $definition->getTier(), $occurredAt)
                    )
                ) {
                    throw new LogicException('Managed permission changed after preflight.');
                }
            }
        }
    }

    /**
     * Creates and reconciles desired managed roles.
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

            if ($item->getAction() === ManagedPolicyChangeAction::RECONCILE) {
                $current = $this->roleRepository->getById($definition->getId());
                if (
                    !$current instanceof Role || !$this->roleRepository->replace(
                        $current,
                        $current->reconcileManaged(
                            $definition->getName(),
                            $definition->getPermissionIds(),
                            $occurredAt
                        )
                    )
                ) {
                    throw new LogicException('Managed role changed after preflight.');
                }
            }
        }
    }

    /**
     * Removes managed permissions omitted from desired policy.
     */
    private function removeManagedPermissions(ManagedPolicyPlan $plan): void
    {
        foreach ($plan->getPermissions() as $item) {
            if ($item->getAction() !== ManagedPolicyChangeAction::REMOVE) {
                continue;
            }

            $current = $this->permissionRepository->getById($item->getDefinition()->getId());
            if (!$current instanceof Permission) {
                throw new LogicException('Managed permission changed after preflight.');
            }

            if (!$this->permissionRepository->remove($current)) {
                throw new LogicException('Managed permission changed or became referenced after preflight.');
            }
        }
    }

    /**
     * Removes managed roles omitted from desired policy.
     */
    private function removeManagedRoles(ManagedPolicyPlan $plan): void
    {
        foreach ($plan->getRoles() as $item) {
            if ($item->getAction() !== ManagedPolicyChangeAction::REMOVE) {
                continue;
            }

            $current = $this->roleRepository->getById($item->getDefinition()->getId());
            if (!$current instanceof Role) {
                throw new LogicException('Managed role changed after preflight.');
            }

            if (!$this->roleRepository->remove($current)) {
                throw new LogicException('Managed role changed after preflight.');
            }
        }
    }
}
