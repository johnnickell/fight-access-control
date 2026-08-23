<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepositoryState;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class InMemoryUserRepositoryTest extends TestCase
{
    public function test_that_only_the_expected_role_assignments_can_be_replaced(): void
    {
        $repository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $repository->add($current);
        $winner = clone $current;
        $winner->replaceRoleAssignments([RoleId::generate()]);

        $staleCandidate = clone $current;
        $staleCandidate->replaceRoleAssignments([RoleId::generate()]);

        self::assertTrue($repository->replaceRoleAssignments($current, $winner));
        self::assertFalse($repository->replaceRoleAssignments($current, $staleCandidate));
        self::assertSame($winner, $repository->getById($current->getId()));
    }

    public function test_that_role_assignment_replacement_rejects_unrelated_user_changes(): void
    {
        $repository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $repository->add($current);
        $replacement = clone $current;
        $replacement->replaceRoleAssignments([RoleId::generate()]);
        $replacement->rehashPassword($this->passwordHash('unrelated-password-change'));

        self::assertFalse($repository->replaceRoleAssignments($current, $replacement));
        self::assertSame($current, $repository->getById($current->getId()));
    }

    public function test_that_role_assignment_replacement_requires_exactly_one_revision_advance(): void
    {
        $repository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $repository->add($current);
        $replacement = clone $current;
        $replacement->replaceRoleAssignments([RoleId::generate(), RoleId::generate()]);
        $replacement->replaceRoleAssignments([RoleId::generate()]);

        self::assertFalse($repository->replaceRoleAssignments($current, $replacement));
        self::assertSame($current, $repository->getById($current->getId()));
    }

    public function test_that_authentication_replacement_preserves_role_assignments(): void
    {
        $repository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $roleId = RoleId::generate();
        $current->replaceRoleAssignments([$roleId]);
        $repository->add($current);
        $replacement = clone $current;
        $replacement->rehashPassword($this->passwordHash('replacement-password'));
        $replacement->advanceAuthenticationAuthorityRevision();

        self::assertTrue($repository->replaceAuthenticationAuthority($current, $replacement));
        self::assertSame([$roleId], $replacement->getRoleIds());
        self::assertSame(
            $current->getAuthorizationAssignmentRevision(),
            $replacement->getAuthorizationAssignmentRevision()
        );
    }

    public function test_that_authentication_replacement_rejects_role_assignment_changes(): void
    {
        $repository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $repository->add($current);
        $replacement = clone $current;
        $replacement->replaceRoleAssignments([RoleId::generate()]);
        $replacement->advanceAuthenticationAuthorityRevision();

        self::assertFalse($repository->replaceAuthenticationAuthority($current, $replacement));
        self::assertSame($current, $repository->getById($current->getId()));
    }

    public function test_that_atomic_login_authority_and_session_win_before_a_stale_reset_or_both_lose(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUsers = new InMemoryUserRepository(state: $userState);
        $current = $this->activeUser();
        $seedUsers->add($current);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginSessions = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginUsers = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginUsers->bindRefreshSessionRepository($loginSessions);

        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUsers = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $loginExpected = $loginUsers->getById($current->getId());
        $resetExpected = $resetUsers->getById($current->getId());
        self::assertInstanceOf(User::class, $loginExpected);
        self::assertInstanceOf(User::class, $resetExpected);
        $loginReplacement = clone $loginExpected;
        $loginReplacement->advanceAuthenticationAuthorityRevision();

        $session = $this->session($loginReplacement);
        $resetReplacement = clone $resetExpected;
        $resetReplacement->resetPassword($this->passwordHash('reset-password'));
        $resetReplacement->advanceAuthenticationAuthorityRevision();

        self::assertTrue($loginUnitOfWork->commitTransactional(
            static fn(): bool => $loginUsers->replaceAuthenticationAuthorityAndAddRefreshSession(
                $loginExpected,
                $loginReplacement,
                $session
            )
        ));
        self::assertFalse($resetUnitOfWork->commitTransactional(
            static fn(): bool => $resetUsers->replaceAuthenticationAuthority($resetExpected, $resetReplacement)
        ));

        self::assertSame($loginReplacement, $resetUsers->getById($current->getId()));
        self::assertSame([$session], $loginSessions->all());
    }

    public function test_that_reset_authority_wins_before_a_stale_atomic_login_or_both_lose(): void
    {
        $userState = new InMemoryUserRepositoryState();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $seedUsers = new InMemoryUserRepository(state: $userState);
        $current = $this->activeUser();
        $seedUsers->add($current);
        $resetUnitOfWork = new InMemoryUnitOfWork();
        $resetUsers = new InMemoryUserRepository($resetUnitOfWork, state: $userState);
        $loginUnitOfWork = new InMemoryUnitOfWork();
        $loginSessions = new InMemoryRefreshSessionRepository($loginUnitOfWork, state: $sessionState);
        $loginUsers = new InMemoryUserRepository($loginUnitOfWork, state: $userState);
        $loginUsers->bindRefreshSessionRepository($loginSessions);

        $resetExpected = $resetUsers->getById($current->getId());
        $loginExpected = $loginUsers->getById($current->getId());
        self::assertInstanceOf(User::class, $resetExpected);
        self::assertInstanceOf(User::class, $loginExpected);
        $resetReplacement = clone $resetExpected;
        $resetReplacement->resetPassword($this->passwordHash('reset-password'));
        $resetReplacement->advanceAuthenticationAuthorityRevision();

        $loginReplacement = clone $loginExpected;
        $loginReplacement->advanceAuthenticationAuthorityRevision();

        $session = $this->session($loginReplacement);

        self::assertTrue($resetUnitOfWork->commitTransactional(
            static fn(): bool => $resetUsers->replaceAuthenticationAuthority($resetExpected, $resetReplacement)
        ));
        self::assertFalse($loginUnitOfWork->commitTransactional(
            static fn(): bool => $loginUsers->replaceAuthenticationAuthorityAndAddRefreshSession(
                $loginExpected,
                $loginReplacement,
                $session
            )
        ));

        self::assertSame($resetReplacement, $loginUsers->getById($current->getId()));
        self::assertSame([], $loginSessions->all());
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
        $outerReplacement->advanceAuthenticationAuthorityRevision();

        $competingReplacement = clone $outerReplacement;
        $competingReplacement->advanceAuthenticationAuthorityRevision();

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
        $winnerReplacement->rehashPassword($this->passwordHash('winner-password'));
        $winnerReplacement->advanceAuthenticationAuthorityRevision();

        self::assertTrue($winnerUnitOfWork->commitTransactional(
            static fn(): bool => $winnerRepository->replaceAuthenticationAuthority(
                $winnerExpected,
                $winnerReplacement
            )
        ));
        self::assertSame($winnerReplacement, $outerRepository->getById($current->getId()));
        self::assertSame(1, $winnerReplacement->getAuthenticationAuthorityRevision());
    }

    public function test_that_atomic_authentication_authority_and_session_roll_back_together(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $sessionState = new InMemoryRefreshSessionRepositoryState();
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork, state: $sessionState);
        $repository = new InMemoryUserRepository($unitOfWork);
        $repository->bindRefreshSessionRepository($sessions);

        $current = $this->activeUser();
        $repository->add($current);
        $replacement = clone $current;
        $replacement->advanceAuthenticationAuthorityRevision();

        $session = $this->session($replacement);

        try {
            $unitOfWork->commitTransactional(function () use (
                $current,
                $replacement,
                $repository,
                $session
            ): void {
                self::assertTrue($repository->replaceAuthenticationAuthorityAndAddRefreshSession(
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

        self::assertSame($current, $repository->getById($current->getId()));
        self::assertSame([], $sessions->all());
    }

    public function test_that_only_the_expected_authentication_authority_can_be_replaced(): void
    {
        $repository = new InMemoryUserRepository();
        $current = $this->activeUser();
        $repository->add($current);
        $winner = clone $current;
        $winner->resetPassword($this->passwordHash('winner-password'));
        $winner->advanceAuthenticationAuthorityRevision();

        $staleCandidate = clone $current;
        $staleCandidate->rehashPassword($this->passwordHash('stale-password'));
        $staleCandidate->advanceAuthenticationAuthorityRevision();

        self::assertTrue($repository->replaceAuthenticationAuthority($current, $winner));
        self::assertFalse($repository->replaceAuthenticationAuthority($current, $staleCandidate));
        self::assertSame($winner, $repository->getById($current->getId()));
        self::assertSame(2, $winner->getAuthenticationVersion());
        self::assertTrue(password_verify(
            'winner-password',
            $winner->getPasswordHash()?->toString() ?? ''
        ));
    }

    public function test_that_authentication_authority_replacement_rolls_back_with_its_unit_of_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryUserRepository($unitOfWork);
        $current = $this->activeUser();
        $repository->add($current);
        $replacement = clone $current;
        $replacement->rehashPassword($this->passwordHash('replacement-password'));
        $replacement->advanceAuthenticationAuthorityRevision();

        try {
            $unitOfWork->commitTransactional(function () use ($current, $replacement, $repository): void {
                self::assertTrue($repository->replaceAuthenticationAuthority($current, $replacement));
                throw new RuntimeException('Rollback requested.');
            });
            self::fail('Expected rollback request.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Rollback requested.', $runtimeException->getMessage());
        }

        self::assertSame($current, $repository->getById($current->getId()));
        self::assertTrue(password_verify(
            'correct-secret',
            $current->getPasswordHash()?->toString() ?? ''
        ));
    }

    private function activeUser(): User
    {
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('authority@example.test')
        );
        $user->activate($this->passwordHash('correct-secret'));

        return $user;
    }

    private function passwordHash(string $password): PasswordHash
    {
        return PasswordHash::fromString(password_hash($password, PASSWORD_DEFAULT));
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
