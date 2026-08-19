<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryRefreshSessionRepository implements RefreshSessionRepository
{
    /** @var list<RefreshSession> */
    private array $refreshSessions = [];

    public function __construct(private readonly ?InMemoryUnitOfWork $unitOfWork = null)
    {
    }

    public function add(RefreshSession $refreshSession): void
    {
        $this->refreshSessions[] = $refreshSession;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->refreshSessions);
        });
    }

    public function getById(RefreshSessionId $id): ?RefreshSession
    {
        foreach ($this->refreshSessions as $refreshSession) {
            if ($refreshSession->getId()->equals($id)) {
                return $refreshSession;
            }
        }

        return null;
    }

    /** @return list<RefreshSession> */
    public function all(): array
    {
        return $this->refreshSessions;
    }
}
