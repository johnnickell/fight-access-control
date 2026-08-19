<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use Fight\AccessControl\Application\Invitation\UserStore;
use Fight\AccessControl\Domain\Identity\User;

final class InMemoryUserStore implements UserStore
{
    /** @var list<User> */
    private array $users = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function reserve(User $user): bool
    {
        if (array_any($this->users, fn(User $reserved): bool => $reserved->email()->equals($user->email()))) {
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
