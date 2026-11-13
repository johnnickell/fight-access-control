<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\CreateCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RemoveCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RenameCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Role\Command\CreateCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;

final readonly class RoleLifecycleAuditFixture
{
    public InMemoryUnitOfWork $unitOfWork;

    public InMemoryRoleRepository $roleRepository;

    public InMemoryRoleRepositoryState $state;

    public InMemoryAuditEvidenceRepository $audit;

    public InMemoryEventDispatcher $eventDispatcher;

    public RoutingCommandBus $bus;

    public Command $command;

    public Role $original;

    public DateTimeImmutable $outcomeTime;

    public function __construct(
        string $operation,
        bool $auditEnabled = true,
        bool $omitAudit = false,
        bool $failAudit = false,
        ?InMemoryEventDispatcher $eventDispatcher = null,
        ?Clock $clock = null
    ) {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->state = new InMemoryRoleRepositoryState();
        $this->roleRepository = new InMemoryRoleRepository($this->unitOfWork, state: $this->state);
        $this->audit = new InMemoryAuditEvidenceRepository($this->unitOfWork, failAfterSave: $failAudit);
        $this->eventDispatcher = $eventDispatcher ?? new InMemoryEventDispatcher();
        $this->outcomeTime = new DateTimeImmutable('2030-01-08T12:34:56.123456Z');
        $this->original = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_SUPPORT'),
            [],
            new DateTimeImmutable('2030-01-01T00:00:00Z')
        );
        if ($operation !== 'create') {
            $this->roleRepository->add($this->original);
        }

        $actorId = UserId::generate();
        $this->command = match ($operation) {
            'create' => new CreateCustomRole($actorId, $this->original->getId(), $this->original->getName()),
            'remove' => new RemoveCustomRole($actorId, $this->original->getId(), $this->original->getRevision()),
            default => new RenameCustomRole(
                $actorId,
                $this->original->getId(),
                RoleName::fromString($operation === 'same-name' ? 'ROLE_SUPPORT' : 'ROLE_HELP_DESK'),
                $this->original->getRevision()
            )
        };
        $arguments = $omitAudit ? [] : ['auditEvidenceRepository' => $auditEnabled ? $this->audit : null];
        $clock ??= new FixedClock($this->outcomeTime);
        $handler = match ($operation) {
            'create' => new CreateCustomRoleHandler(
                $this->roleRepository,
                $clock,
                $this->unitOfWork,
                $this->eventDispatcher,
                ...$arguments
            ),
            'remove' => new RemoveCustomRoleHandler(
                $this->roleRepository,
                new InMemoryUserRepository($this->unitOfWork),
                $clock,
                $this->unitOfWork,
                $this->eventDispatcher,
                ...$arguments
            ),
            default => new RenameCustomRoleHandler(
                $this->roleRepository,
                $clock,
                $this->unitOfWork,
                $this->eventDispatcher,
                ...$arguments
            )
        };
        $router = new InMemoryCommandRouter();
        $router->registerHandler($handler::commandRegistration(), $handler);

        $this->bus = new RoutingCommandBus($router);
    }
}
