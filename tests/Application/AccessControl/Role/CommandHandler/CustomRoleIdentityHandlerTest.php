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
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\ControllableRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
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
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(function () use ($roleRepository, $roleId, $unitOfWork): void {
            self::assertInstanceOf(Role::class, $roleRepository->getById($roleId));
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new CreateCustomRoleHandler(
            $roleRepository,
            new FixedClock($createdAt),
            $unitOfWork,
            $eventDispatcher
        );

        $handler->handle(CommandMessage::create(new CreateCustomRole($actorId, $roleId, $name)));

        self::assertSame(CreateCustomRole::class, CreateCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        $role = $roleRepository->getById($roleId);
        self::assertInstanceOf(Role::class, $role);
        self::assertSame($name, $role->getName());
        self::assertFalse($role->isManaged());
        self::assertSame([], $role->getPermissionIds());
        self::assertSame($createdAt, $role->getCreatedAt());
        self::assertSame($createdAt, $role->getUpdatedAt());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CustomRoleCreated::class, $eventDispatcher->events()[0]);
        $event = $eventDispatcher->events()[0];
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
        $permissionRepository = new InMemoryPermissionRepository($unitOfWork);
        $permissionRepository->add(Permission::define(
            $permissionId,
            PermissionName::fromString('VIEW_SUPPORT'),
            $createdAt
        ));
        $roleRepository = new InMemoryRoleRepository($unitOfWork);
        $roleRepository->add(Role::define(
            $roleId,
            RoleName::fromString('ROLE_SUPPORT'),
            [$permissionId],
            $createdAt
        ));
        $eventDispatcher = new InMemoryEventDispatcher(function () use ($roleRepository, $roleId, $unitOfWork): void {
            self::assertSame('ROLE_HELP_DESK', $roleRepository->getById($roleId)?->getName()->toString());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = $this->renameHandler(
            $roleRepository,
            $unitOfWork,
            $eventDispatcher,
            $renamedAt
        );

        $handler->handle(CommandMessage::create(new RenameCustomRole(
            $actorId,
            $roleId,
            RoleName::fromString('ROLE_HELP_DESK'),
            $roleRepository->getById($roleId)->getRevision()
        )));

        self::assertSame(RenameCustomRole::class, RenameCustomRoleHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        $role = $roleRepository->getById($roleId);
        self::assertInstanceOf(Role::class, $role);
        self::assertSame($roleId, $role->getId());
        self::assertSame([$permissionId], $role->getPermissionIds());
        self::assertFalse($role->isManaged());
        self::assertSame($createdAt, $role->getCreatedAt());
        self::assertSame($renamedAt, $role->getUpdatedAt());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CustomRoleRenamed::class, $eventDispatcher->events()[0]);
        $event = $eventDispatcher->events()[0];
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($roleId, $event->getRoleId());
        self::assertSame('ROLE_HELP_DESK', $event->getName()->toString());
        self::assertSame($renamedAt, $event->getRenamedAt());
    }

    public function test_creation_and_rename_reject_the_reserved_name_without_success_events(): void
    {
        $roleRepository = new InMemoryRoleRepository();
        $role = $this->customRole();
        $roleRepository->add($role);
        $reserved = RoleName::fromString(Role::SUPER_ADMIN_NAME);
        $commands = [
            new CreateCustomRole($this->actorId(), RoleId::generate(), $reserved),
            new RenameCustomRole($this->actorId(), $role->getId(), $reserved, $role->getRevision())
        ];
        foreach ($commands as $command) {
            $eventDispatcher = new InMemoryEventDispatcher();
            $unitOfWork = new InMemoryUnitOfWork();
            $handler = $this->renameHandler($roleRepository, $unitOfWork, $eventDispatcher);
            if ($command instanceof CreateCustomRole) {
                $handler = new CreateCustomRoleHandler(
                    $roleRepository,
                    new FixedClock('2026-08-23T12:00:00+00:00'),
                    $unitOfWork,
                    $eventDispatcher
                );
            }

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame($role, $roleRepository->getById($role->getId()));
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
        }
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
                )
            ] as $command
        ) {
            $roleRepository = new InMemoryRoleRepository();
            $roleRepository->add($existing);
            $eventDispatcher = new InMemoryEventDispatcher();
            $handler = new CreateCustomRoleHandler(
                $roleRepository,
                new FixedClock('2026-08-23T12:00:00+00:00'),
                new InMemoryUnitOfWork(),
                $eventDispatcher
            );

            $failure = $this->captureFailure($handler->handle(...), $command);

            self::assertInstanceOf(CustomRoleException::class, $failure);
            self::assertSame($existing, $roleRepository->getById($existing->getId()));
            $this->assertCommandFailure($eventDispatcher, $command, $failure);
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
            new ControllableRoleRepository($this->customRole(), replaceSucceeds: false)
        ];
        $commands = [];
        foreach ($cases as $index => $roleRepository) {
            $commands[] = new RenameCustomRole(
                $this->actorId(),
                $roleId,
                RoleName::fromString($index === 1 ? 'ROLE_TAKEN' : 'ROLE_RENAMED'),
                $roleRepository->getById($roleId)?->getRevision() ?? RoleRevision::initial()
            );
        }

        foreach ($cases as $index => $roleRepository) {
            $eventDispatcher = new InMemoryEventDispatcher();
            $failure = $this->captureFailure(
                $this->renameHandler($roleRepository, new InMemoryUnitOfWork(), $eventDispatcher)->handle(...),
                $commands[$index]
            );

            self::assertInstanceOf(CustomRoleException::class, $failure);
            $this->assertCommandFailure($eventDispatcher, $commands[$index], $failure);
        }
    }

    public function test_it_rethrows_the_identical_repository_failure_and_dispatches_command_failure(): void
    {
        $expected = new RuntimeException('role repository read failed');
        $roleRepository = new ControllableRoleRepository(getFailure: $expected);
        $eventDispatcher = new InMemoryEventDispatcher();
        $command = new CreateCustomRole(
            $this->actorId(),
            RoleId::fromString('018f0000-0000-7000-8000-000000000002'),
            RoleName::fromString('ROLE_SUPPORT')
        );
        $handler = new CreateCustomRoleHandler(
            $roleRepository,
            new FixedClock('2026-08-23T12:00:00+00:00'),
            new InMemoryUnitOfWork(),
            $eventDispatcher
        );

        $actual = $this->captureFailure($handler->handle(...), $command);

        self::assertSame($expected, $actual);
        $this->assertCommandFailure($eventDispatcher, $command, $expected);
    }

    public function test_commands_and_events_round_trip_and_reject_missing_required_data(): void
    {
        $actorId = $this->actorId();
        $roleId = RoleId::fromString('018f0000-0000-7000-8000-000000000002');
        $name = RoleName::fromString('ROLE_SUPPORT');
        $now = new DateTimeImmutable('2026-08-23T12:00:00+00:00');
        $messages = [
            new CreateCustomRole($actorId, $roleId, $name),
            new RenameCustomRole($actorId, $roleId, $name, RoleRevision::initial()),
            new CustomRoleCreated($actorId, $roleId, $name, $now),
            new CustomRoleRenamed($actorId, $roleId, $name, $now)
        ];

        foreach ($messages as $message) {
            self::assertEquals($message, $message::fromArray($message->toArray()));
        }

        $rejected = 0;
        $messageClasses = [
            CreateCustomRole::class,
            RenameCustomRole::class,
            CustomRoleCreated::class,
            CustomRoleRenamed::class
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
        RoleRepository $roleRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        ?DateTimeImmutable $now = null
    ): RenameCustomRoleHandler {
        return new RenameCustomRoleHandler(
            $roleRepository,
            new FixedClock($now ?? '2026-08-23T12:00:00+00:00'),
            $unitOfWork,
            $eventDispatcher
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
        InMemoryEventDispatcher $eventDispatcher,
        Command $command,
        Throwable $throwable
    ): void {
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        self::assertSame($command, $eventDispatcher->events()[0]->getCommand());
        $message = 'Custom role rename failed.';
        if ($command instanceof CreateCustomRole) {
            $message = 'Custom role creation failed.';
        }

        self::assertSame($message, $eventDispatcher->events()[0]->getErrorMessage());
        self::assertNotSame($throwable->getMessage(), $eventDispatcher->events()[0]->getErrorMessage());
    }
}
