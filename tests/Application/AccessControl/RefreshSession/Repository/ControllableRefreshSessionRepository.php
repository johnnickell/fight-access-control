<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
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

    public function getByUserId(UserId $userId): array
    {
        if ($this->refreshSession?->getUserId()->equals($userId)) {
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
