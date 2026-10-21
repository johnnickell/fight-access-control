<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use LogicException;

/**
 * Class PasswordResetRevocationService
 *
 * Cancels outstanding recovery inside the caller's held User fence and transaction.
 */
final readonly class PasswordResetRevocationService
{
    /**
     * Constructs PasswordResetRevocationService
     */
    public function __construct(private PasswordResetGrantRepository $passwordResetGrantRepository)
    {
    }

    /**
     * Revokes outstanding authority and destroys recoverable delivery material atomically
     */
    public function revokeFor(UserId $userId, DateTimeImmutable $at): void
    {
        $grant = $this->passwordResetGrantRepository->getLatestByUserId($userId);
        if (!$grant instanceof PasswordResetGrant || !$grant->isIssued()) {
            return;
        }

        if (!$this->passwordResetGrantRepository->replace($grant, $grant->revoke($at))) {
            throw new LogicException('Password-reset authority changed concurrently.');
        }
    }
}
