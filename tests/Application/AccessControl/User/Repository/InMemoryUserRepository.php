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
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
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
        private readonly ?Closure $afterReplaceAuthenticationAuthority = null,
        private readonly bool $replaceEmailChangeReservationSucceeds = true,
        private readonly bool $replacePendingInvitationEmailSucceeds = true,
        private readonly bool $replaceEmailChangeConfirmationSucceeds = true,
        private readonly bool $replaceLifecycleStateSucceeds = true
    ) {
        $this->state = $state ?? new InMemoryUserRepositoryState();
        $this->authenticationAuthorityFenceOwner = new stdClass();
    }

    public function add(User $user): void
    {
        $canonical = $user->getEmail()->canonical();
        if (
            array_any(
                $this->state->users,
                static fn(User $reserved): bool =>
                    $reserved->getEmail()->canonical() === $canonical
                    || $reserved->getPendingEmailChange()?->canonical() === $canonical
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

    public function getAll(Pagination $pagination): ResultSet
    {
        $records = ArrayList::of(User::class)->replace(array_slice(
            $this->state->users,
            $pagination->offset(),
            $pagination->limit()
        ));

        return new ResultSet(
            $pagination->page(),
            $pagination->perPage(),
            count($this->state->users),
            $records
        );
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

    public function replaceEmailChangeReservation(User $expected, User $replacement): bool
    {
        if (!$this->replaceEmailChangeReservationSucceeds) {
            return false;
        }

        $index = $this->emailChangeReservationReplacementIndex($expected, $replacement);
        if ($index === null) {
            return false;
        }

        $destination = $replacement->getPendingEmailChange();
        if ($destination instanceof EmailAddress) {
            foreach ($this->state->users as $reserved) {
                if ($reserved->getId()->equals($expected->getId())) {
                    continue;
                }

                if (
                    $reserved->getEmail()->canonical() === $destination->canonical()
                    || $reserved->getPendingEmailChange()?->canonical() === $destination->canonical()
                ) {
                    return false;
                }
            }
        }

        $this->replaceAt($index, $replacement);

        return true;
    }

    public function replacePendingInvitationEmail(User $expected, User $replacement): bool
    {
        if (!$this->replacePendingInvitationEmailSucceeds) {
            return false;
        }

        $index = $this->pendingInvitationEmailReplacementIndex($expected, $replacement);
        if ($index === null) {
            return false;
        }

        $destination = $replacement->getEmail()->canonical();
        foreach ($this->state->users as $reserved) {
            if ($reserved->getId()->equals($expected->getId())) {
                continue;
            }

            if (
                $reserved->getEmail()->canonical() === $destination
                || $reserved->getPendingEmailChange()?->canonical() === $destination
            ) {
                return false;
            }
        }

        $this->replaceAt($index, $replacement);

        return true;
    }

    public function replaceEmailChangeConfirmation(User $expected, User $replacement): bool
    {
        if (
            !$this->replaceEmailChangeConfirmationSucceeds
            || !$this->acquireAuthenticationAuthorityFence($expected)
        ) {
            return false;
        }

        $index = $this->emailChangeConfirmationReplacementIndex($expected, $replacement);
        if ($index === null || $this->emailIsClaimedByAnotherUser($expected, $replacement->getEmail())) {
            $this->releaseAuthenticationAuthorityFence($expected->getId());

            return false;
        }

        $releaseAfterOperation = $this->holdAuthenticationAuthorityFenceThroughCompletion($expected->getId());
        try {
            $this->replaceAt($index, $replacement);
        } finally {
            if ($releaseAfterOperation) {
                $this->releaseAuthenticationAuthorityFence($expected->getId());
            }
        }

        return true;
    }

    public function replaceLifecycleState(User $expected, User $replacement): bool
    {
        if (!$this->replaceLifecycleStateSucceeds) {
            return false;
        }

        $index = $this->lifecycleStateReplacementIndex($expected, $replacement);
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
            && $this->emailStateMatches($replacement, $expected)
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
                && $this->emailStateMatches($user, $expected)
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
                && $this->emailStateMatches($user, $expected)
                && $this->emailStateMatches($replacement, $expected)
            ) {
                return $index;
            }
        }

        return null;
    }

    private function emailChangeReservationReplacementIndex(User $expected, User $replacement): ?int
    {
        $authenticationAuthorityRevision = $expected->getAuthenticationAuthorityRevision();
        $authorizationAssignmentRevision = $expected->getAuthorizationAssignmentRevision();
        $emailChangeReservationRevision = $expected->getEmailChangeReservationRevision();
        $expectedPendingEmailChange = $expected->getPendingEmailChange();
        $replacementPendingEmailChange = $replacement->getPendingEmailChange();
        $reservationTransitionIsValid = (
            !$expectedPendingEmailChange instanceof EmailAddress
            && $replacementPendingEmailChange instanceof EmailAddress
        ) || (
            $expectedPendingEmailChange instanceof EmailAddress
            && !$replacementPendingEmailChange instanceof EmailAddress
        );

        foreach ($this->state->users as $index => $user) {
            if (
                $user->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $user->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $replacement->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $user->getState() === $expected->getState()
                && $replacement->getState() === $expected->getState()
                && $this->passwordHashesMatch($user->getPasswordHash(), $expected->getPasswordHash())
                && $this->passwordHashesMatch($replacement->getPasswordHash(), $expected->getPasswordHash())
                && $user->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $replacement->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $user->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision
                && $replacement->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision
                && $user->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $replacement->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $this->roleAssignmentsMatch($user, $expected)
                && $this->roleAssignmentsMatch($replacement, $expected)
                && $user->getEmailChangeReservationRevision() === $emailChangeReservationRevision
                && $replacement->getEmailChangeReservationRevision() === $emailChangeReservationRevision + 1
                && $user->getCanonicalEmailRevision() === $expected->getCanonicalEmailRevision()
                && $replacement->getCanonicalEmailRevision() === $expected->getCanonicalEmailRevision()
                && $user->getPendingEmailChange()?->canonical() === $expectedPendingEmailChange?->canonical()
                && $reservationTransitionIsValid
            ) {
                return $index;
            }
        }

        return null;
    }

    private function pendingInvitationEmailReplacementIndex(User $expected, User $replacement): ?int
    {
        $authenticationAuthorityRevision = $expected->getAuthenticationAuthorityRevision();
        $authorizationAssignmentRevision = $expected->getAuthorizationAssignmentRevision();
        $canonicalEmailRevision = $expected->getCanonicalEmailRevision();
        $emailChangeReservationRevision = $expected->getEmailChangeReservationRevision();
        $pendingEmailChange = $expected->getPendingEmailChange()?->canonical();

        foreach ($this->state->users as $index => $user) {
            if (
                $user->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $user->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $replacement->getEmail()->canonical() !== $expected->getEmail()->canonical()
                && $user->getState() === UserState::PENDING_ACTIVATION
                && $expected->getState() === UserState::PENDING_ACTIVATION
                && $replacement->getState() === UserState::PENDING_ACTIVATION
                && $this->passwordHashesMatch($user->getPasswordHash(), $expected->getPasswordHash())
                && $this->passwordHashesMatch($replacement->getPasswordHash(), $expected->getPasswordHash())
                && $user->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $replacement->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $user->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision
                && $replacement->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision
                && $user->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $replacement->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $this->roleAssignmentsMatch($user, $expected)
                && $this->roleAssignmentsMatch($replacement, $expected)
                && $user->getPendingEmailChange()?->canonical() === $pendingEmailChange
                && $replacement->getPendingEmailChange()?->canonical() === $pendingEmailChange
                && $user->getEmailChangeReservationRevision() === $emailChangeReservationRevision
                && $replacement->getEmailChangeReservationRevision() === $emailChangeReservationRevision
                && $user->getCanonicalEmailRevision() === $canonicalEmailRevision
                && $replacement->getCanonicalEmailRevision() === $canonicalEmailRevision + 1
            ) {
                return $index;
            }
        }

        return null;
    }

    private function emailChangeConfirmationReplacementIndex(User $expected, User $replacement): ?int
    {
        $pendingEmailChange = $expected->getPendingEmailChange();
        if (!$pendingEmailChange instanceof EmailAddress) {
            return null;
        }

        $authenticationAuthorityRevision = $expected->getAuthenticationAuthorityRevision();
        $authorizationAssignmentRevision = $expected->getAuthorizationAssignmentRevision();
        $canonicalEmailRevision = $expected->getCanonicalEmailRevision();
        $emailChangeReservationRevision = $expected->getEmailChangeReservationRevision();

        foreach ($this->state->users as $index => $user) {
            if (
                $user->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $user->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $replacement->getEmail()->canonical() === $pendingEmailChange->canonical()
                && $user->getState() === $expected->getState()
                && $replacement->getState() === UserState::ACTIVE
                && $this->passwordHashesMatch($user->getPasswordHash(), $expected->getPasswordHash())
                && $this->passwordHashesMatch($replacement->getPasswordHash(), $expected->getPasswordHash())
                && $user->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $replacement->getAuthenticationVersion() === $expected->getAuthenticationVersion() + 1
                && $user->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision
                && $replacement->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision + 1
                && $user->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $replacement->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $this->roleAssignmentsMatch($user, $expected)
                && $this->roleAssignmentsMatch($replacement, $expected)
                && $user->getPendingEmailChange()?->canonical() === $pendingEmailChange->canonical()
                && !$replacement->getPendingEmailChange() instanceof EmailAddress
                && $user->getEmailChangeReservationRevision() === $emailChangeReservationRevision
                && $replacement->getEmailChangeReservationRevision() === $emailChangeReservationRevision + 1
                && $user->getCanonicalEmailRevision() === $canonicalEmailRevision
                && $replacement->getCanonicalEmailRevision() === $canonicalEmailRevision + 1
            ) {
                return $index;
            }
        }

        return null;
    }

    private function lifecycleStateReplacementIndex(User $expected, User $replacement): ?int
    {
        if (!$this->lifecycleTransitionIsValid($expected->getState(), $replacement->getState())) {
            return null;
        }

        if (!$this->lifecyclePasswordTransitionIsValid($expected, $replacement)) {
            return null;
        }

        $authenticationAuthorityRevision = $expected->getAuthenticationAuthorityRevision();
        $authorizationAssignmentRevision = $expected->getAuthorizationAssignmentRevision();
        $emailChangeReservationRevision = $expected->getEmailChangeReservationRevision();
        $canonicalEmailRevision = $expected->getCanonicalEmailRevision();
        $pendingEmailChange = $expected->getPendingEmailChange()?->canonical();

        foreach ($this->state->users as $index => $user) {
            if (
                $user->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $user->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $replacement->getEmail()->canonical() === $expected->getEmail()->canonical()
                && $user->getState() === $expected->getState()
                && $this->passwordHashesMatch($user->getPasswordHash(), $expected->getPasswordHash())
                && $user->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $replacement->getAuthenticationVersion() === $expected->getAuthenticationVersion()
                && $user->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision
                && $replacement->getAuthenticationAuthorityRevision() === $authenticationAuthorityRevision
                && $user->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $replacement->getAuthorizationAssignmentRevision() === $authorizationAssignmentRevision
                && $this->roleAssignmentsMatch($user, $expected)
                && $this->roleAssignmentsMatch($replacement, $expected)
                && $user->getPendingEmailChange()?->canonical() === $pendingEmailChange
                && $replacement->getPendingEmailChange()?->canonical() === $pendingEmailChange
                && $user->getEmailChangeReservationRevision() === $emailChangeReservationRevision
                && $replacement->getEmailChangeReservationRevision() === $emailChangeReservationRevision
                && $user->getCanonicalEmailRevision() === $canonicalEmailRevision
                && $replacement->getCanonicalEmailRevision() === $canonicalEmailRevision
            ) {
                return $index;
            }
        }

        return null;
    }

    private function lifecycleTransitionIsValid(UserState $expected, UserState $target): bool
    {
        return match (true) {
            $expected === UserState::ACTIVE && $target === UserState::DISABLED,
            $expected === UserState::DISABLED && $target === UserState::ACTIVE,
            $expected === UserState::ACTIVE && $target === UserState::DELETED,
            $expected === UserState::DISABLED && $target === UserState::DELETED,
            $expected === UserState::DELETED && $target === UserState::ACTIVE,
            $expected === UserState::DELETED && $target === UserState::PENDING_ACTIVATION => true,
            default => false,
        };
    }

    private function lifecyclePasswordTransitionIsValid(User $expected, User $replacement): bool
    {
        if ($replacement->getState() === UserState::PENDING_ACTIVATION) {
            return !$replacement->getPasswordHash() instanceof PasswordHash;
        }

        return $this->passwordHashesMatch(
            $expected->getPasswordHash(),
            $replacement->getPasswordHash()
        );
    }

    private function emailIsClaimedByAnotherUser(User $expected, EmailAddress $email): bool
    {
        foreach ($this->state->users as $reserved) {
            if ($reserved->getId()->equals($expected->getId())) {
                continue;
            }

            if (
                $reserved->getEmail()->canonical() === $email->canonical()
                || $reserved->getPendingEmailChange()?->canonical() === $email->canonical()
            ) {
                return true;
            }
        }

        return false;
    }

    private function emailStateMatches(User $left, User $right): bool
    {
        return $left->getEmail()->canonical() === $right->getEmail()->canonical()
            && $left->getPendingEmailChange()?->canonical() === $right->getPendingEmailChange()?->canonical()
            && $left->getEmailChangeReservationRevision() === $right->getEmailChangeReservationRevision()
            && $left->getCanonicalEmailRevision() === $right->getCanonicalEmailRevision();
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
