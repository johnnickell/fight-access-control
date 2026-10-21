<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetDelivery;
use Fight\Test\AccessControl\Domain\AccessControl\PasswordResetGrant\ExtensiblePasswordResetGrant;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(RecoveryAuthority::class)]
final class InMemoryRecoveryAuthorityConformanceTest extends RecoveryAuthorityConformance
{
    private InMemoryUnitOfWork $unitOfWork;

    private InMemoryUserRepositoryState $userState;

    protected function repository(User $user): PasswordResetGrantRepository
    {
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->userState = new InMemoryUserRepositoryState();
        $users = new InMemoryUserRepository($this->unitOfWork, state: $this->userState);
        $this->transaction(static function () use ($users, $user): void {
            $users->add($user);
        });

        return new InMemoryPasswordResetGrants($this->unitOfWork, authorityState: $this->userState);
    }

    protected function restart(PasswordResetGrantRepository $repository): PasswordResetGrantRepository
    {
        assert($repository instanceof InMemoryPasswordResetGrants);
        $state = new InMemoryPasswordResetGrantState();
        foreach ($repository->all() as $grant) {
            $state->grants[] = ExtensiblePasswordResetGrant::reconstitute(
                $grant->getId(),
                $grant->getUserId(),
                $grant->getCredentialHash(),
                $grant->getExpiresAt(),
                ExtensiblePasswordResetDelivery::fromStoredState($grant->getDelivery()),
                new RecoveryAuthority(
                    $grant->getAuthority()->getAuthenticationVersion(),
                    $grant->getAuthority()->getCanonicalEmailRevision()
                ),
                $grant->getConsumedAt(),
                $grant->getRevokedAt(),
                $grant->getRevision()
            );
        }

        $users = new InMemoryUserRepositoryState();
        foreach ($this->userState->users as $user) {
            $users->users[] = clone $user;
        }

        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->userState = $users;

        return new InMemoryPasswordResetGrants($this->unitOfWork, state: $state, authorityState: $this->userState);
    }

    protected function transaction(callable $operation): void
    {
        $this->unitOfWork->commitTransactional($operation);
    }
}
