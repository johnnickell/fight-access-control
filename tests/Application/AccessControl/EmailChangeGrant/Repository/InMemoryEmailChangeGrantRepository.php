<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryEmailChangeGrantRepository implements EmailChangeGrantRepository
{
    /** @var list<EmailChangeGrant> */
    private array $emailChangeGrants = [];

    private int $replaceCalls = 0;

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $addSucceeds = true,
        private readonly bool $replaceSucceeds = true,
        private readonly bool $appendAfterTerminalSucceeds = true,
        private readonly ?int $replaceFailureOnCall = null
    ) {
    }

    public function add(EmailChangeGrant $emailChangeGrant): bool
    {
        if (
            !$this->addSucceeds
            || $this->getLatestByUserId($emailChangeGrant->getUserId()) instanceof EmailChangeGrant
            || $emailChangeGrant->getRevision() !== 0
            || !$emailChangeGrant->isIssued()
            || $emailChangeGrant->getDelivery()->getStatus() !== EmailChangeDeliveryStatus::PENDING
            || !$emailChangeGrant->getDelivery()->isRecoverable()
            || !$emailChangeGrant->getDelivery()->getUserId()->equals($emailChangeGrant->getUserId())
            || array_any(
                $this->emailChangeGrants,
                static fn(EmailChangeGrant $stored): bool =>
                    $stored->getCredentialHash() === $emailChangeGrant->getCredentialHash()
            )
        ) {
            return false;
        }

        $this->emailChangeGrants[] = $emailChangeGrant;
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->emailChangeGrants);
        });

        return true;
    }

    public function getByDeliveryId(EmailChangeDeliveryId $emailChangeDeliveryId): ?EmailChangeGrant
    {
        foreach ($this->emailChangeGrants as $emailChangeGrant) {
            if ($emailChangeGrant->getDelivery()->getId()->equals($emailChangeDeliveryId)) {
                return $emailChangeGrant;
            }
        }

        return null;
    }

    public function appendAfterTerminal(
        EmailChangeGrant $terminalPredecessor,
        EmailChangeGrant $successor
    ): bool {
        $current = $this->getLatestByUserId($terminalPredecessor->getUserId());
        if (
            !$this->appendAfterTerminalSucceeds
            || !$current instanceof EmailChangeGrant
            || !$this->sameState($current, $terminalPredecessor)
            || $current->isIssued()
            || $current->getDelivery()->isRecoverable()
            || !$this->validSuccessor($current, $successor)
        ) {
            return false;
        }

        $snapshot = $this->emailChangeGrants;
        $this->emailChangeGrants[] = $successor;
        $this->unitOfWork?->onRollback(function () use ($snapshot): void {
            $this->emailChangeGrants = $snapshot;
        });

        return true;
    }

    public function replace(EmailChangeGrant $predecessor, EmailChangeGrant $replacement): bool
    {
        ++$this->replaceCalls;
        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceSucceeds
            || $this->replaceFailureOnCall === $this->replaceCalls
            || !$current instanceof EmailChangeGrant
            || !$this->sameState($current, $predecessor)
            || !$this->sameGeneration($current, $replacement)
            || $replacement->getRevision() !== $current->getRevision() + 1
        ) {
            return false;
        }

        if ($current->isIssued() && $replacement->isIssued()) {
            if (!$this->isAllowedDeliveryTransition($current, $replacement)) {
                return false;
            }

            return $this->replaceCurrent($current, $replacement);
        }

        $terminalStateCount = array_sum([
            (int) $replacement->isConsumed(),
            (int) $replacement->isRevoked(),
            (int) $replacement->isExpired(),
        ]);
        if ($terminalStateCount !== 1) {
            return false;
        }

        $transitionedAt = $replacement->getConsumedAt() ?? $replacement->getRevokedAt() ?? $replacement->getExpiredAt();
        if (!$transitionedAt instanceof DateTimeImmutable) {
            return false;
        }

        try {
            if ($replacement->isConsumed()) {
                $expected = $predecessor->consume($transitionedAt);
            } elseif ($replacement->isRevoked()) {
                $expected = $predecessor->revoke($transitionedAt);
            } else {
                $expected = $predecessor->expireAt($transitionedAt);
            }
        } catch (EmailChangeGrantException) {
            return false;
        }

        if (!$this->sameState($expected, $replacement)) {
            return false;
        }

        return $this->replaceCurrent($current, $replacement);
    }

    public function getLatestByUserId(UserId $userId): ?EmailChangeGrant
    {
        foreach (array_reverse($this->emailChangeGrants) as $emailChangeGrant) {
            if ($emailChangeGrant->getUserId()->equals($userId)) {
                return $emailChangeGrant;
            }
        }

        return null;
    }

    /** @return list<EmailChangeGrant> */
    public function all(): array
    {
        return $this->emailChangeGrants;
    }

    private function sameGeneration(EmailChangeGrant $left, EmailChangeGrant $right): bool
    {
        return $left->getId()->equals($right->getId())
            && $left->getUserId()->equals($right->getUserId())
            && $left->getCredentialHash() === $right->getCredentialHash()
            && $left->getExpiresAt() == $right->getExpiresAt()
            && $left->getDelivery()->getId()->equals($right->getDelivery()->getId());
    }

    private function validSuccessor(EmailChangeGrant $predecessor, EmailChangeGrant $successor): bool
    {
        return $successor->getUserId()->equals($predecessor->getUserId())
            && $successor->getRevision() === 0
            && $successor->isIssued()
            && $successor->getDelivery()->getStatus() === EmailChangeDeliveryStatus::PENDING
            && $successor->getDelivery()->isRecoverable()
            && $successor->getDelivery()->getUserId()->equals($successor->getUserId())
            && !array_any(
                $this->emailChangeGrants,
                static fn(EmailChangeGrant $stored): bool =>
                    $stored->getId()->equals($successor->getId())
                    || $stored->getDelivery()->getId()->equals($successor->getDelivery()->getId())
                    || $stored->getCredentialHash() === $successor->getCredentialHash()
            );
    }

    private function isAllowedDeliveryTransition(EmailChangeGrant $predecessor, EmailChangeGrant $replacement): bool
    {
        $before = $predecessor->getDelivery();
        $after = $replacement->getDelivery();

        return match ($before->getStatus()) {
            EmailChangeDeliveryStatus::PENDING =>
                $after->getStatus() === EmailChangeDeliveryStatus::CLAIMED
                && $after->getCiphertext() === $before->getCiphertext(),
            EmailChangeDeliveryStatus::CLAIMED =>
                ($after->getStatus() === EmailChangeDeliveryStatus::FAILED
                    && $after->getCiphertext() === $before->getCiphertext())
                || ($after->getStatus() === EmailChangeDeliveryStatus::CONFIRMED
                    && $after->getCiphertext() === null),
            EmailChangeDeliveryStatus::FAILED =>
                $after->getStatus() === EmailChangeDeliveryStatus::CLAIMED
                && $after->getCiphertext() === $before->getCiphertext(),
            EmailChangeDeliveryStatus::CONFIRMED => false,
        };
    }

    private function replaceCurrent(EmailChangeGrant $current, EmailChangeGrant $replacement): bool
    {
        $snapshot = $this->emailChangeGrants;
        foreach ($this->emailChangeGrants as $index => $stored) {
            if ($this->sameState($stored, $current)) {
                $this->emailChangeGrants[$index] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($snapshot): void {
                    $this->emailChangeGrants = $snapshot;
                });

                return true;
            }
        }

        return false;
    }

    private function sameState(EmailChangeGrant $left, EmailChangeGrant $right): bool
    {
        $leftDelivery = $left->getDelivery();
        $rightDelivery = $right->getDelivery();

        return $this->sameGeneration($left, $right)
            && $left->getRevision() === $right->getRevision()
            && $left->getConsumedAt() == $right->getConsumedAt()
            && $left->getRevokedAt() == $right->getRevokedAt()
            && $left->getExpiredAt() == $right->getExpiredAt()
            && $leftDelivery->getUserId()->equals($rightDelivery->getUserId())
            && $leftDelivery->getEmail()->canonical() === $rightDelivery->getEmail()->canonical()
            && $leftDelivery->getCiphertext() === $rightDelivery->getCiphertext()
            && $leftDelivery->getExpiresAt() == $rightDelivery->getExpiresAt()
            && $leftDelivery->getStatus() === $rightDelivery->getStatus();
    }
}
