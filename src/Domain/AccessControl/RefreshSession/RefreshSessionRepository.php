<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use DateTimeImmutable;
use Exception;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Provides authoritative refresh-session persistence.
 */
interface RefreshSessionRepository
{
    /**
     * Adds a refresh session.
     *
     * @throws Exception When an error occurs
     */
    public function add(RefreshSession $refreshSession): void;

    /**
     * Retrieves a refresh session by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(RefreshSessionId $id): ?RefreshSession;

    /**
     * Retrieves one page of usable refresh sessions owned by a user.
     *
     * @throws Exception When an error occurs
     */
    public function getByUserId(UserId $userId, DateTimeImmutable $at, Pagination $pagination): ResultSet;

    /**
     * Retrieves a refresh session by a presented opaque credential.
     *
     * @throws Exception When an error occurs
     */
    public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession;

    /**
     * Retrieves a refresh session by any one-way digest used during its lifetime.
     *
     * @throws Exception When an error occurs
     */
    public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession;

    /**
     * Atomically replaces the session only while its expected revision remains current.
     *
     * The replacement must advance the expected revision by exactly one. Returns false
     * when the expected predecessor has already lost authority or the successor is invalid.
     *
     * @throws Exception When an error occurs
     */
    public function replace(RefreshSession $expected, RefreshSession $replacement): bool;
}
