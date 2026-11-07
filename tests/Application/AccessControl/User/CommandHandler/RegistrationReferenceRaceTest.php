<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RegisterUserHandler::class)]
final class RegistrationReferenceRaceTest extends TestCase
{
    public function test_role_removal_or_replacement_committed_after_resolution_wins_and_aborts_all_registration(): void
    {
        foreach (['remove', 'rename', 'revision', 'recreate'] as $change) {
            $fixture = null;
            $winner = new InMemoryUnitOfWork();
            $fixture = new RegistrationFixture(beforeInsert: static function () use (
                &$fixture,
                $winner,
                $change
            ): void {
                self::assertInstanceOf(RegistrationFixture::class, $fixture);
                $roleRepository = $fixture->competingRoles($winner);
                $winner->commitTransactional(static function () use ($fixture, $roleRepository, $change): void {
                    if ($change === 'remove' || $change === 'recreate') {
                        self::assertTrue($roleRepository->remove($fixture->member));
                        if ($change === 'recreate') {
                            $roleRepository->add(Role::define(
                                $fixture->member->getId(),
                                $fixture->member->getName(),
                                [],
                                new DateTimeImmutable('2030-01-01T00:00:01Z')
                            ));
                        }
                    } else {
                        $replacement = $fixture->member->renameCustom(
                            RoleName::fromString('ROLE_CHANGED'),
                            $fixture->member->getUpdatedAt()
                        );
                        if ($change === 'revision') {
                            self::assertTrue($roleRepository->replace($fixture->member, $replacement));
                            $current = $replacement;
                            $replacement = $current->renameCustom(
                                $fixture->member->getName(),
                                $current->getUpdatedAt()
                            );
                            self::assertTrue($roleRepository->replace($current, $replacement));
                            self::assertEquals($fixture->member->getName(), $replacement->getName());
                            self::assertEquals($fixture->member->getUpdatedAt(), $replacement->getUpdatedAt());
                            self::assertFalse($fixture->member->getRevision()->equals($replacement->getRevision()));
                        } else {
                            self::assertTrue($roleRepository->replace($fixture->member, $replacement));
                        }
                    }
                });
            });
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::fail('A stale resolved Role was inserted.');
            } catch (StateConflict $caught) {
                self::assertSame('Registration default Roles are no longer authoritative.', $caught->getMessage());
            }

            self::assertSame([], $fixture->userRepository->all());
            self::assertSame([], $fixture->activationGrantRepository->all());
            self::assertSame([], $fixture->auditEvidenceRepository->all());
            self::assertCount(1, $fixture->eventDispatcher->events());
            // The winner persists; registration rollback cannot undo its independent transaction.
            self::assertNotSame(
                $fixture->member,
                $fixture->roleRepository->getByName(RoleName::fromString('ROLE_MEMBER'))
            );
            self::assertTrue($winner->transactionCompleted);
        }
    }

    public function test_registration_wins_fence_until_complete_and_then_assigned_role_cannot_be_removed(): void
    {
        foreach ([false, true] as $rollback) {
            $fixture = new RegistrationFixture();
            $competitor = new InMemoryUnitOfWork();
            $fixture->unitOfWork->beforeCommit = static function () use ($fixture, $competitor): void {
                self::assertTrue($fixture->unitOfWork->transactionActive);
                self::assertCount(1, $fixture->userRepository->all());
                self::assertCount(1, $fixture->activationGrantRepository->all());
                self::assertCount(1, $fixture->auditEvidenceRepository->all());
                $roleRepository = $fixture->competingRoles($competitor);
                $competitor->commitTransactional(static function () use ($roleRepository, $fixture, $competitor): void {
                    $userRepository = new InMemoryUserRepository(
                        $competitor,
                        state: $fixture->userState,
                        authorizationReferences: $fixture->unitOfWork->authorizationReferenceState()
                    );
                    $current = $fixture->userRepository->all()[0];
                    $replacement = clone $current;
                    $replacement->removeRole($fixture->reader->getId(), new DateTimeImmutable('2030-01-01T00:00:01Z'));
                    self::assertFalse($userRepository->validateRoleAssignmentReference($fixture->member->getId()));
                    self::assertFalse($userRepository->replaceRoleAssignments($current, $replacement));
                    self::assertFalse($roleRepository->remove($fixture->member));
                    self::assertFalse($roleRepository->replace($fixture->member, $fixture->member->renameCustom(
                        RoleName::fromString('ROLE_CHANGED'),
                        new DateTimeImmutable('2030-01-01T00:00:01Z')
                    )));
                    try {
                        $roleRepository->add(Role::define(
                            RoleId::generate(),
                            RoleName::fromString('ROLE_LATE'),
                            [],
                            new DateTimeImmutable('2030-01-01T00:00:01Z')
                        ));
                        self::fail('A Role writer bypassed the held creation fence.');
                    } catch (RuntimeException $runtimeException) {
                        self::assertStringContainsString('fence', $runtimeException->getMessage());
                    }
                });
            };
            $fixture->unitOfWork->failNextCommit = $rollback;
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::assertFalse($rollback);
            } catch (RuntimeException $caught) {
                self::assertTrue($rollback);
                self::assertSame('Injected transaction failure.', $caught->getMessage());
            }

            self::assertSame($fixture->member, $fixture->roleRepository->getById($fixture->member->getId()));
            $roleRepository = $fixture->competingRoles($competitor);
            if ($rollback) {
                self::assertSame([], $fixture->userRepository->all());
                self::assertTrue(
                    $competitor->commitTransactional(fn(): bool => $roleRepository->remove($fixture->member))
                );
            } else {
                self::assertCount(1, $fixture->userRepository->all());
                self::assertCount(2, $fixture->userRepository->all()[0]->getRoleIds());
                self::assertFalse(
                    $competitor->commitTransactional(fn(): bool => $roleRepository->remove($fixture->member))
                );
                // Replacement can serialize after completed registration, not during it.
                self::assertTrue($competitor->commitTransactional(fn(): bool => $roleRepository->replace(
                    $fixture->member,
                    $fixture->member->renameCustom(
                        RoleName::fromString('ROLE_CHANGED'),
                        new DateTimeImmutable('2030-01-01T00:00:01Z')
                    )
                )));
            }
        }
    }

    public function test_competing_canonical_registration_insert_has_one_complete_winner(): void
    {
        $fixture = null;
        $winnerId = null;
        $winnerActivationGrantRepository = null;
        $winnerAuditEvidenceRepository = null;
        $winnerEventDispatcher = new InMemoryEventDispatcher();
        $fixture = new RegistrationFixture(beforeInsert: static function () use (
            &$fixture,
            &$winnerId,
            &$winnerActivationGrantRepository,
            &$winnerAuditEvidenceRepository,
            $winnerEventDispatcher
        ): void {
            self::assertInstanceOf(RegistrationFixture::class, $fixture);
            $other = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository(
                $other,
                state: $fixture->userState,
                authorizationReferences: $fixture->unitOfWork->authorizationReferenceState()
            );
            $winnerActivationGrantRepository = new InMemoryActivationGrantRepository($other);
            $winnerAuditEvidenceRepository = new InMemoryAuditEvidenceRepository($other);
            $command = $fixture->command('ALICE@example.test');
            $winnerId = $command->getUserId();
            new RegisterUserHandler(
                $userRepository,
                $fixture->competingRoles($other),
                $winnerActivationGrantRepository,
                $winnerAuditEvidenceRepository,
                $other,
                new FixedCredentialGenerator('competing-registration-fixture'),
                new PrefixInvitationDeliveryCipher(),
                new FixedClock('2030-01-01T00:00:00Z'),
                $winnerEventDispatcher,
                ['ROLE_READER']
            )->handle(CommandMessage::create($command));
        });
        $loser = $fixture->command();
        $this->expectException(StateConflict::class);
        try {
            $fixture->handler()->handle(CommandMessage::create($loser));
        } finally {
            self::assertCount(1, $fixture->userRepository->all());
            self::assertNull($fixture->userRepository->getById($loser->getUserId()));
            self::assertSame($winnerId, $fixture->userRepository->all()[0]->getId());
            self::assertEquals([$fixture->reader->getId()], $fixture->userRepository->all()[0]->getRoleIds());
            self::assertSame([], $fixture->activationGrantRepository->all());
            self::assertSame([], $fixture->auditEvidenceRepository->all());
            self::assertNotNull($winnerActivationGrantRepository);
            self::assertNotNull($winnerAuditEvidenceRepository);
            self::assertCount(1, $winnerActivationGrantRepository->all());
            self::assertCount(1, $winnerAuditEvidenceRepository->all());
            self::assertCount(1, $winnerEventDispatcher->events());
        }
    }
}
