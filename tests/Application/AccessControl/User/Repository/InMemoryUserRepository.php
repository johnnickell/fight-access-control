<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Closure;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\User\Exception\DuplicateEmailException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use stdClass;

final class InMemoryUserRepository implements UserRepository
{
    private readonly InMemoryUserRepositoryState $state;

    private ?InMemoryRefreshSessionRepository $refreshSessionRepository = null;

    private readonly object $authenticationAuthorityFenceOwner;

    /** @var array<string, true> */
    private array $registeredAuthenticationAuthorityFenceReleases = [];

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly ?Closure $beforeGetByEmail = null,
        private readonly bool $replaceAuthenticationAuthoritySucceeds = true,
        ?InMemoryUserRepositoryState $state = null,
        private readonly ?Closure $afterReplaceAuthenticationAuthority = null
    ) {
        $this->state = $state ?? new InMemoryUserRepositoryState();
        $this->authenticationAuthorityFenceOwner = new stdClass();
    }

    public function add(User $user): void
    {
        if (
            array_any(
                $this->state->users,
                static fn(User $reserved): bool => $reserved->getEmail()->canonical() === $user->getEmail()->canonical()
            )
        ) {
            throw new DuplicateEmailException('The email address is already reserved.');
        }

        $this->state->users[] = $user;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->state->users);
        });
    }

    public function getById(UserId $id): ?User
    {
        foreach ($this->state->users as $user) {
            if ($user->getId()->equals($id)) {
                return $user;
            }
        }

        return null;
    }

    public function getByEmail(EmailAddress $email): ?User
    {
        $this->beforeGetByEmail?->__invoke();

        foreach ($this->state->users as $user) {
            if ($user->getEmail()->canonical() === $email->canonical()) {
                return $user;
            }
        }

        return null;
    }

    public function replaceAuthenticationAuthority(User $expected, User $replacement): bool
    {
        if (!$this->acquireAuthenticationAuthorityFence($expected)) {
            return false;
        }

        $index = $this->replacementIndex($expected, $replacement);
        if ($index === null) {
            $this->releaseAuthenticationAuthorityFence($expected->getId());

            return false;
        }

        $releaseAfterOperation = $this->holdAuthenticationAuthorityFenceThroughCompletion($expected->getId());
        try {
            $this->replaceAt($index, $replacement);
            $this->afterReplaceAuthenticationAuthority?->__invoke();
        } finally {
            if ($releaseAfterOperation) {
                $this->releaseAuthenticationAuthorityFence($expected->getId());
            }
        }

        return true;
    }

    public function replaceAuthenticationAuthorityAndAddRefreshSession(
        User $expected,
        User $replacement,
        RefreshSession $refreshSession
    ): bool {
        if (!$this->acquireAuthenticationAuthorityFence($expected)) {
            return false;
        }

        $index = $this->replacementIndex($expected, $replacement);
        if (
            $index === null
            || !$this->refreshSessionRepository instanceof InMemoryRefreshSessionRepository
            || !$refreshSession->getUserId()->equals($replacement->getId())
            || $refreshSession->getAuthenticationVersion() !== $replacement->getAuthenticationVersion()
        ) {
            $this->releaseAuthenticationAuthorityFence($expected->getId());

            return false;
        }

        $releaseAfterOperation = $this->holdAuthenticationAuthorityFenceThroughCompletion($expected->getId());
        try {
            $this->replaceAt($index, $replacement);
            $this->refreshSessionRepository->addAsPartOfAuthenticationAuthorityReplacement($refreshSession);
        } finally {
            if ($releaseAfterOperation) {
                $this->releaseAuthenticationAuthorityFence($expected->getId());
            }
        }

        return true;
    }

    public function replaceRoleAssignments(User $expected, User $replacement): bool
    {
        $index = $this->roleAssignmentReplacementIndex($expected, $replacement);
        if ($index === null) {
            return false;
        }

        $this->replaceAt($index, $replacement);

        return true;
    }

    public function bindRefreshSessionRepository(InMemoryRefreshSessionRepository $refreshSessionRepository): void
    {
        $this->refreshSessionRepository = $refreshSessionRepository;
    }

    /** @return list<User> */
    public function all(): array
    {
        return $this->state->users;
    }

    private function passwordHashesMatch(?PasswordHash $left, ?PasswordHash $right): bool
    {
        if (!$left instanceof PasswordHash || !$right instanceof PasswordHash) {
            return $left === $right;
        }

        return hash_equals($left->toString(), $right->toString());
    }

    private function replacementIsValid(User $expected, User $replacement): bool
    {
        $stateIsValid = $replacement->getState() === $expected->getState()
            || (
                $expected->getState() === UserState::PENDING_ACTIVATION
                && $replacement->getState() === UserState::ACTIVE
            );
        $authenticationVersionIsValid = (
            $replacement->getAuthenticationVersion() === $expected->getAuthenticationVersion()
            || $replacement->getAuthenticationVersion() === $expected->getAuthenticationVersion() + 1
        );
        $replacementRevision = $replacement->getAuthenticationAuthorityRevision();
        $expectedRevision = $expected->getAuthenticationAuthorityRevision();
        $authenticationAuthorityRevisionIsValid = $replacementRevision === $expectedRevision + 1;

        return $stateIsValid
            && $authenticationVersionIsValid
            && $authenticationAuthorityRevisionIsValid
            && $replacement->getAuthorizationAssignmentRevision() === $expected->getAuthorizationAssignmentRevision()
            && $this->roleAssignmentsMatch($replacement, $expected)
            && $replacement->getPasswordHash() instanceof PasswordHash;
    }

    private function replacementIndex(User $expected, User $replacement): ?int
    {
        if (!$this->replaceAuthenticationAuthoritySucceeds) {
            return null;
        }

        foreach ($this->state->users as $index => $user) {
            if (
                $user->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $replacement->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $user->getState() === $expected->getState()
                && $user->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $user->getAuthenticationAuthorityRevision() === $expected->getAuthenticationAuthorityRevision()
                && $user->getAuthorizationAssignmentRevision() === $expected->getAuthorizationAssignmentRevision()
                && $this->passwordHashesMatch($user->getPasswordHash(), $expected->getPasswordHash())
                && $this->roleAssignmentsMatch($user, $expected)
                && $this->replacementIsValid($expected, $replacement)
            ) {
                return $index;
            }
        }

        return null;
    }

    private function roleAssignmentReplacementIndex(User $expected, User $replacement): ?int
    {
        $expectedAuthenticationAuthorityRevision = $expected->getAuthenticationAuthorityRevision();
        $expectedAuthorizationAssignmentRevision = $expected->getAuthorizationAssignmentRevision();

        foreach ($this->state->users as $index => $user) {
            if (
                $user->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $user->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $replacement->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $user->getState() === $expected->getState()
                && $replacement->getState() === $expected->getState()
                && $user->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $replacement->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $user->getAuthenticationAuthorityRevision() === $expectedAuthenticationAuthorityRevision
                && $replacement->getAuthenticationAuthorityRevision() === $expectedAuthenticationAuthorityRevision
                && $user->getAuthorizationAssignmentRevision() === $expectedAuthorizationAssignmentRevision
                && $replacement->getAuthorizationAssignmentRevision() === $expectedAuthorizationAssignmentRevision + 1
                && $this->passwordHashesMatch($user->getPasswordHash(), $expected->getPasswordHash())
                && $this->passwordHashesMatch($replacement->getPasswordHash(), $expected->getPasswordHash())
                && $this->roleAssignmentsMatch($user, $expected)
                && !$this->roleAssignmentsMatch($replacement, $expected)
            ) {
                return $index;
            }
        }

        return null;
    }

    private function roleAssignmentsMatch(User $left, User $right): bool
    {
        $leftRoleIds = $left->getRoleIds();
        if (count($leftRoleIds) !== count($right->getRoleIds())) {
            return false;
        }

        return array_all(
            $leftRoleIds,
            static fn(RoleId $roleId): bool => $right->hasRole($roleId)
        );
    }

    private function replaceAt(int $index, User $replacement): void
    {
        $user = $this->state->users[$index];
        $this->state->users[$index] = $replacement;
        $this->unitOfWork?->onRollback(function () use ($index, $replacement, $user): void {
            if (($this->state->users[$index] ?? null) === $replacement) {
                $this->state->users[$index] = $user;
            }
        });
    }

    private function acquireAuthenticationAuthorityFence(User $expected): bool
    {
        return $this->state->acquireAuthenticationAuthorityFence(
            $expected->getId(),
            $this->authenticationAuthorityFenceOwner
        );
    }

    private function holdAuthenticationAuthorityFenceThroughCompletion(UserId $userId): bool
    {
        if (
            !$this->unitOfWork instanceof InMemoryUnitOfWork
            || !$this->unitOfWork->transactionActive
        ) {
            return true;
        }

        $key = $userId->toString();
        if (!isset($this->registeredAuthenticationAuthorityFenceReleases[$key])) {
            $this->registeredAuthenticationAuthorityFenceReleases[$key] = true;
            $this->unitOfWork->onCompletion(function () use ($key, $userId): void {
                $this->releaseAuthenticationAuthorityFence($userId);
                unset($this->registeredAuthenticationAuthorityFenceReleases[$key]);
            });
        }

        return false;
    }

    private function releaseAuthenticationAuthorityFence(UserId $userId): void
    {
        $this->state->releaseAuthenticationAuthorityFence(
            $userId,
            $this->authenticationAuthorityFenceOwner
        );
    }
}
