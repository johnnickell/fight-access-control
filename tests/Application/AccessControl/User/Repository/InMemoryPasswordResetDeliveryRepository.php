<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Repository;

use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryPasswordResetDeliveryRepository implements PasswordResetDeliveryRepository
{
    /** @var list<PasswordResetDelivery> */
    private array $passwordResetDeliveries = [];

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $replaceSucceeds = true,
        private readonly bool $replaceInvalidatedSucceeds = true,
        private readonly bool $appendAfterTerminalSucceeds = true,
        private readonly bool $addSucceeds = true
    ) {
    }

    public function add(PasswordResetDelivery $passwordResetDelivery): bool
    {
        if (
            !$this->addSucceeds
            || $this->getByUserId($passwordResetDelivery->getUserId()) instanceof PasswordResetDelivery
        ) {
            return false;
        }

        $this->passwordResetDeliveries[] = $passwordResetDelivery;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->passwordResetDeliveries);
        });

        return true;
    }

    public function appendAfterTerminal(
        PasswordResetDelivery $terminalPredecessor,
        PasswordResetDelivery $replacement
    ): bool {
        if (
            !$this->appendAfterTerminalSucceeds
            || $terminalPredecessor->isRecoverable()
            || !$replacement->isRecoverable()
            || !$replacement->getUserId()->equals($terminalPredecessor->getUserId())
            || $replacement->getEmail() !== $terminalPredecessor->getEmail()
            || $replacement->getId()->equals($terminalPredecessor->getId())
            || $this->getByUserId($terminalPredecessor->getUserId()) !== $terminalPredecessor
            || $this->getById($terminalPredecessor->getId()) !== $terminalPredecessor
        ) {
            return false;
        }

        $passwordResetDeliveries = $this->passwordResetDeliveries;
        $this->passwordResetDeliveries[] = $replacement;
        $this->unitOfWork?->onRollback(function () use ($passwordResetDeliveries): void {
            $this->passwordResetDeliveries = $passwordResetDeliveries;
        });

        return true;
    }

    public function getByUserId(UserId $userId): ?PasswordResetDelivery
    {
        foreach (array_reverse($this->passwordResetDeliveries) as $passwordResetDelivery) {
            if ($passwordResetDelivery->getUserId()->equals($userId)) {
                return $passwordResetDelivery;
            }
        }

        return null;
    }

    public function getById(PasswordResetDeliveryId $passwordResetDeliveryId): ?PasswordResetDelivery
    {
        foreach ($this->passwordResetDeliveries as $passwordResetDelivery) {
            if ($passwordResetDelivery->getId()->equals($passwordResetDeliveryId)) {
                return $passwordResetDelivery;
            }
        }

        return null;
    }

    public function replaceInvalidated(
        PasswordResetDelivery $predecessor,
        PasswordResetDelivery $invalidatedPasswordResetDelivery
    ): bool {
        if (
            !$this->replaceInvalidatedSucceeds
            || !$predecessor->isRecoverable()
            || $invalidatedPasswordResetDelivery->isRecoverable()
            || !$invalidatedPasswordResetDelivery->getId()->equals($predecessor->getId())
            || !$invalidatedPasswordResetDelivery->getUserId()->equals($predecessor->getUserId())
            || $invalidatedPasswordResetDelivery->getEmail() !== $predecessor->getEmail()
            || $invalidatedPasswordResetDelivery->getExpiresAt() != $predecessor->getExpiresAt()
            || $this->getByUserId($predecessor->getUserId()) !== $predecessor
            || $this->getById($predecessor->getId()) !== $predecessor
        ) {
            return false;
        }

        $passwordResetDeliveries = $this->passwordResetDeliveries;

        foreach ($this->passwordResetDeliveries as $index => $passwordResetDelivery) {
            if ($passwordResetDelivery === $predecessor) {
                $this->passwordResetDeliveries[$index] = $invalidatedPasswordResetDelivery;
                $this->unitOfWork?->onRollback(function () use ($passwordResetDeliveries): void {
                    $this->passwordResetDeliveries = $passwordResetDeliveries;
                });

                return true;
            }
        }

        return false;
    }

    public function replace(
        PasswordResetDelivery $predecessor,
        PasswordResetDelivery $invalidatedPredecessor,
        PasswordResetDelivery $replacement
    ): bool {
        if (
            !$this->replaceSucceeds
            || !$predecessor->isRecoverable()
            || $invalidatedPredecessor->isRecoverable()
            || !$replacement->isRecoverable()
            || !$invalidatedPredecessor->getId()->equals($predecessor->getId())
            || !$invalidatedPredecessor->getUserId()->equals($predecessor->getUserId())
            || $invalidatedPredecessor->getEmail() !== $predecessor->getEmail()
            || $invalidatedPredecessor->getExpiresAt() != $predecessor->getExpiresAt()
            || !$replacement->getUserId()->equals($predecessor->getUserId())
            || $replacement->getEmail() !== $predecessor->getEmail()
            || $replacement->getId()->equals($predecessor->getId())
            || $this->getByUserId($predecessor->getUserId()) !== $predecessor
            || $this->getById($predecessor->getId()) !== $predecessor
        ) {
            return false;
        }

        $passwordResetDeliveries = $this->passwordResetDeliveries;

        foreach ($this->passwordResetDeliveries as $index => $passwordResetDelivery) {
            if ($passwordResetDelivery === $predecessor) {
                $this->passwordResetDeliveries[$index] = $invalidatedPredecessor;
                $this->passwordResetDeliveries[] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($passwordResetDeliveries): void {
                    $this->passwordResetDeliveries = $passwordResetDeliveries;
                });

                return true;
            }
        }

        return false;
    }

    /** @return list<PasswordResetDelivery> */
    public function all(): array
    {
        return $this->passwordResetDeliveries;
    }
}
