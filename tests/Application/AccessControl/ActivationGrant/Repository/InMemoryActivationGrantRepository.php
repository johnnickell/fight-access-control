<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class InMemoryActivationGrantRepository implements ActivationGrantRepository
{
    /** @var list<ActivationGrant> */
    private array $activationGrants = [];

    private bool $beforeReplaceCalled = false;

    private int $replaceCalls = 0;

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $addSucceeds = true,
        private readonly bool $replaceSucceeds = true,
        private readonly bool $replaceWithSuccessorSucceeds = true,
        private readonly ?Closure $beforeReplace = null,
        private readonly ?int $replaceFailureOnCall = null,
        private readonly int $beforeReplaceOnCall = 1
    ) {
    }

    public function add(ActivationGrant $activationGrant): bool
    {
        if (
            !$this->addSucceeds
            || $this->getLatestByUserId($activationGrant->getUserId()) instanceof ActivationGrant
            || !$this->isPristine($activationGrant)
            || $this->hasGrantId($activationGrant->getId())
            || $this->hasDeliveryId($activationGrant->getDelivery()->getId())
            || $this->hasCredentialHash($activationGrant->getUserId(), $activationGrant->getCredentialHash())
        ) {
            return false;
        }

        $this->activationGrants[] = $activationGrant;
        $this->recordRollback();

        return true;
    }

    public function getByDeliveryId(ActivationDeliveryId $activationDeliveryId): ?ActivationGrant
    {
        foreach ($this->activationGrants as $activationGrant) {
            if ($activationGrant->getDelivery()->getId()->equals($activationDeliveryId)) {
                return $activationGrant;
            }
        }

        return null;
    }

    public function getById(ActivationGrantId $activationGrantId): ?ActivationGrant
    {
        foreach ($this->activationGrants as $activationGrant) {
            if ($activationGrant->getId()->equals($activationGrantId)) {
                return $activationGrant;
            }
        }

        return null;
    }

    public function getLatestByUserId(UserId $userId): ?ActivationGrant
    {
        foreach (array_reverse($this->activationGrants) as $activationGrant) {
            if ($activationGrant->getUserId()->equals($userId)) {
                return $activationGrant;
            }
        }

        return null;
    }

    public function replace(ActivationGrant $predecessor, ActivationGrant $replacement): bool
    {
        ++$this->replaceCalls;
        if (
            !$this->beforeReplaceCalled
            && $this->beforeReplace instanceof Closure
            && $this->replaceCalls === $this->beforeReplaceOnCall
        ) {
            $this->beforeReplaceCalled = true;
            ($this->beforeReplace)($this, $predecessor);
        }

        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceSucceeds
            || $this->replaceFailureOnCall === $this->replaceCalls
            || !$current instanceof ActivationGrant
            || !$this->sameState($current, $predecessor)
            || !$this->sameGeneration($current, $replacement)
            || $replacement->getRevision() !== $current->getRevision() + 1
            || !$this->isAllowedReplacement($current, $replacement)
        ) {
            return false;
        }

        return $this->replaceCurrent($current, $replacement);
    }

    public function replaceWithSuccessor(
        ActivationGrant $predecessor,
        ActivationGrant $terminalPredecessor,
        ActivationGrant $successor
    ): bool {
        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceWithSuccessorSucceeds
            || !$current instanceof ActivationGrant
            || !$this->sameState($current, $predecessor)
            || $terminalPredecessor->isIssued()
            || $terminalPredecessor->getDelivery()->isRetryable()
            || !$this->sameGeneration($current, $terminalPredecessor)
            || $terminalPredecessor->getRevision() !== $current->getRevision() + 1
            || !$this->isAllowedReplacement($current, $terminalPredecessor)
            || !$this->validSuccessor($current, $successor)
        ) {
            return false;
        }

        $snapshot = $this->activationGrants;
        foreach ($this->activationGrants as $index => $activationGrant) {
            if ($this->sameRevision($activationGrant, $current)) {
                $this->activationGrants[$index] = $terminalPredecessor;
                $this->activationGrants[] = $successor;
                $this->unitOfWork?->onRollback(function () use ($snapshot): void {
                    $this->activationGrants = $snapshot;
                });

                return true;
            }
        }

        return false;
    }

    /** @return list<ActivationGrant> */
    public function all(): array
    {
        return $this->activationGrants;
    }

    private function hasCredentialHash(UserId $userId, string $credentialHash): bool
    {
        return array_any(
            $this->activationGrants,
            fn(ActivationGrant $activationGrant): bool =>
                $activationGrant->getUserId()->equals($userId)
                && $activationGrant->getCredentialHash() === $credentialHash
        );
    }

    private function hasDeliveryId(ActivationDeliveryId $activationDeliveryId): bool
    {
        return array_any(
            $this->activationGrants,
            fn(ActivationGrant $activationGrant): bool =>
                $activationGrant->getDelivery()->getId()->equals($activationDeliveryId)
        );
    }

    private function hasGrantId(ActivationGrantId $activationGrantId): bool
    {
        return array_any(
            $this->activationGrants,
            fn(ActivationGrant $activationGrant): bool => $activationGrant->getId()->equals($activationGrantId)
        );
    }

    private function isAllowedReplacement(ActivationGrant $predecessor, ActivationGrant $replacement): bool
    {
        $predecessorDelivery = $predecessor->getDelivery();
        $replacementDelivery = $replacement->getDelivery();
        $deliveryOwnershipIsUnchanged = $replacementDelivery->getUserId()->equals($predecessorDelivery->getUserId())
            && $replacementDelivery->getEmail()->canonical() === $predecessorDelivery->getEmail()->canonical()
            && $replacementDelivery->getExpiresAt() == $predecessorDelivery->getExpiresAt();

        if (!$deliveryOwnershipIsUnchanged) {
            return false;
        }

        if ($predecessor->isIssued() && $replacement->isIssued()) {
            return $this->isAllowedDeliveryTransition($predecessor, $replacement);
        }

        if (!$predecessor->isIssued() || !($replacement->isConsumed() xor $replacement->isRevoked())) {
            return false;
        }

        $transitionedAt = $replacement->getRevokedAt();
        if ($replacement->isConsumed()) {
            $transitionedAt = $replacement->getConsumedAt();
        }

        if (!$transitionedAt instanceof DateTimeImmutable) {
            return false;
        }

        try {
            if ($replacement->isConsumed()) {
                $expected = $predecessor->consume($transitionedAt);
            } else {
                $expected = $predecessor->revoke($transitionedAt);
            }
        } catch (ActivationGrantException) {
            return false;
        }

        return $this->sameState($expected, $replacement);
    }

    private function isAllowedDeliveryTransition(ActivationGrant $predecessor, ActivationGrant $replacement): bool
    {
        $before = $predecessor->getDelivery();
        $after = $replacement->getDelivery();

        if ($after->getStatus() === ActivationDeliveryStatus::EXPIRED) {
            return $before->getCiphertext() !== null && $after->getCiphertext() === null;
        }

        return match ($before->getStatus()) {
            ActivationDeliveryStatus::PENDING =>
                $after->getStatus() === ActivationDeliveryStatus::CLAIMED
                && $after->getCiphertext() === $before->getCiphertext(),
            ActivationDeliveryStatus::CLAIMED =>
                ($after->getStatus() === ActivationDeliveryStatus::FAILED
                    && $after->getCiphertext() === $before->getCiphertext())
                || ($after->getStatus() === ActivationDeliveryStatus::CONFIRMED
                    && $after->getCiphertext() === null),
            ActivationDeliveryStatus::FAILED =>
                $after->getStatus() === ActivationDeliveryStatus::PENDING
                && $after->getCiphertext() === $before->getCiphertext(),
            ActivationDeliveryStatus::CONFIRMED,
            ActivationDeliveryStatus::EXPIRED => false,
        };
    }

    private function isPristine(ActivationGrant $activationGrant): bool
    {
        $delivery = $activationGrant->getDelivery();

        return $activationGrant->getRevision() === 0
            && $activationGrant->isIssued()
            && $delivery->getStatus() === ActivationDeliveryStatus::PENDING
            && $delivery->getCiphertext() !== null
            && $delivery->getCiphertext() !== ''
            && $delivery->getUserId()->equals($activationGrant->getUserId())
            && $delivery->getExpiresAt() == $activationGrant->getExpiresAt();
    }

    private function recordRollback(): void
    {
        $this->unitOfWork?->onRollback(function (): void {
            array_pop($this->activationGrants);
        });
    }

    private function replaceCurrent(ActivationGrant $predecessor, ActivationGrant $replacement): bool
    {
        $snapshot = $this->activationGrants;
        foreach ($this->activationGrants as $index => $activationGrant) {
            if ($this->sameRevision($activationGrant, $predecessor)) {
                $this->activationGrants[$index] = $replacement;
                $this->unitOfWork?->onRollback(function () use ($snapshot): void {
                    $this->activationGrants = $snapshot;
                });

                return true;
            }
        }

        return false;
    }

    private function sameGeneration(ActivationGrant $predecessor, ActivationGrant $replacement): bool
    {
        return $replacement->getId()->equals($predecessor->getId())
            && $replacement->getUserId()->equals($predecessor->getUserId())
            && $replacement->getCredentialHash() === $predecessor->getCredentialHash()
            && $replacement->getExpiresAt() == $predecessor->getExpiresAt()
            && $replacement->getDelivery()->getId()->equals($predecessor->getDelivery()->getId())
            && $predecessor->getDelivery()->getUserId()->equals($predecessor->getUserId())
            && $replacement->getDelivery()->getUserId()->equals($replacement->getUserId());
    }

    private function sameRevision(ActivationGrant $current, ActivationGrant $predecessor): bool
    {
        return $current->getId()->equals($predecessor->getId())
            && $current->getRevision() === $predecessor->getRevision();
    }

    private function sameState(ActivationGrant $current, ActivationGrant $predecessor): bool
    {
        $currentDelivery = $current->getDelivery();
        $predecessorDelivery = $predecessor->getDelivery();

        return $this->sameRevision($current, $predecessor)
            && $current->getUserId()->equals($predecessor->getUserId())
            && $current->getCredentialHash() === $predecessor->getCredentialHash()
            && $current->getExpiresAt() == $predecessor->getExpiresAt()
            && $current->getConsumedAt() == $predecessor->getConsumedAt()
            && $current->getRevokedAt() == $predecessor->getRevokedAt()
            && $currentDelivery->getId()->equals($predecessorDelivery->getId())
            && $currentDelivery->getUserId()->equals($predecessorDelivery->getUserId())
            && $currentDelivery->getEmail()->canonical() === $predecessorDelivery->getEmail()->canonical()
            && $currentDelivery->getCiphertext() === $predecessorDelivery->getCiphertext()
            && $currentDelivery->getExpiresAt() == $predecessorDelivery->getExpiresAt()
            && $currentDelivery->getStatus() === $predecessorDelivery->getStatus();
    }

    private function validSuccessor(ActivationGrant $predecessor, ActivationGrant $successor): bool
    {
        return $this->isPristine($successor)
            && $successor->getUserId()->equals($predecessor->getUserId())
            && !$successor->getId()->equals($predecessor->getId())
            && !$successor->getDelivery()->getId()->equals($predecessor->getDelivery()->getId())
            && !$this->hasGrantId($successor->getId())
            && !$this->hasDeliveryId($successor->getDelivery()->getId())
            && !$this->hasCredentialHash($predecessor->getUserId(), $successor->getCredentialHash());
    }
}
