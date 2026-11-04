<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\Role\Repository\InMemoryRoleRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class InMemoryUserRepositoryTest extends TestCase
{
    public function test_stale_authentication_replacement_cannot_erase_later_email_state(): void
    {
        foreach (['reservation', 'canonical correction'] as $emailTransition) {
            $userRepository = new InMemoryUserRepository();
            $current = $this->userBeforeEmailTransition($emailTransition);
            $userRepository->add($current);
            $staleExpected = clone $current;
            $staleReplacement = clone $staleExpected;
            if ($emailTransition === 'reservation') {
                $staleReplacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                $winner = clone $current;
                $winner->requestEmailChange(
                    EmailAddress::fromString('reserved@example.test'),
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                );
                self::assertTrue($userRepository->replaceEmailChangeReservation($current, $winner));
            } else {
                $staleReplacement->activate(
                    $this->passwordHash('stale-activation-password'),
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                );
                $winner = clone $current;
                $winner->correctPendingInvitationEmail(
                    EmailAddress::fromString('corrected@example.test'),
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                );
                self::assertTrue($userRepository->replacePendingInvitationEmail($current, $winner));
            }

            self::assertFalse($userRepository->replaceAuthenticationAuthority($staleExpected, $staleReplacement));
            self::assertSame($winner, $userRepository->getById($current->getId()));
            self::assertSame(
                $emailTransition === 'reservation' ? 'authority@example.test' : 'corrected@example.test',
                $userRepository->getById($current->getId())->getEmail()->canonical()
            );
            self::assertSame(
                $emailTransition === 'reservation' ? 'reserved@example.test' : null,
                $userRepository->getById($current->getId())->getPendingEmailChange()?->canonical()
            );
        }
    }

    public function test_stale_coupled_authentication_and_session_replacement_cannot_erase_a_reservation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $userRepository->bindRefreshSessionRepository($refreshSessionRepository);

        $current = $this->activeUser();
        $userRepository->add($current);
        $staleExpected = clone $current;
        $staleReplacement = clone $staleExpected;
        $staleReplacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $session = $this->session($staleReplacement);
        $winner = clone $current;
        $winner->requestEmailChange(
            EmailAddress::fromString('reserved@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        self::assertTrue($userRepository->replaceEmailChangeReservation($current, $winner));

        self::assertFalse($userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
            $staleExpected,
            $staleReplacement,
            $session
        ));
        self::assertSame($winner, $userRepository->getById($current->getId()));
        self::assertSame('reserved@example.test', $winner->getPendingEmailChange()?->canonical());
        self::assertSame([], $refreshSessionRepository->all());
    }

    public function test_stale_role_replacement_cannot_erase_later_email_state(): void
    {
        foreach (['reservation', 'canonical correction'] as $emailTransition) {
            $userRepository = new InMemoryUserRepository();
            $current = $this->userBeforeEmailTransition($emailTransition);
            $userRepository->add($current);
            $staleExpected = clone $current;
            $staleReplacement = clone $staleExpected;
            $staleReplacement->replaceRoleAssignments(
                [RoleId::generate()],
                new DateTimeImmutable('2026-01-01T00:00:00+00:00')
            );
            if ($emailTransition === 'reservation') {
                $winner = clone $current;
                $winner->requestEmailChange(
                    EmailAddress::fromString('reserved@example.test'),
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                );
                self::assertTrue($userRepository->replaceEmailChangeReservation($current, $winner));
            } else {
                $winner = clone $current;
                $winner->correctPendingInvitationEmail(
                    EmailAddress::fromString('corrected@example.test'),
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                );
                self::assertTrue($userRepository->replacePendingInvitationEmail($current, $winner));
            }

            self::assertFalse($userRepository->replaceRoleAssignments($staleExpected, $staleReplacement));
            self::assertSame($winner, $userRepository->getById($current->getId()));
            self::assertSame(
                $emailTransition === 'reservation' ? 1 : 0,
                $userRepository->getById($current->getId())->getEmailChangeReservationRevision()
            );
            self::assertSame(
                $emailTransition === 'reservation' ? 0 : 1,
                $userRepository->getById($current->getId())->getCanonicalEmailRevision()
            );
        }
    }

    public function test_preexisting_replacements_cannot_mutate_email_state_they_do_not_own(): void
    {
        foreach (['authentication', 'role'] as $replacementType) {
            foreach (['reservation', 'canonical correction'] as $emailTransition) {
                $userRepository = new InMemoryUserRepository();
                $current = $this->userBeforeEmailTransition($emailTransition);
                $userRepository->add($current);
                $replacement = clone $current;
                if ($emailTransition === 'reservation') {
                    $replacement->requestEmailChange(
                        EmailAddress::fromString('reserved@example.test'),
                        new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                    );
                } else {
                    $replacement->correctPendingInvitationEmail(
                        EmailAddress::fromString('corrected@example.test'),
                        new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                    );
                }

                if ($replacementType === 'authentication') {
                    if ($emailTransition === 'canonical correction') {
                        $replacement->activate(
                            $this->passwordHash('replacement-password'),
                            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                        );
                    } else {
                        $replacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                    }

                    $result = $userRepository->replaceAuthenticationAuthority($current, $replacement);
                } else {
                    $replacement->replaceRoleAssignments(
                        [RoleId::generate()],
                        new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                    );
                    $result = $userRepository->replaceRoleAssignments($current, $replacement);
                }

                self::assertFalse($result);
                self::assertSame($current, $userRepository->getById($current->getId()));
                self::assertSame(
                    $emailTransition === 'reservation' ? 'authority@example.test' : 'invited@example.test',
                    $userRepository->getById($current->getId())->getEmail()->canonical()
                );
                self::assertNull($userRepository->getById($current->getId())->getPendingEmailChange());
            }
        }
    }

    public function test_a_live_email_change_reservation_blocks_a_new_canonical_identity(): void
    {
        $userRepository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $replacement = clone $current;
        $replacement->requestEmailChange(
            EmailAddress::fromString('reserved@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $userRepository->add($current);
        self::assertTrue($userRepository->replaceEmailChangeReservation($current, $replacement));

        $this->expectException(DuplicateEmailException::class);
        $userRepository->add(User::invite(
            UserId::generate(),
            EmailAddress::fromString('reserved@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        ));
    }

    public function test_a_stale_email_change_reservation_replacement_loses_without_mutation(): void
    {
        $userRepository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $userRepository->add($current);
        $winner = clone $current;
        $winner->requestEmailChange(
            EmailAddress::fromString('winner@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $stale = clone $current;
        $stale->requestEmailChange(
            EmailAddress::fromString('stale@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        self::assertTrue($userRepository->replaceEmailChangeReservation($current, $winner));
        self::assertFalse($userRepository->replaceEmailChangeReservation($current, $stale));
        self::assertSame($winner, $userRepository->getById($current->getId()));
    }

    public function test_cancelling_a_reservation_releases_only_its_destination(): void
    {
        $userRepository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $userRepository->add($current);
        $reserved = clone $current;
        $reserved->requestEmailChange(
            EmailAddress::fromString('released@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        self::assertTrue($userRepository->replaceEmailChangeReservation($current, $reserved));
        $cancelled = clone $reserved;
        $cancelled->cancelEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertTrue($userRepository->replaceEmailChangeReservation($reserved, $cancelled));
        $replacementIdentity = User::invite(
            UserId::generate(),
            EmailAddress::fromString('released@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($replacementIdentity);

        self::assertSame($replacementIdentity, $userRepository->getByEmail(
            EmailAddress::fromString('released@example.test')
        ));
        self::assertSame(
            'authority@example.test',
            $userRepository->getById($current->getId())?->getEmail()->canonical()
        );
    }

    public function test_that_only_the_expected_role_assignments_can_be_replaced(): void
    {
        $authorizationReferences = new InMemoryAuthorizationReferenceState();
        $userRepository = new InMemoryUserRepository(authorizationReferences: $authorizationReferences);
        $roleRepository = new InMemoryRoleRepository(authorizationReferences: $authorizationReferences);
        $current = $this->activeUser();
        $userRepository->add($current);
        $winnerRoleId = RoleId::generate();
        $roleRepository->add(Role::define(
            $winnerRoleId,
            RoleName::fromString('ROLE_WINNER'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        ));
        $winner = clone $current;
        $winner->replaceRoleAssignments([$winnerRoleId], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $staleCandidate = clone $current;
        $staleCandidate->replaceRoleAssignments(
            [RoleId::generate()],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        self::assertTrue($userRepository->replaceRoleAssignments($current, $winner));
        self::assertFalse($userRepository->replaceRoleAssignments($current, $staleCandidate));
        self::assertSame($winner, $userRepository->getById($current->getId()));
    }

    public function test_that_role_assignment_replacement_rejects_unrelated_user_changes(): void
    {
        $userRepository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $userRepository->add($current);
        $replacement = clone $current;
        $replacement->replaceRoleAssignments([RoleId::generate()], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $replacement->recordSuccessfulLogin(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $this->passwordHash('unrelated-password-change')
        );

        self::assertFalse($userRepository->replaceRoleAssignments($current, $replacement));
        self::assertSame($current, $userRepository->getById($current->getId()));
    }

    public function test_that_role_assignment_replacement_requires_exactly_one_revision_advance(): void
    {
        $userRepository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $userRepository->add($current);
        $replacement = clone $current;
        $replacement->replaceRoleAssignments(
            [RoleId::generate(), RoleId::generate()],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $replacement->replaceRoleAssignments([RoleId::generate()], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertFalse($userRepository->replaceRoleAssignments($current, $replacement));
        self::assertSame($current, $userRepository->getById($current->getId()));
    }

    public function test_that_repositories_reject_missing_and_double_login_transitions_without_partial_writes(): void
    {
        foreach ([0, 2] as $transitions) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
            $userRepository->bindRefreshSessionRepository($refreshSessionRepository);
            $user = $this->activeUser();
            $userRepository->add($user);
            $replacement = clone $user;
            for ($index = 0; $index < $transitions; ++$index) {
                $replacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01Z'));
            }

            self::assertFalse($userRepository->replaceAuthenticationAuthority($user, $replacement));
            self::assertFalse($userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                $user,
                $replacement,
                $this->session($replacement)
            ));
            self::assertSame($user, $userRepository->getById($user->getId()));
            self::assertSame([], $refreshSessionRepository->all());
        }
    }

    public function test_that_authentication_replacement_preserves_role_assignments(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $current = $this->activeUser();
        $roleId = RoleId::generate();
        new InMemoryRoleRepository($unitOfWork)->add(Role::define(
            $roleId,
            RoleName::fromString('ROLE_FIXTURE'),
            [],
            $current->getCreatedAt()
        ));
        $current->replaceRoleAssignments([$roleId], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $userRepository->add($current);
        $replacement = clone $current;
        $replacement->recordSuccessfulLogin(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $this->passwordHash('replacement-password')
        );

        self::assertTrue($userRepository->replaceAuthenticationAuthority($current, $replacement));
        self::assertSame([$roleId], $replacement->getRoleIds());
        self::assertSame(
            $current->getAuthorizationAssignmentRevision(),
            $replacement->getAuthorizationAssignmentRevision()
        );
    }

    public function test_that_authentication_replacement_rejects_role_assignment_changes(): void
    {
        $userRepository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $userRepository->add($current);
        $replacement = clone $current;
        $replacement->replaceRoleAssignments([RoleId::generate()], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $replacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertFalse($userRepository->replaceAuthenticationAuthority($current, $replacement));
        self::assertSame($current, $userRepository->getById($current->getId()));
    }

    public function test_that_atomic_login_authority_and_session_win_before_a_stale_reset_or_both_lose(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUserRepository = new InMemoryUserRepository(state: $userState);
        $current = $this->activeUser();
        $seedUserRepository->add($current);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginRefreshSessionRepository = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginUserRepository = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginUserRepository->bindRefreshSessionRepository($loginRefreshSessionRepository);

        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUserRepository = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $loginExpected = $loginUserRepository->getById($current->getId());
        $resetExpected = $resetUserRepository->getById($current->getId());
        self::assertInstanceOf(User::class, $loginExpected);
        self::assertInstanceOf(User::class, $resetExpected);
        $loginReplacement = clone $loginExpected;
        $loginReplacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $session = $this->session($loginReplacement);
        $resetReplacement = clone $resetExpected;
        $resetReplacement->resetPassword(
            $this->passwordHash('reset-password'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        self::assertTrue($loginUnitOfWork->commitTransactional(
            static fn(): bool => $loginUserRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                $loginExpected,
                $loginReplacement,
                $session
            )
        ));
        self::assertFalse($resetUnitOfWork->commitTransactional(
            static fn(): bool => $resetUserRepository->replaceAuthenticationAuthority($resetExpected, $resetReplacement)
        ));

        self::assertSame($loginReplacement, $resetUserRepository->getById($current->getId()));
        self::assertSame([$session], $loginRefreshSessionRepository->all());
    }

    public function test_that_reset_authority_wins_before_a_stale_atomic_login_or_both_lose(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUserRepository = new InMemoryUserRepository(state: $userState);
        $current = $this->activeUser();
        $seedUserRepository->add($current);
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUserRepository = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginRefreshSessionRepository = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginUserRepository = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginUserRepository->bindRefreshSessionRepository($loginRefreshSessionRepository);

        $resetExpected = $resetUserRepository->getById($current->getId());
        $loginExpected = $loginUserRepository->getById($current->getId());
        self::assertInstanceOf(User::class, $resetExpected);
        self::assertInstanceOf(User::class, $loginExpected);
        $resetReplacement = clone $resetExpected;
        $resetReplacement->resetPassword(
            $this->passwordHash('reset-password'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $loginReplacement = clone $loginExpected;
        $loginReplacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $session = $this->session($loginReplacement);

        self::assertTrue($resetUnitOfWork->commitTransactional(
            static fn(): bool => $resetUserRepository->replaceAuthenticationAuthority($resetExpected, $resetReplacement)
        ));
        self::assertFalse($loginUnitOfWork->commitTransactional(
            static fn(): bool => $loginUserRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                $loginExpected,
                $loginReplacement,
                $session
            )
        ));

        self::assertSame($resetReplacement, $loginUserRepository->getById($current->getId()));
        self::assertSame([], $loginRefreshSessionRepository->all());
    }

    public function test_that_competing_authority_retries_only_after_fence_owner_rolls_back(): void
    {
        $state = new InMemoryUserRepositoryState();
        $outerUnitOfWork = new InMemoryUnitOfWork();
        $outerRepository = new InMemoryUserRepository($outerUnitOfWork, state: $state);
        $winnerUnitOfWork = new InMemoryUnitOfWork();
        $winnerRepository = new InMemoryUserRepository($winnerUnitOfWork, state: $state);
        $current = $this->activeUser();
        $outerRepository->add($current);
        $outerReplacement = clone $current;
        $outerReplacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $competingReplacement = clone $outerReplacement;
        $competingReplacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        try {
            $outerUnitOfWork->commitTransactional(function () use (
                $competingReplacement,
                $current,
                $outerReplacement,
                $outerRepository,
                $winnerRepository,
                $winnerUnitOfWork
            ): void {
                self::assertTrue($outerRepository->replaceAuthenticationAuthority($current, $outerReplacement));
                self::assertFalse($winnerUnitOfWork->commitTransactional(
                    static fn(): bool => $winnerRepository->replaceAuthenticationAuthority(
                        $outerReplacement,
                        $competingReplacement
                    )
                ));

                throw new RuntimeException('Outer transaction loses.');
            });
            self::fail('Expected outer transaction rollback.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Outer transaction loses.', $runtimeException->getMessage());
        }

        $winnerExpected = $winnerRepository->getById($current->getId());
        self::assertInstanceOf(User::class, $winnerExpected);
        $winnerReplacement = clone $winnerExpected;
        $winnerReplacement->recordSuccessfulLogin(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $this->passwordHash('winner-password')
        );

        self::assertTrue($winnerUnitOfWork->commitTransactional(
            static fn(): bool => $winnerRepository->replaceAuthenticationAuthority(
                $winnerExpected,
                $winnerReplacement
            )
        ));
        self::assertSame($winnerReplacement, $outerRepository->getById($current->getId()));
        self::assertSame(
            $current->getAuthenticationAuthorityRevision() + 1,
            $winnerReplacement->getAuthenticationAuthorityRevision()
        );
    }

    public function test_that_atomic_authentication_authority_and_session_roll_back_together(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork, state: $sessionState);
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $userRepository->bindRefreshSessionRepository($refreshSessionRepository);

        $current = $this->activeUser();
        $userRepository->add($current);
        $replacement = clone $current;
        $replacement->recordSuccessfulLogin(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $session = $this->session($replacement);

        try {
            $unitOfWork->commitTransactional(function () use (
                $current,
                $replacement,
                $userRepository,
                $session
            ): void {
                self::assertTrue($userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                    $current,
                    $replacement,
                    $session
                ));

                throw new RuntimeException('Rollback requested after atomic persistence.');
            });
            self::fail('Expected rollback request.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Rollback requested after atomic persistence.', $runtimeException->getMessage());
        }

        self::assertSame($current, $userRepository->getById($current->getId()));
        self::assertSame([], $refreshSessionRepository->all());
    }

    public function test_that_only_the_expected_authentication_authority_can_be_replaced(): void
    {
        $userRepository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $userRepository->add($current);
        $winner = clone $current;
        $winner->resetPassword(
            $this->passwordHash('winner-password'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $staleCandidate = clone $current;
        $staleCandidate->recordSuccessfulLogin(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $this->passwordHash('stale-password')
        );

        self::assertTrue($userRepository->replaceAuthenticationAuthority($current, $winner));
        self::assertFalse($userRepository->replaceAuthenticationAuthority($current, $staleCandidate));
        self::assertSame($winner, $userRepository->getById($current->getId()));
        self::assertSame(2, $winner->getAuthenticationVersion());
        self::assertTrue(password_verify(
            'winner-password',
            $winner->getPasswordHash()?->toString() ?? ''
        ));
    }

    public function test_that_authentication_authority_replacement_rolls_back_with_its_unit_of_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $current = $this->activeUser();
        $userRepository->add($current);
        $replacement = clone $current;
        $replacement->recordSuccessfulLogin(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $this->passwordHash('replacement-password')
        );

        try {
            $unitOfWork->commitTransactional(function () use ($current, $replacement, $userRepository): void {
                self::assertTrue($userRepository->replaceAuthenticationAuthority($current, $replacement));
                throw new RuntimeException('Rollback requested.');
            });
            self::fail('Expected rollback request.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Rollback requested.', $runtimeException->getMessage());
        }

        self::assertSame($current, $userRepository->getById($current->getId()));
        self::assertTrue(password_verify(
            'correct-secret',
            $current->getPasswordHash()?->toString() ?? ''
        ));
    }

    private function activeUser(): User
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('authority@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $user->activate($this->passwordHash('correct-secret'), new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        return $user;
    }

    private function passwordHash(string $password): PasswordHash
    {
        return PasswordHash::fromString(password_hash($password, PASSWORD_DEFAULT));
    }

    private function userBeforeEmailTransition(string $emailTransition): User
    {
        if ($emailTransition === 'reservation') {
            return $this->activeUser();
        }

        return User::invite(
            UserId::generate(),
            EmailAddress::fromString('invited@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
    }

    private function session(User $user): RefreshSession
    {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $user->getId(),
            RefreshCredential::fromString(
                '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
            ),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T11:00:00+00:00'),
            $user->getAuthenticationVersion(),
            false
        );
    }
}
