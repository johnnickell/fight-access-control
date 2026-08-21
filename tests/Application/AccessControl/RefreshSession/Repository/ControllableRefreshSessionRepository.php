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
use Throwable;

final class ControllableRefreshSessionRepository implements RefreshSessionRepository
{
    public function __construct(
        private ?RefreshSession $refreshSession,
        private readonly bool $replaceSucceeds = true,
        private readonly ?Throwable $getFailure = null
    ) {
    }

    public function add(RefreshSession $refreshSession): void
    {
        $this->refreshSession = $refreshSession;
    }

    public function getById(RefreshSessionId $id): ?RefreshSession
    {
        if ($this->getFailure instanceof Throwable) {
            throw $this->getFailure;
        }

        if ($this->refreshSession?->getId()->equals($id)) {
            return $this->refreshSession;
        }

        return null;
    }

    public function getByUserId(UserId $userId, DateTimeImmutable $at, Pagination $pagination): ResultSet
    {
        $refreshSessions = [];
        if (
            $this->refreshSession?->getUserId()->equals($userId)
            && $this->refreshSession->isUsableAt($at)
        ) {
            $refreshSessions[] = $this->refreshSession;
        }

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
        if (
            $this->refreshSession?->getUserId()->equals($userId)
            && $this->refreshSession->isUsableAt($at)
        ) {
            return [$this->refreshSession];
        }

        return [];
    }

    public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
    {
        if ($this->refreshSession?->matchesCredential($refreshCredential)) {
            return $this->refreshSession;
        }

        return null;
    }

    public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
    {
        if ($this->refreshSession?->matchesUsedCredential($refreshCredential)) {
            return $this->refreshSession;
        }

        return null;
    }

    public function replace(RefreshSession $expected, RefreshSession $replacement): bool
    {
        if (!$this->replaceSucceeds) {
            return false;
        }

        $this->refreshSession = $replacement;

        return true;
    }
}
