<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;

final class RegistrationFixture
{
    public readonly InMemoryUnitOfWork $unitOfWork;

    public readonly InMemoryUserRepository $users;

    public InMemoryActivationGrantRepository $grants;

    public readonly InMemoryAuditEvidenceRepository $audit;

    public readonly InMemoryRoleRepository $roles;

    public readonly InMemoryRoleRepositoryState $roleState;

    public readonly InMemoryUserRepositoryState $userState;

    public readonly InMemoryEventDispatcher $events;

    public readonly Role $member;

    public readonly Role $reader;

    public function __construct(
        ?Closure $beforeInsert = null,
        ?Closure $afterInsert = null,
        bool $failAudit = false,
        bool $failGrant = false,
        ?InMemoryEventDispatcher $events = null
    ) {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->userState = new InMemoryUserRepositoryState();
        $this->roleState = new InMemoryRoleRepositoryState();
        $this->users = new InMemoryUserRepository(
            $this->unitOfWork,
            state: $this->userState,
            beforeAddWithRoleAssignments: $beforeInsert,
            afterAddWithRoleAssignments: $afterInsert
        );
        $this->roles = new InMemoryRoleRepository($this->unitOfWork, state: $this->roleState);
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $this->member = Role::define(RoleId::generate(), RoleName::fromString('ROLE_MEMBER'), [], $at);
        $this->reader = Role::defineManaged(RoleId::generate(), RoleName::fromString('ROLE_READER'), [], $at);
        $this->roles->add($this->member);
        $this->roles->add($this->reader);

        $this->grants = new InMemoryActivationGrantRepository($this->unitOfWork, addSucceeds: !$failGrant);
        $this->audit = new InMemoryAuditEvidenceRepository($this->unitOfWork, failAfterSave: $failAudit);
        $this->events = $events ?? new InMemoryEventDispatcher();
    }

    /** @param list<string> $names */
    public function handler(
        array $names = ['ROLE_MEMBER', 'ROLE_READER'],
        ?TransactionalUnitOfWork $unitOfWork = null
    ): RegisterUserHandler {
        return new RegisterUserHandler(
            $this->users,
            $this->roles,
            $this->grants,
            $this->audit,
            $unitOfWork ?? $this->unitOfWork,
            new FixedCredentialGenerator('registration-fixture-credential'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2030-01-01T00:00:00Z'),
            $this->events,
            $names
        );
    }

    public function command(string $email = 'Alice@example.test'): RegisterUser
    {
        return new RegisterUser(UserId::generate(), EmailAddress::fromString($email));
    }

    public function competingRoles(InMemoryUnitOfWork $unitOfWork): InMemoryRoleRepository
    {
        return new InMemoryRoleRepository(
            $unitOfWork,
            authorizationReferences: $this->unitOfWork->authorizationReferenceState(),
            state: $this->roleState
        );
    }
}
