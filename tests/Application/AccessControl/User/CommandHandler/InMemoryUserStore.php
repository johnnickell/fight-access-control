<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\UserStore;
use Fight\AccessControl\Domain\AccessControl\User\User;

final class InMemoryUserStore implements UserStore
{
    /** @var list<User> */
    private array $users = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function reserve(User $user): bool
    {
        if (
            array_any(
                $this->users,
                static fn(User $reserved): bool => $reserved->getEmail()->canonical() === $user->getEmail()->canonical()
            )
        ) {
            return false;
        }

        $this->users[] = $user;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->users);
        });

        return true;
    }

    /** @return list<User> */
    public function all(): array
    {
        return $this->users;
    }
}
