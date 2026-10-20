<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RegisterUserHandler;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\Common\Domain\Exception\DomainException;
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
        foreach (['remove', 'rename', 'replace', 'recreate'] as $change) {
            $fixture = null;
            $winner = new InMemoryUnitOfWork();
            $fixture = new RegistrationFixture(beforeInsert: static function () use (
                &$fixture,
                $winner,
                $change
            ): void {
                self::assertInstanceOf(RegistrationFixture::class, $fixture);
                $roles = $fixture->competingRoles($winner);
                $winner->commitTransactional(static function () use ($fixture, $roles, $change): void {
                    if ($change === 'remove' || $change === 'recreate') {
                        self::assertTrue($roles->remove($fixture->member));
                        if ($change === 'recreate') {
                            $roles->add(Role::define(
                                RoleId::generate(),
                                $fixture->member->getName(),
                                [],
                                new DateTimeImmutable('2030-01-01T00:00:01Z')
                            ));
                        }
                    } else {
                        self::assertTrue($roles->replace($fixture->member, $fixture->member->renameCustom(
                            RoleName::fromString($change === 'rename' ? 'ROLE_CHANGED' : 'ROLE_MEMBER'),
                            new DateTimeImmutable('2030-01-01T00:00:01Z')
                        )));
                    }
                });
            });
            try {
                $fixture->handler()->handle(CommandMessage::create($fixture->command()));
                self::fail('A stale resolved Role was inserted.');
            } catch (DomainException $caught) {
                self::assertSame('Registration default Roles are no longer authoritative.', $caught->getMessage());
            }

            self::assertSame([], $fixture->users->all());
            self::assertSame([], $fixture->grants->all());
            self::assertSame([], $fixture->audit->all());
            self::assertCount(1, $fixture->events->events());
            // The winner persists; registration rollback cannot undo its independent transaction.
            self::assertNotSame($fixture->member, $fixture->roles->getByName(RoleName::fromString('ROLE_MEMBER')));
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
                self::assertCount(1, $fixture->users->all());
                self::assertCount(1, $fixture->grants->all());
                self::assertCount(1, $fixture->audit->all());
                $roles = $fixture->competingRoles($competitor);
                $competitor->commitTransactional(static function () use ($roles, $fixture, $competitor): void {
                    $users = new InMemoryUserRepository(
                        $competitor,
                        state: $fixture->userState,
                        authorizationReferences: $fixture->unitOfWork->authorizationReferenceState()
                    );
                    $current = $fixture->users->all()[0];
                    $replacement = clone $current;
                    $replacement->removeRole($fixture->reader->getId(), new DateTimeImmutable('2030-01-01T00:00:01Z'));
                    self::assertFalse($users->validateRoleAssignmentReference($fixture->member->getId()));
                    self::assertFalse($users->replaceRoleAssignments($current, $replacement));
                    self::assertFalse($roles->remove($fixture->member));
                    self::assertFalse($roles->replace($fixture->member, $fixture->member->renameCustom(
                        RoleName::fromString('ROLE_CHANGED'),
                        new DateTimeImmutable('2030-01-01T00:00:01Z')
                    )));
                    try {
                        $roles->add(Role::define(
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

            self::assertSame($fixture->member, $fixture->roles->getById($fixture->member->getId()));
            $roles = $fixture->competingRoles($competitor);
            if ($rollback) {
                self::assertSame([], $fixture->users->all());
                self::assertTrue($competitor->commitTransactional(fn(): bool => $roles->remove($fixture->member)));
            } else {
                self::assertCount(1, $fixture->users->all());
                self::assertCount(2, $fixture->users->all()[0]->getRoleIds());
                self::assertFalse($competitor->commitTransactional(fn(): bool => $roles->remove($fixture->member)));
                // Replacement can serialize after completed registration, not during it.
                self::assertTrue($competitor->commitTransactional(fn(): bool => $roles->replace(
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
        $winnerGrants = null;
        $winnerAudit = null;
        $winnerEvents = new InMemoryEventDispatcher();
        $fixture = new RegistrationFixture(beforeInsert: static function () use (
            &$fixture,
            &$winnerId,
            &$winnerGrants,
            &$winnerAudit,
            $winnerEvents
        ): void {
            self::assertInstanceOf(RegistrationFixture::class, $fixture);
            $other = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository(
                $other,
                state: $fixture->userState,
                authorizationReferences: $fixture->unitOfWork->authorizationReferenceState()
            );
            $winnerGrants = new InMemoryActivationGrantRepository($other);
            $winnerAudit = new InMemoryAuditEvidenceRepository($other);
            $command = $fixture->command('ALICE@example.test');
            $winnerId = $command->getUserId();
            new RegisterUserHandler(
                $users,
                $fixture->competingRoles($other),
                $winnerGrants,
                $winnerAudit,
                $other,
                new FixedCredentialGenerator('competing-registration-fixture'),
                new PrefixInvitationDeliveryCipher(),
                new FixedClock('2030-01-01T00:00:00Z'),
                $winnerEvents,
                ['ROLE_READER']
            )->handle(CommandMessage::create($command));
        });
        $loser = $fixture->command();
        $this->expectException(DuplicateEmailException::class);
        try {
            $fixture->handler()->handle(CommandMessage::create($loser));
        } finally {
            self::assertCount(1, $fixture->users->all());
            self::assertNull($fixture->users->getById($loser->getUserId()));
            self::assertSame($winnerId, $fixture->users->all()[0]->getId());
            self::assertEquals([$fixture->reader->getId()], $fixture->users->all()[0]->getRoleIds());
            self::assertSame([], $fixture->grants->all());
            self::assertSame([], $fixture->audit->all());
            self::assertNotNull($winnerGrants);
            self::assertNotNull($winnerAudit);
            self::assertCount(1, $winnerGrants->all());
            self::assertCount(1, $winnerAudit->all());
            self::assertCount(1, $winnerEvents->events());
        }
    }
}
