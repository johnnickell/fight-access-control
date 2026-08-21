<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryPasswordResetGrantRepository implements PasswordResetGrantRepository
{
    /** @var list<PasswordResetGrant> */
    private array $passwordResetGrants = [];

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $replaceSucceeds = true,
        private readonly bool $replaceConsumedSucceeds = true,
        private readonly bool $appendAfterTerminalSucceeds = true,
        private readonly bool $addSucceeds = true
    ) {
    }

    public function add(PasswordResetGrant $passwordResetGrant): bool
    {
        if (
            !$this->addSucceeds
            || $this->getByUserId($passwordResetGrant->getUserId()) instanceof PasswordResetGrant
        ) {
            return false;
        }

        $this->passwordResetGrants[] = $passwordResetGrant;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->passwordResetGrants);
        });

        return true;
    }

    public function appendAfterTerminal(
        PasswordResetGrant $terminalPredecessor,
        PasswordResetGrant $replacement
    ): bool {
        if (
            !$this->appendAfterTerminalSucceeds
            || $terminalPredecessor->isIssued()
            || !$replacement->isIssued()
            || !$replacement->getUserId()->equals($terminalPredecessor->getUserId())
            || $this->hasCredentialHash(
                $terminalPredecessor->getUserId(),
                $replacement->getCredentialHash()
            )
            || $this->getByUserId($terminalPredecessor->getUserId()) !== $terminalPredecessor
        ) {
            return false;
        }

        $passwordResetGrants = $this->passwordResetGrants;
        $this->passwordResetGrants[] = $replacement;
        $this->unitOfWork?->onRollback(function () use ($passwordResetGrants): void {
            $this->passwordResetGrants = $passwordResetGrants;
        });

        return true;
    }

    public function getByUserId(UserId $userId): ?PasswordResetGrant
    {
        foreach (array_reverse($this->passwordResetGrants) as $passwordResetGrant) {
            if ($passwordResetGrant->getUserId()->equals($userId)) {
                return $passwordResetGrant;
            }
        }

        return null;
    }

    public function replaceConsumed(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $consumedPasswordResetGrant
    ): bool {
        if (
            !$this->replaceConsumedSucceeds
            || !$predecessor->isIssued()
            || !$consumedPasswordResetGrant->isConsumed()
            || !$consumedPasswordResetGrant->getUserId()->equals($predecessor->getUserId())
            || $consumedPasswordResetGrant->getCredentialHash() !== $predecessor->getCredentialHash()
            || $consumedPasswordResetGrant->getExpiresAt() != $predecessor->getExpiresAt()
            || $this->getByUserId($predecessor->getUserId()) !== $predecessor
        ) {
            return false;
        }

        $passwordResetGrants = $this->passwordResetGrants;

        foreach ($this->passwordResetGrants as $index => $passwordResetGrant) {
            if ($passwordResetGrant === $predecessor) {
                $this->passwordResetGrants[$index] = $consumedPasswordResetGrant;
                $this->unitOfWork?->onRollback(function () use ($passwordResetGrants): void {
                    $this->passwordResetGrants = $passwordResetGrants;
                });

                return true;
            }
        }

        return false;
    }

    public function replace(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $revokedPredecessor,
        PasswordResetGrant $replacement
    ): bool {
        if (
            !$this->replaceSucceeds
            || !$predecessor->isIssued()
            || !$revokedPredecessor->isRevoked()
            || !$replacement->isIssued()
            || !$revokedPredecessor->getUserId()->equals($predecessor->getUserId())
            || !$replacement->getUserId()->equals($predecessor->getUserId())
            || $revokedPredecessor->getCredentialHash() !== $predecessor->getCredentialHash()
            || $this->hasCredentialHash($predecessor->getUserId(), $replacement->getCredentialHash())
            || $revokedPredecessor->getExpiresAt() != $predecessor->getExpiresAt()
            || $this->getByUserId($predecessor->getUserId()) !== $predecessor
        ) {
            return false;
        }

        $passwordResetGrants = $this->passwordResetGrants;

        foreach ($this->passwordResetGrants as $index => $passwordResetGrant) {
            if ($passwordResetGrant === $predecessor) {
                $this->passwordResetGrants[$index] = $revokedPredecessor;
                $this->passwordResetGrants[] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($passwordResetGrants): void {
                    $this->passwordResetGrants = $passwordResetGrants;
                });

                return true;
            }
        }

        return false;
    }

    /** @return list<PasswordResetGrant> */
    public function all(): array
    {
        return $this->passwordResetGrants;
    }

    private function hasCredentialHash(UserId $userId, string $credentialHash): bool
    {
        return array_any(
            $this->passwordResetGrants,
            fn(PasswordResetGrant $passwordResetGrant): bool =>
                $passwordResetGrant->getUserId()->equals($userId)
                && $passwordResetGrant->getCredentialHash() === $credentialHash
        );
    }
}
