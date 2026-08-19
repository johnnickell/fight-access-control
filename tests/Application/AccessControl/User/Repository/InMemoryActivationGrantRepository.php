<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;

final class InMemoryActivationGrantRepository implements ActivationGrantRepository
{
    /** @var list<ActivationGrant> */
    private array $grants = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function add(ActivationGrant $grant): void
    {
        $this->grants[] = $grant;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->grants);
        });
    }

    public function getByUserId(UserId $userId): ?ActivationGrant
    {
        foreach (array_reverse($this->grants) as $grant) {
            if ($grant->getUserId()->equals($userId)) {
                return $grant;
            }
        }

        return null;
    }

    public function replace(
        ActivationGrant $predecessor,
        ActivationGrant $revokedPredecessor,
        ActivationGrant $replacement
    ): void {
        $grants = $this->grants;

        foreach ($this->grants as $index => $grant) {
            if ($grant === $predecessor) {
                $this->grants[$index] = $revokedPredecessor;
                $this->grants[] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($grants): void {
                    $this->grants = $grants;
                });

                return;
            }
        }

        throw new LogicException('The predecessor activation grant was not found.');
    }

    /** @return list<ActivationGrant> */
    public function all(): array
    {
        return $this->grants;
    }
}
