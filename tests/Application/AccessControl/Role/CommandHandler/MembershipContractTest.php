<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Role\CommandHandler;

use Closure;
use Fight\AccessControl\Application\AccessControl\Role\CommandHandler as RoleHandlers;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler as UserHandlers;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;
use Fight\AccessControl\Domain\AccessControl\Role\Command\CreateCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Adapter\Http\Error\SafeHttpDiagnostics;
use Fight\Common\Adapter\Http\Error\SafeHttpErrorPolicy;
use Fight\Common\Adapter\Http\Psr17\JSendResponseFactory;
use Fight\Common\Adapter\Middleware\Psr15\SafeJSendErrorMiddleware;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Throwable;

#[CoversClass(RoleHandlers\CreateCustomRoleHandler::class)]
#[CoversClass(RoleHandlers\RenameCustomRoleHandler::class)]
#[CoversClass(RoleHandlers\RemoveCustomRoleHandler::class)]
#[CoversClass(RoleHandlers\GrantPermissionToCustomRoleHandler::class)]
#[CoversClass(RoleHandlers\RevokePermissionFromCustomRoleHandler::class)]
#[CoversClass(UserHandlers\AssignRoleToUserHandler::class)]
#[CoversClass(UserHandlers\RemoveRoleFromUserHandler::class)]
final class MembershipContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['create', 'rename', 'remove', 'grant', 'revoke', 'assign', 'unassign'] as $operation) {
            yield $operation => [$operation];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function existingTargets(): iterable
    {
        foreach (['rename', 'remove', 'grant', 'revoke', 'assign', 'unassign'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function test_wrong_role_identity_remains_internal_before_writes(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $roles = $this->createMock(RoleRepository::class);
        $roles->method('getById')->willReturn(Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_UNRELATED'),
            [],
            $fixture->role->getCreatedAt()
        ));
        $roles->expects(self::never())->method('add');
        $roles->expects(self::never())->method('replace');
        $roles->expects(self::never())->method('remove');
        $failure = $this->failure(fn() => $fixture->bus($fixture->handler(roleRepository: $roles))
            ->execute($fixture->command));
        self::assertInstanceOf(LogicException::class, $failure);
        self::assertSame(500, new SafeHttpErrorPolicy()->status($failure));
        self::assertFalse($fixture->unitOfWork->transactionCompleted);
        self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
        $this->assertFailure($fixture);
    }

    #[DataProvider('existingTargets')]
    public function test_true_role_absence_is_deliberate_not_found(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $roles = $this->createStub(RoleRepository::class);
        $failure = $this->failure(fn() => $fixture->bus($fixture->handler(roleRepository: $roles))
            ->execute($fixture->command));
        self::assertInstanceOf(ResourceNotFound::class, $failure);
        self::assertSame(404, new SafeHttpErrorPolicy()->status($failure));
        self::assertFalse($fixture->unitOfWork->transactionCompleted);
        $this->assertFailure($fixture);
    }

    public function test_both_permission_readers_reject_unrelated_identity_even_for_apparent_no_op(): void
    {
        foreach (['grant', 'revoke'] as $operation) {
            $fixture = new MembershipContractFixture($operation, true);
            $other = new MembershipContractFixture($operation);
            $permissions = $this->createStub(PermissionRepository::class);
            $permissions->method('getById')->willReturn($other->permission);
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(permissionRepository: $permissions))
                ->execute($fixture->command));
            self::assertInstanceOf(LogicException::class, $failure);
            self::assertSame($fixture->role, $fixture->roleRepository->getById($fixture->role->getId()));
            self::assertFalse($fixture->unitOfWork->transactionCompleted);
            $this->assertFailure($fixture);
        }
    }

