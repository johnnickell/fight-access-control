<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryRefreshSessionRepository implements RefreshSessionRepository
{
    private int $getByUserIdCalls = 0;

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

    public function getByUserId(UserId $userId): array
    {
        ++$this->getByUserIdCalls;

        return array_values(array_filter(
            $this->refreshSessions,
            static fn(RefreshSession $refreshSession): bool => $refreshSession->getUserId()->equals($userId)
        ));
    }

    public function getByUserIdCalls(): int
    {
        return $this->getByUserIdCalls;
    }

    public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
    {
        foreach ($this->refreshSessions as $refreshSession) {
            if ($refreshSession->matchesCredential($refreshCredential)) {
                return $refreshSession;
            }
        }

        return null;
    }

    public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
    {
        foreach ($this->refreshSessions as $refreshSession) {
            if ($refreshSession->matchesUsedCredential($refreshCredential)) {
                return $refreshSession;
            }
        }

        return null;
    }

    public function replace(RefreshSession $expected, RefreshSession $replacement): bool
    {
        foreach ($this->refreshSessions as $index => $refreshSession) {
            if (
                $refreshSession->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $refreshSession->getRevision() === $expected->getRevision()
                && $replacement->getRevision() === $expected->getRevision() + 1
            ) {
                $this->refreshSessions[$index] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($index, $refreshSession): void {
                    $this->refreshSessions[$index] = $refreshSession;
                });

                return true;
            }
        }

        return false;
    }

    /** @return list<RefreshSession> */
    public function all(): array
    {
        return $this->refreshSessions;
    }
}
