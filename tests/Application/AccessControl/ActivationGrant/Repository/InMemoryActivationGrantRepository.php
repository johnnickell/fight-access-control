<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository;

use Closure;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
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
        private readonly ?int $replaceFailureOnCall = null
    ) {
    }

    public function add(ActivationGrant $activationGrant): bool
    {
        if (
            !$this->addSucceeds
            || $this->getLatestByUserId($activationGrant->getUserId()) instanceof ActivationGrant
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
        if (!$this->beforeReplaceCalled && $this->beforeReplace instanceof Closure) {
            $this->beforeReplaceCalled = true;
            ($this->beforeReplace)($this, $predecessor);
        }

        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceSucceeds
            || $this->replaceFailureOnCall === $this->replaceCalls
            || !$this->sameGeneration($predecessor, $replacement)
            || $replacement->getRevision() !== $predecessor->getRevision() + 1
            || !$current instanceof ActivationGrant
            || !$this->sameRevision($current, $predecessor)
        ) {
            return false;
        }

        return $this->replaceCurrent($predecessor, $replacement);
    }

    public function replaceWithSuccessor(
        ActivationGrant $predecessor,
        ActivationGrant $terminalPredecessor,
        ActivationGrant $successor
    ): bool {
        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceWithSuccessorSucceeds
            || $terminalPredecessor->isIssued()
            || $terminalPredecessor->getDelivery()->isRetryable()
            || !$this->sameGeneration($predecessor, $terminalPredecessor)
            || $terminalPredecessor->getRevision() !== $predecessor->getRevision() + 1
            || !$this->validSuccessor($predecessor, $successor)
            || !$current instanceof ActivationGrant
            || !$this->sameRevision($current, $predecessor)
        ) {
            return false;
        }

        $snapshot = $this->activationGrants;
        foreach ($this->activationGrants as $index => $activationGrant) {
            if ($this->sameRevision($activationGrant, $predecessor)) {
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
            && $replacement->getDelivery()->getId()->equals($predecessor->getDelivery()->getId());
    }

    private function sameRevision(ActivationGrant $current, ActivationGrant $predecessor): bool
    {
        return $current->getId()->equals($predecessor->getId())
            && $current->getRevision() === $predecessor->getRevision();
    }

    private function validSuccessor(ActivationGrant $predecessor, ActivationGrant $successor): bool
    {
        return $successor->isIssued()
            && $successor->getDelivery()->isRetryable()
            && $successor->getUserId()->equals($predecessor->getUserId())
            && !$successor->getId()->equals($predecessor->getId())
            && !$successor->getDelivery()->getId()->equals($predecessor->getDelivery()->getId())
            && !$this->hasCredentialHash($predecessor->getUserId(), $successor->getCredentialHash());
    }
}
