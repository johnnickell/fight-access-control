<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use Fight\AccessControl\Application\Invitation\ActivationGrantStore;
use Fight\AccessControl\Domain\Identity\ActivationGrant;

final class InMemoryActivationGrantStore implements ActivationGrantStore
{
    /** @var list<ActivationGrant> */
    private array $grants = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function save(ActivationGrant $grant): void
    {
        $this->grants[] = $grant;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->grants);
        });
    }

    /** @return list<ActivationGrant> */
    public function all(): array
    {
        return $this->grants;
    }
}
