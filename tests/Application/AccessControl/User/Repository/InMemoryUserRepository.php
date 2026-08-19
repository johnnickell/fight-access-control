<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\DuplicateEmail;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryUserRepository implements UserRepository
{
    /** @var list<User> */
    private array $users = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function add(User $user): void
    {
        if (
            array_any(
                $this->users,
                static fn(User $reserved): bool => $reserved->getEmail()->canonical() === $user->getEmail()->canonical()
            )
        ) {
            throw new DuplicateEmail('The email address is already reserved.');
        }

        $this->users[] = $user;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->users);
        });
    }

    /** @return list<User> */
    public function all(): array
    {
        return $this->users;
    }
}