    public function test_user_readers_reject_unrelated_identity_before_role_resolution(): void
    {
        foreach (['assign', 'unassign'] as $operation) {
            $fixture = new MembershipContractFixture($operation);
            $other = new MembershipContractFixture($operation);
            $users = $this->createMock(UserRepository::class);
            $users->method('getById')->willReturn($other->user);
            $users->expects(self::never())->method('replaceRoleAssignments');
            $roles = $this->createMock(RoleRepository::class);
            $roles->expects(self::never())->method('getById');
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(
                roleRepository: $roles,
                userRepository: $users
            ))->execute($fixture->command));
            self::assertInstanceOf(LogicException::class, $failure);
            self::assertFalse($fixture->unitOfWork->transactionCompleted);
            $this->assertFailure($fixture);
        }
    }

    public function test_name_index_mismatch_is_internal_not_name_collision(): void
    {
        foreach (['create', 'rename'] as $operation) {
            $fixture = new MembershipContractFixture($operation);
            $roles = $this->createMock(RoleRepository::class);
            $roles->method('getById')->willReturn($operation === 'create' ? null : $fixture->role);
            $roles->method('getByName')->willReturn($fixture->role);
            $roles->expects(self::never())->method('add');
            $roles->expects(self::never())->method('replace');
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(roleRepository: $roles))
                ->execute($fixture->command));
            self::assertInstanceOf(LogicException::class, $failure);
            $this->assertFailure($fixture);
        }
    }

    public function test_same_role_name_lookup_distinguishes_concurrent_change_from_contradictory_state(): void
    {
        foreach ([false, true] as $sameRevision) {
            $fixture = new MembershipContractFixture('rename');
            $current = $fixture->role->renameCustom(RoleName::fromString('ROLE_NEW'), $fixture->role->getUpdatedAt());
            if ($sameRevision) {
                $current = Role::reconstitute(
                    $current->getId(),
                    $current->getName(),
                    $current->getPermissionIds(),
                    false,
                    $current->getCreatedAt(),
                    $current->getUpdatedAt(),
                    $fixture->role->getRevision()
                );
            }

            $fixture->roleState->roles = [$current];
            $roleRepository = $this->createMock(RoleRepository::class);
            $roleRepository->method('getById')->willReturn($fixture->role);
            $roleRepository->method('getByName')->willReturn($current);
            $roleRepository->expects(self::never())->method('replace');
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(roleRepository: $roleRepository))
                ->execute($fixture->command));
            self::assertInstanceOf($sameRevision ? LogicException::class : StateConflict::class, $failure);
            self::assertSame($current, $fixture->roleRepository->getById($current->getId()));
            self::assertFalse($fixture->unitOfWork->transactionCompleted);
            $this->assertFailure($fixture);
        }
    }

    public function test_membership_no_op_cannot_accept_a_role_snapshot_that_lost_its_final_fence(): void
    {
        foreach (['grant', 'revoke'] as $operation) {
            $fixture = new MembershipContractFixture($operation, true);
            $roles = $this->createMock(RoleRepository::class);
            $roles->method('getById')->willReturn($fixture->role);
            $roles->method('validateCustomPermissionGrant')->willReturn(true);
            $roles->method('validatePermissionReference')->willReturn(true);
            $roles->method('validateCurrent')->willReturn(false);
            $roles->expects(self::never())->method('replace');
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(roleRepository: $roles))
                ->execute($fixture->command));
            self::assertInstanceOf(StateConflict::class, $failure);
            self::assertFalse($fixture->unitOfWork->transactionCompleted);
            $this->assertFailure($fixture);
        }
    }

    public function test_reserved_definition_mismatch_is_internal_not_ordinary_membership_refusal(): void
    {
        foreach (['assign', 'unassign'] as $operation) {
            $fixture = new MembershipContractFixture($operation, true);
            $roles = $this->createStub(RoleRepository::class);
            $roles->method('getById')->willReturn($fixture->role);
            $roles->method('getByName')->willReturn($fixture->role);
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(roleRepository: $roles))
                ->execute($fixture->command));
            self::assertInstanceOf(LogicException::class, $failure);
            self::assertSame(500, new SafeHttpErrorPolicy()->status($failure));
            $this->assertFailure($fixture);
        }
    }

    #[DataProvider('operations')]
    public function test_all_real_registered_serialized_command_paths_commit_before_success(string $operation): void
    {
        $fixture = new MembershipContractFixture($operation);
        $message = CommandMessage::arrayDeserialize(CommandMessage::create($fixture->command)->arraySerialize());
        $fixture->bus()->dispatch($message);
        self::assertTrue($fixture->unitOfWork->transactionCompleted);
        self::assertCount(1, $fixture->eventDispatcher->events());
        self::assertNotInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
        $this->assertChanged($fixture);
    }

    #[DataProvider('operations')]
    public function test_commit_and_double_publication_faults_preserve_primary_and_actual_persisted_outcome(
        string $operation
    ): void {
        foreach (['before', 'after', 'publication'] as $stage) {
            $fixture = new MembershipContractFixture($operation);
            $primary = new RuntimeException('Synthetic primary diagnostic', 404);
            $secondary = new RuntimeException('Synthetic secondary diagnostic', 409);
            $fail = static function () use ($primary): void {
                throw $primary;
            };
            if ($stage === 'before') {
                $fixture->unitOfWork->beforeCommit = $fail;
            } elseif ($stage === 'after') {
                $fixture->unitOfWork->afterCommit = $fail;
            }

            $attempts = [];
            $events = new InMemoryEventDispatcher(static function ($event) use (
                &$attempts,
                $primary,
                $secondary,
                $fixture
            ): void {
                $attempts[] = $event;
                if (!$event instanceof CommandFailedEvent) {
                    self::assertTrue($fixture->unitOfWork->transactionCompleted);
                    throw $primary;
                }

                throw $secondary;
            });
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(eventDispatcher: $events))
                ->execute($fixture->command));
            self::assertSame($primary, $failure);
            self::assertSame($stage !== 'before', $fixture->unitOfWork->transactionCompleted);
            if ($stage === 'before') {
                self::assertSame([$fixture->role], $fixture->roleState->roles);
                self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
            } else {
                $this->assertChanged($fixture);
            }

            self::assertCount($stage === 'publication' ? 2 : 1, $attempts);
            $event = $attempts[array_key_last($attempts)];
            self::assertInstanceOf(CommandFailedEvent::class, $event);
            self::assertSame($fixture->command->toArray(), $event->toArray()['command_data']);
            $serialized = serialize(CommandFailedEvent::fromArray($event->toArray())->toArray());
            self::assertStringNotContainsString($primary->getMessage(), $serialized);
            self::assertStringNotContainsString($secondary->getMessage(), $serialized);
            self::assertSame(500, new SafeHttpErrorPolicy()->status($failure));
        }
    }

    #[DataProvider('operations')]
    public function test_repository_faults_including_domain_shaped_faults_are_never_blanket_translated(
        string $operation
    ): void {
        $faults = [new RuntimeException('Synthetic repository detail'), new CustomRoleException('Synthetic fault')];
        foreach ($faults as $fault) {
            $fixture = new MembershipContractFixture($operation);
            $roles = $this->createStub(RoleRepository::class);
            $roles->method('getById')->willThrowException($fault);
            $failure = $this->failure(fn() => $fixture->bus($fixture->handler(roleRepository: $roles))
                ->execute($fixture->command));
            self::assertSame($fault, $failure);
            self::assertSame(500, new SafeHttpErrorPolicy()->status($failure));
            self::assertFalse($fixture->unitOfWork->transactionCompleted);
            $this->assertFailure($fixture);
        }
    }

    public function test_all_validated_no_ops_keep_complete_state_and_publish_nothing(): void
    {
        foreach (['rename', 'grant', 'revoke', 'assign', 'unassign'] as $operation) {
            $fixture = new MembershipContractFixture($operation, true);
            $fixture->bus()->execute($fixture->command);
            self::assertTrue($fixture->unitOfWork->transactionCompleted);
            self::assertSame([$fixture->role], $fixture->roleState->roles);
            self::assertSame($fixture->user, $fixture->userRepository->getById($fixture->user->getId()));
            self::assertSame([], $fixture->eventDispatcher->events());
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function safeFailures(): iterable
    {
        yield 'missing role' => ['role', 404];
        yield 'missing user' => ['user', 404];
        yield 'missing permission' => ['permission', 404];
        yield 'collision' => ['collision', 409];
        yield 'stale caller' => ['stale', 412];
        yield 'managed role' => ['managed', 422];
        yield 'reserved name' => ['reserved', 422];
        yield 'live reference' => ['reference', 422];
        yield 'final CAS' => ['cas', 409];
        yield 'wrong identity' => ['identity', 500];
        yield 'storage' => ['storage', 500];
    }

    #[DataProvider('safeFailures')]
    public function test_actual_safe_http_composition_reports_once_without_exposing_diagnostics(
        string $case,
        int $status
    ): void {
        $operation = match ($case) {
            'user' => 'assign',
            'permission' => 'revoke',
            'collision', 'reserved' => 'create',
            'reference' => 'remove',
            default => 'rename'
        };
        $fixture = new MembershipContractFixture($operation);
        $command = $fixture->command;
        $roles = null;
        $permissions = null;
        $users = null;
        if ($case === 'role') {
            $fixture->roleState->roles = [];
        } elseif ($case === 'user') {
            $users = $this->createStub(UserRepository::class);
        } elseif ($case === 'permission') {
            $permissions = $this->createStub(PermissionRepository::class);
        } elseif ($case === 'collision') {
            $command = new CreateCustomRole(UserId::generate(), $fixture->role->getId(), $fixture->role->getName());
        } elseif ($case === 'reserved') {
            $command = new CreateCustomRole(
                UserId::generate(),
                RoleId::generate(),
                RoleName::fromString(Role::SUPER_ADMIN_NAME)
            );
        } elseif ($case === 'stale') {
            $fixture->roleState->roles = [$fixture->role->renameCustom(
                RoleName::fromString('ROLE_LATER'),
                $fixture->role->getUpdatedAt()
            )];
        } elseif ($case === 'managed') {
            $fixture->roleState->roles = [Role::reconstitute(
                $fixture->role->getId(),
                $fixture->role->getName(),
                [],
                true,
                $fixture->role->getCreatedAt(),
                $fixture->role->getUpdatedAt(),
                $fixture->role->getRevision()
            )];
        } elseif ($case === 'reference') {
            $users = $this->createStub(UserRepository::class);
            $users->method('hasRoleAssignment')->willReturn(true);
        } elseif ($case === 'cas') {
            $roles = $this->createStub(RoleRepository::class);
            $roles->method('getById')->willReturn($fixture->role);
            $roles->method('replace')->willReturn(false);
        } elseif ($case === 'identity') {
            $roles = $this->createStub(RoleRepository::class);
            $roles->method('getById')->willReturn(new MembershipContractFixture('rename')->role);
        } elseif ($case === 'storage') {
            $fixture->unitOfWork->beforeCommit = static function (): void {
                throw new RuntimeException('Synthetic private storage detail', 422);
            };
        }

        $bus = $fixture->bus($fixture->handler($roles, $permissions, $users));
        $diagnostics = $this->createMock(SafeHttpDiagnostics::class);
        $diagnostics->expects(self::once())->method('report')->with(self::callback(
            static fn (Throwable $failure): bool => new SafeHttpErrorPolicy()->status($failure) === $status
        ));
        $middleware = new SafeJSendErrorMiddleware(
            new JSendResponseFactory(new ResponseFactory(), new StreamFactory()),
            new SafeHttpErrorPolicy(),
            $diagnostics
        );
        $handler = new readonly class (static fn () => $bus->execute($command)) implements RequestHandlerInterface {
            public function __construct(private Closure $execute)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->execute)();
                throw new RuntimeException('The expected failure did not occur.');
            }
        };
        $response = $middleware->process(new ServerRequestFactory()->createServerRequest('POST', '/roles'), $handler);
        self::assertSame($status, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('Synthetic', $body);
        self::assertStringNotContainsString('ROLE_', $body);
        self::assertStringNotContainsString('trace', $body);
        self::assertStringNotContainsString($fixture->role->getId()->toString(), $body);
    }

    private function assertChanged(MembershipContractFixture $fixture): void
    {
        $role = $fixture->roleRepository->getById($fixture->role->getId());
        $user = $fixture->userRepository->getById($fixture->user->getId());
        if ($fixture->operation === 'remove') {
            self::assertNull($role);
        } elseif (in_array($fixture->operation, ['rename', 'grant', 'revoke'], true)) {
            self::assertNotNull($role);
            self::assertTrue($fixture->role->getRevision()->next()->equals($role->getRevision()));
        } elseif ($fixture->operation === 'create') {
            self::assertCount(2, $fixture->roleState->roles);
        } else {
            self::assertNotNull($user);
            self::assertSame(5, $user->getAuthorizationAssignmentRevision());
            self::assertSame($fixture->operation === 'assign', $user->hasRole($fixture->role->getId()));
        }
    }

    private function assertFailure(MembershipContractFixture $fixture): void
    {
        self::assertCount(1, $fixture->eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $fixture->eventDispatcher->events()[0]);
        self::assertSame(
            $fixture->command->toArray(),
            $fixture->eventDispatcher->events()[0]->toArray()['command_data']
        );
    }

    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('The operation unexpectedly completed.');
    }
}
