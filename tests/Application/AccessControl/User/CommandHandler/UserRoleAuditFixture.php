<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\AssignRoleToUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RemoveRoleFromUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\Command\AssignRoleToUser;
use Fight\AccessControl\Domain\AccessControl\User\Command\RemoveRoleFromUser;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;

final readonly class UserRoleAuditFixture
{
    public InMemoryUnitOfWork $unitOfWork;

    public InMemoryUserRepository $userRepository;

    public InMemoryUserRepositoryState $userState;

    public InMemoryRoleRepository $roleRepository;

    public InMemoryEventDispatcher $eventDispatcher;

    public User $user;

    public Role $role;

    public Role $otherRole;

    public AssignRoleToUser|RemoveRoleFromUser $command;

    public function __construct(
        public bool $assigning,
        bool $noOp = false,
        UserState $state = UserState::ACTIVE,
        bool $superAdmin = false
    ) {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->userState = new InMemoryUserRepositoryState();
        $this->userRepository = new InMemoryUserRepository($this->unitOfWork, state: $this->userState);
        $this->roleRepository = new InMemoryRoleRepository($this->unitOfWork);
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->otherRole = Role::define(RoleId::generate(), RoleName::fromString('ROLE_OTHER'), [], $now);
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_FIRST'), [], $now);
        $role = $role->renameCustom(RoleName::fromString('ROLE_CURRENT'), $now);
        if ($superAdmin) {
            $role = Role::defineManaged(RoleId::generate(), RoleName::fromString(Role::SUPER_ADMIN_NAME), [], $now);
        }

        $this->role = $role;
        $this->roleRepository->add($this->role);
        $this->roleRepository->add($this->otherRole);

        $user = UserFixture::withState('membership@example.test', $state);
        $user->assignRole($this->otherRole->getId(), $now);
        if ($assigning === $noOp) {
            $user->assignRole($this->role->getId(), $now);
        }

        $this->user = $user;
        $this->userRepository->add($user);
        $actor = UserId::generate();
        $command = new RemoveRoleFromUser($actor, $user->getId(), $this->role->getId());
        if ($assigning) {
            $command = new AssignRoleToUser($actor, $user->getId(), $this->role->getId());
        }

        $this->command = $command;
    }

    public function handler(
        ?AuditEvidenceRepository $auditEvidenceRepository = null,
        ?EventDispatcher $eventDispatcher = null,
        ?InMemoryUserRepository $userRepository = null
    ): AssignRoleToUserHandler|RemoveRoleFromUserHandler {
        $arguments = [
            'userRepository'          => $userRepository ?? $this->userRepository,
            'roleRepository'          => $this->roleRepository,
            'clock'                   => new FixedClock('2030-01-02T03:04:05.123456+00:00'),
            'unitOfWork'              => $this->unitOfWork,
            'eventDispatcher'         => $eventDispatcher ?? $this->eventDispatcher,
            'auditEvidenceRepository' => $auditEvidenceRepository
        ];

        if ($this->assigning) {
            return new AssignRoleToUserHandler(...$arguments);
        }

        return new RemoveRoleFromUserHandler(...$arguments);
    }
}
