<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\CreateCustomRoleHandler;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler\RenameCustomRoleHandler;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\Command\CreateCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleCreated;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRenamed;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\RoleAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\ControllableRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Service\FixedRoleAdministrationAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(CreateCustomRoleHandler::class)]
#[CoversClass(RenameCustomRoleHandler::class)]
#[CoversClass(CreateCustomRole::class)]
#[CoversClass(RenameCustomRole::class)]
#[CoversClass(CustomRoleCreated::class)]
#[CoversClass(CustomRoleRenamed::class)]
final class CustomRoleIdentityHandlerTest extends TestCase
{
    public function test_it_creates_an_empty_custom_role_before_committing_once_and_emitting_success(): void
    {
        $actorId = $this->actorId();
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $name = RoleName::fromString('ROLE_SUPPORT');
        $createdAt = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRoleRepository($unitOfWork);
        $authorization = new FixedRoleAdministrationAuthorization(true);
        $events = new InMemoryEventDispatcher(function () use ($repository, $roleId, $unitOfWork): void {
            self::assertInstanceOf(Role::class, $repository->getById($roleId));
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new CreateCustomRoleHandler(
            $repository,
            $authorization,
            new FixedClock($createdAt),
            $unitOfWork,
            $events
        );

        $handler->handle(CommandMessage::create(new CreateCustomRole($actorId, $roleId, $name)));

        self::assertSame(CreateCustomRole::class, CreateCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        $role = $repository->getById($roleId);
        self::assertInstanceOf(Role::class, $role);
        self::assertSame($name, $role->getName());
        self::assertFalse($role->isManaged());
        self::assertSame([], $role->getPermissionIds());
        self::assertSame($createdAt, $role->getCreatedAt());
        self::assertSame($createdAt, $role->getUpdatedAt());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CustomRoleCreated::class, $events->events()[0]);
        $event = $events->events()[0];
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($roleId, $event->getRoleId());
        self::assertSame($name, $event->getName());
        self::assertSame($createdAt, $event->getCreatedAt());
    }

    public function test_it_renames_a_custom_role_without_changing_identity_membership_or_creation(): void
    {
        $actorId = $this->actorId();
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $permissionId = PermissionId::fromString('018f0000-0000-7000-8000-000000000003');
        $createdAt = new DateTimeImmutable('2026-08-22T12:00:00+00:00');
        $renamedAt = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $unitOfWork = new InMemoryUnitOfWork();
        $permissions = new InMemoryPermissionRepository($unitOfWork);
        $permissions->add(Permission::define(
            $permissionId,
            PermissionName::fromString('VIEW_SUPPORT'),
            $createdAt
        ));
        $repository = new InMemoryRoleRepository($unitOfWork);
        $repository->add(Role::define(
            $roleId,
            RoleName::fromString('ROLE_SUPPORT'),
            [$permissionId],
            $createdAt
        ));
        $events = new InMemoryEventDispatcher(function () use ($repository, $roleId, $unitOfWork): void {
            self::assertSame('ROLE_HELP_DESK', $repository->getById($roleId)?->getName()->toString());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = $this->renameHandler(
            $repository,
            $unitOfWork,
            $events,
            new FixedRoleAdministrationAuthorization(true),
            $renamedAt
        );

        $handler->handle(CommandMessage::create(new RenameCustomRole(
            $actorId,
            $roleId,
            RoleName::fromString('ROLE_HELP_DESK')
        )));

        self::assertSame(RenameCustomRole::class, RenameCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        $role = $repository->getById($roleId);
        self::assertInstanceOf(Role::class, $role);
        self::assertSame($roleId, $role->getId());
        self::assertSame([$permissionId], $role->getPermissionIds());
        self::assertFalse($role->isManaged());
        self::assertSame($createdAt, $role->getCreatedAt());
        self::assertSame($renamedAt, $role->getUpdatedAt());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CustomRoleRenamed::class, $events->events()[0]);
        $event = $events->events()[0];
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($roleId, $event->getRoleId());
        self::assertSame('ROLE_HELP_DESK', $event->getName()->toString());
        self::assertSame($renamedAt, $event->getRenamedAt());
    }

    public function test_creation_authorization_denial_causes_no_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryRoleRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $command = new CreateCustomRole(
            $this->actorId(),
            RoleId::fromString('018f0000-0000-7000-8000-000000000002'),
            RoleName::fromString('ROLE_SUPPORT')
        );
        $handler = new CreateCustomRoleHandler(
            $repository,
            new FixedRoleAdministrationAuthorization(false),
            new FixedClock('2026-08-23T12:00:00+00:00'),
            $unitOfWork,
            $events
        );

        $failure = $this->captureFailure($handler->handle(...), $command);

        self::assertInstanceOf(RoleAdministrationAuthorizationException::class, $failure);
        self::assertNull($repository->getById($command->getRoleId()));
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_rename_authorization_denial_preserves_the_role(): void
    {
        $role = $this->customRole();
        $repository = new ControllableRoleRepository($role);
        $events = new InMemoryEventDispatcher();
        $command = new RenameCustomRole(
            $this->actorId(),
            $role->getId(),
            RoleName::fromString('ROLE_RENAMED')
        );
        $handler = $this->renameHandler(
            $repository,
            new InMemoryUnitOfWork(),
            $events,
            new FixedRoleAdministrationAuthorization(false)
        );

        $failure = $this->captureFailure($handler->handle(...), $command);

        self::assertInstanceOf(RoleAdministrationAuthorizationException::class, $failure);
        self::assertSame($role, $repository->getById($role->getId()));
        $this->assertCommandFailure($events, $command, $failure);
    }

    public function test_creation_rejects_persisted_identifier_and_name_collisions(): void
    {
        $existing = $this->customRole();
        foreach (
            [
                new CreateCustomRole(
                    $this->actorId(),
                    $existing->getId(),
                    RoleName::fromString('ROLE_UNIQUE')
                ),
                new CreateCustomRole(
                    $this->actorId(),
                    RoleId::fromString('018f0000-0000-7000-8000-000000000004'),
                    $existing->getName()
                ),
            ] as $command
        ) {
            $repository = new InMemoryRoleRepository();
            $repository->add($existing);
            $events = new InMemoryEventDispatcher();
            $handler = new CreateCustomRoleHandler(
                $repository,
                new FixedRoleAdministrationAuthorization(true),
                new FixedClock('2026-08-23T12:00:00+00:00'),
                new InMemoryUnitOfWork(),
                $events
            );

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame($existing, $repository->getById($existing->getId()));
            $this->assertCommandFailure($events, $command, $failure);
        }
    }

    public function test_rename_rejects_missing_colliding_managed_and_concurrently_changed_roles(): void
    {
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $collidingRepository = new InMemoryRoleRepository();
        $collidingRepository->add($this->customRole());
        $collidingRepository->add(Role::define(
            RoleId::fromString('018f0000-0000-7000-8000-000000000004'),
            RoleName::fromString('ROLE_TAKEN'),
            [],
            new DateTimeImmutable('2026-08-22T12:00:00+00:00')
        ));
        $cases = [
            new ControllableRoleRepository(),
            $collidingRepository,
            new ControllableRoleRepository(Role::defineManaged(
                $roleId,
                RoleName::fromString('ROLE_MANAGED'),
                [],
                new DateTimeImmutable('2026-08-22T12:00:00+00:00')
            )),
            new ControllableRoleRepository($this->customRole(), replaceSucceeds: false),
        ];
        $commands = [
            new RenameCustomRole($this->actorId(), $roleId, RoleName::fromString('ROLE_RENAMED')),
            new RenameCustomRole(
                $this->actorId(),
                $roleId,
                RoleName::fromString('ROLE_TAKEN')
            ),
            new RenameCustomRole($this->actorId(), $roleId, RoleName::fromString('ROLE_RENAMED')),
            new RenameCustomRole($this->actorId(), $roleId, RoleName::fromString('ROLE_RENAMED')),
        ];

        foreach ($cases as $index => $repository) {
            $events = new InMemoryEventDispatcher();
            $failure = $this->captureFailure(
                $this->renameHandler($repository, new InMemoryUnitOfWork(), $events)->handle(...),
                $commands[$index]
            );

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($events, $commands[$index], $failure);
        }
    }

    public function test_it_rethrows_the_identical_repository_failure_and_dispatches_command_failure(): void
    {
        $expected = new RuntimeException('role repository read failed');
        $repository = new ControllableRoleRepository(getFailure: $expected);
        $events = new InMemoryEventDispatcher();
        $command = new CreateCustomRole(
            $this->actorId(),
            RoleId::fromString('018f0000-0000-7000-8000-000000000002'),
            RoleName::fromString('ROLE_SUPPORT')
        );
        $handler = new CreateCustomRoleHandler(
            $repository,
            new FixedRoleAdministrationAuthorization(true),
            new FixedClock('2026-08-23T12:00:00+00:00'),
            new InMemoryUnitOfWork(),
            $events
        );

        $actual = $this->captureFailure($handler->handle(...), $command);

        self::assertSame($expected, $actual);
        $this->assertCommandFailure($events, $command, $expected);
    }

    public function test_commands_and_events_round_trip_and_reject_missing_required_data(): void
    {
        $actorId = $this->actorId();
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $name = RoleName::fromString('ROLE_SUPPORT');
        $now = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $messages = [
            new CreateCustomRole($actorId, $roleId, $name),
            new RenameCustomRole($actorId, $roleId, $name),
            new CustomRoleCreated($actorId, $roleId, $name, $now),
            new CustomRoleRenamed($actorId, $roleId, $name, $now),
        ];

        foreach ($messages as $message) {
            self::assertEquals($message, $message::fromArray($message->toArray()));
        }

        $rejected = 0;
        $messageClasses = [
            CreateCustomRole::class,
            RenameCustomRole::class,
            CustomRoleCreated::class,
            CustomRoleRenamed::class,
        ];
        foreach ($messageClasses as $messageClass) {
            try {
                $messageClass::fromArray([]);
                self::fail('Missing required message data must be rejected.');
            } catch (DomainException) {
                ++$rejected;
            }
        }

        self::assertSame(4, $rejected);
    }

    private function actorId(): UserId
    {
        return UserId::fromString('018f0000-0000-7000-8000-000000000001');
    }

    private function customRole(string $name = 'ROLE_SUPPORT'): Role
    {
        return Role::define(
            RoleId::fromString('018f0000-0000-7000-8000-000000000002'),
            RoleName::fromString($name),
            [],
            new DateTimeImmutable('2026-08-22T12:00:00+00:00')
        );
    }

    private function renameHandler(
        RoleRepository $repository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        ?FixedRoleAdministrationAuthorization $authorization = null,
        ?DateTimeImmutable $now = null
    ): RenameCustomRoleHandler {
        return new RenameCustomRoleHandler(
            $repository,
            $authorization ?? new FixedRoleAdministrationAuthorization(true),
            new FixedClock($now ?? '2026-08-23T12:00:00+00:00'),
            $unitOfWork,
            $events
        );
    }

    /**
     * @param callable(CommandMessage): void $handle
     */
    private function captureFailure(callable $handle, Command $command): Throwable
    {
        try {
            $handle(CommandMessage::create($command));
            self::fail('The command must fail.');
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }

    private function assertCommandFailure(
        InMemoryEventDispatcher $events,
        Command $command,
        Throwable $throwable
    ): void {
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        self::assertSame($command, $events->events()[0]->getCommand());
        self::assertSame($throwable->getMessage(), $events->events()[0]->getErrorMessage());
    }
}
