<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryRefreshSessionRepository implements RefreshSessionRepository
{
    private int $getByUserIdCalls = 0;

    private int $getAllActiveByUserIdCalls = 0;

    private readonly InMemoryRefreshSessionRepositoryState $state;

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        ?InMemoryRefreshSessionRepositoryState $state = null
    ) {
        $this->state = $state ?? new InMemoryRefreshSessionRepositoryState();
    }

    public function add(RefreshSession $refreshSession): void
    {
        $this->state->refreshSessions[] = $refreshSession;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->state->refreshSessions);
        });
    }

    public function addAsPartOfAuthenticationAuthorityReplacement(RefreshSession $refreshSession): void
    {
        $this->state->refreshSessions[] = $refreshSession;
        $this->unitOfWork?->onRollback(function () use ($refreshSession): void {
            $index = array_search($refreshSession, $this->state->refreshSessions, true);
            if ($index !== false) {
                array_splice($this->state->refreshSessions, $index, 1);
            }
        });
    }

    public function getById(RefreshSessionId $id): ?RefreshSession
    {
        foreach ($this->state->refreshSessions as $refreshSession) {
            if ($refreshSession->getId()->equals($id)) {
                return $refreshSession;
            }
        }

        return null;
    }

    public function getByUserId(UserId $userId, DateTimeImmutable $at, Pagination $pagination): ResultSet
    {
        ++$this->getByUserIdCalls;

        $refreshSessions = array_values(array_filter(
            $this->state->refreshSessions,
            static fn(RefreshSession $refreshSession): bool => $refreshSession->getUserId()->equals($userId)
                && $refreshSession->isUsableAt($at)
        ));
        $records = ArrayList::of(RefreshSession::class)->replace(array_slice(
            $refreshSessions,
            $pagination->offset(),
            $pagination->limit()
        ));

        return new ResultSet(
            $pagination->page(),
            $pagination->perPage(),
            count($refreshSessions),
            $records
        );
    }

    public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
    {
        ++$this->getAllActiveByUserIdCalls;
        $refreshSessions = array_values(array_filter(
            $this->state->refreshSessions,
            static fn(RefreshSession $refreshSession): bool => $refreshSession->getUserId()->equals($userId)
                && $refreshSession->isUsableAt($at)
        ));

        return $refreshSessions;
    }

    public function getAllActiveByUserIdCalls(): int
    {
        return $this->getAllActiveByUserIdCalls;
    }

    public function getByUserIdCalls(): int
    {
        return $this->getByUserIdCalls;
    }

    public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
    {
        foreach ($this->state->refreshSessions as $refreshSession) {
            if ($refreshSession->matchesCredential($refreshCredential)) {
                return $refreshSession;
            }
        }

        return null;
    }

    public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
    {
        foreach ($this->state->refreshSessions as $refreshSession) {
            if ($refreshSession->matchesUsedCredential($refreshCredential)) {
                return $refreshSession;
            }
        }

        return null;
    }

    public function replace(RefreshSession $expected, RefreshSession $replacement): bool
    {
        foreach ($this->state->refreshSessions as $index => $refreshSession) {
            if (
                $refreshSession->getId()->equals($expected->getId())
                && $replacement->getId()->equals($expected->getId())
                && $refreshSession->getRevision() === $expected->getRevision()
                && $replacement->getRevision() === $expected->getRevision() + 1
            ) {
                $this->state->refreshSessions[$index] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($index, $refreshSession, $replacement): void {
                    if (($this->state->refreshSessions[$index] ?? null) === $replacement) {
                        $this->state->refreshSessions[$index] = $refreshSession;
                    }
                });

                return true;
            }
        }

        return false;
    }

    /** @return list<RefreshSession> */
    public function all(): array
    {
        return $this->state->refreshSessions;
    }
}
