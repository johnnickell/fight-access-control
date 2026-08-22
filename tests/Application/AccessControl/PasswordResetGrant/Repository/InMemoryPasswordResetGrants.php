<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryPasswordResetGrants implements PasswordResetGrantRepository
{
    /** @var list<PasswordResetGrant> */
    private array $passwordResetGrants = [];

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $replaceSucceeds = true,
        private readonly bool $replaceConsumedSucceeds = true,
        private readonly bool $replaceWithSuccessorSucceeds = true,
        private readonly bool $appendAfterTerminalSucceeds = true,
        private readonly bool $addSucceeds = true
    ) {
    }

    public function add(PasswordResetGrant $passwordResetGrant): bool
    {
        if (
            !$this->addSucceeds
            || $this->getLatestByUserId($passwordResetGrant->getUserId()) instanceof PasswordResetGrant
            || $this->hasCredentialHash(
                $passwordResetGrant->getUserId(),
                $passwordResetGrant->getCredentialHash()
            )
        ) {
            return false;
        }

        $this->passwordResetGrants[] = $passwordResetGrant;
        $this->recordRollback();

        return true;
    }

    public function appendAfterTerminal(
        PasswordResetGrant $terminalPredecessor,
        PasswordResetGrant $successor
    ): bool {
        $current = $this->getLatestByUserId($terminalPredecessor->getUserId());
        if (
            !$this->appendAfterTerminalSucceeds
            || $terminalPredecessor->isIssued()
            || $terminalPredecessor->getDelivery()->isRecoverable()
            || !$this->validSuccessor($terminalPredecessor, $successor)
            || !$current instanceof PasswordResetGrant
            || !$this->sameRevision($current, $terminalPredecessor)
        ) {
            return false;
        }

        $snapshot = $this->passwordResetGrants;
        $this->passwordResetGrants[] = $successor;
        $this->unitOfWork?->onRollback(function () use ($snapshot): void {
            $this->passwordResetGrants = $snapshot;
        });

        return true;
    }

    public function getById(PasswordResetGrantId $passwordResetGrantId): ?PasswordResetGrant
    {
        foreach ($this->passwordResetGrants as $passwordResetGrant) {
            if ($passwordResetGrant->getId()->equals($passwordResetGrantId)) {
                return $passwordResetGrant;
            }
        }

        return null;
    }

    public function getByDeliveryId(PasswordResetDeliveryId $passwordResetDeliveryId): ?PasswordResetGrant
    {
        foreach ($this->passwordResetGrants as $passwordResetGrant) {
            if ($passwordResetGrant->getDelivery()->getId()->equals($passwordResetDeliveryId)) {
                return $passwordResetGrant;
            }
        }

        return null;
    }

    public function getLatestByUserId(UserId $userId): ?PasswordResetGrant
    {
        foreach (array_reverse($this->passwordResetGrants) as $passwordResetGrant) {
            if ($passwordResetGrant->getUserId()->equals($userId)) {
                return $passwordResetGrant;
            }
        }

        return null;
    }

    public function replace(PasswordResetGrant $predecessor, PasswordResetGrant $replacement): bool
    {
        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceSucceeds
            || ($replacement->isConsumed() && !$this->replaceConsumedSucceeds)
            || !$this->sameGeneration($predecessor, $replacement)
            || $replacement->getRevision() !== $predecessor->getRevision() + 1
            || !$current instanceof PasswordResetGrant
            || !$this->sameRevision($current, $predecessor)
        ) {
            return false;
        }

        return $this->replaceCurrent($predecessor, $replacement);
    }

    public function replaceWithSuccessor(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $terminalPredecessor,
        PasswordResetGrant $successor
    ): bool {
        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceWithSuccessorSucceeds
            || $terminalPredecessor->isIssued()
            || $terminalPredecessor->getDelivery()->isRecoverable()
            || !$this->sameGeneration($predecessor, $terminalPredecessor)
            || $terminalPredecessor->getRevision() !== $predecessor->getRevision() + 1
            || !$this->validSuccessor($predecessor, $successor)
            || !$current instanceof PasswordResetGrant
            || !$this->sameRevision($current, $predecessor)
        ) {
            return false;
        }

        $snapshot = $this->passwordResetGrants;
        foreach ($this->passwordResetGrants as $index => $passwordResetGrant) {
            if ($this->sameRevision($passwordResetGrant, $predecessor)) {
                $this->passwordResetGrants[$index] = $terminalPredecessor;
                $this->passwordResetGrants[] = $successor;
                $this->unitOfWork?->onRollback(function () use ($snapshot): void {
                    $this->passwordResetGrants = $snapshot;
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

    private function recordRollback(): void
    {
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->passwordResetGrants);
        });
    }

    private function replaceCurrent(PasswordResetGrant $predecessor, PasswordResetGrant $replacement): bool
    {
        $snapshot = $this->passwordResetGrants;
        foreach ($this->passwordResetGrants as $index => $passwordResetGrant) {
            if ($this->sameRevision($passwordResetGrant, $predecessor)) {
                $this->passwordResetGrants[$index] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($snapshot): void {
                    $this->passwordResetGrants = $snapshot;
                });

                return true;
            }
        }

        return false;
    }

    private function sameGeneration(PasswordResetGrant $predecessor, PasswordResetGrant $replacement): bool
    {
        return $replacement->getId()->equals($predecessor->getId())
            && $replacement->getUserId()->equals($predecessor->getUserId())
            && $replacement->getCredentialHash() === $predecessor->getCredentialHash()
            && $replacement->getExpiresAt() == $predecessor->getExpiresAt()
            && $replacement->getDelivery()->getId()->equals($predecessor->getDelivery()->getId());
    }

    private function sameRevision(PasswordResetGrant $current, PasswordResetGrant $predecessor): bool
    {
        return $current->getId()->equals($predecessor->getId())
            && $current->getRevision() === $predecessor->getRevision();
    }

    private function validSuccessor(PasswordResetGrant $predecessor, PasswordResetGrant $successor): bool
    {
        return $successor->isIssued()
            && $successor->getDelivery()->isRecoverable()
            && $successor->getUserId()->equals($predecessor->getUserId())
            && !$successor->getId()->equals($predecessor->getId())
            && !$successor->getDelivery()->getId()->equals($predecessor->getDelivery()->getId())
            && !$this->hasCredentialHash($predecessor->getUserId(), $successor->getCredentialHash());
    }
}
