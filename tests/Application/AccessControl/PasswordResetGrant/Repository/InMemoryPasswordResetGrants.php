<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetGrantException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Throwable;

final class InMemoryPasswordResetGrants implements PasswordResetGrantRepository
{
    /** @var list<PasswordResetGrant> */
    private array $passwordResetGrants = [];

    private int $replaceCalls = 0;

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $replaceSucceeds = true,
        private readonly bool $replaceConsumedSucceeds = true,
        private readonly bool $replaceWithSuccessorSucceeds = true,
        private readonly bool $appendAfterTerminalSucceeds = true,
        private readonly bool $addSucceeds = true,
        private readonly ?int $replaceFailureOnCall = null
    ) {
    }

    public function findDue(DateTimeImmutable $at, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $due = [];
        foreach ($this->passwordResetGrants as $passwordResetGrant) {
            $latest = $this->getLatestByUserId($passwordResetGrant->getUserId());
            $delivery = $passwordResetGrant->getDelivery();
            if (
                !$latest instanceof PasswordResetGrant
                || !$latest->getId()->equals($passwordResetGrant->getId())
                || !$delivery->isDueAt($at)
            ) {
                continue;
            }

            $due[] = new DueCredentialDelivery(
                $passwordResetGrant->purpose(),
                $delivery->getId(),
                $passwordResetGrant->getUserId(),
                $delivery->getNextAttemptAt(),
                $passwordResetGrant->getRevision(),
                $delivery->getStatus()
            );
        }

        usort($due, $this->compareDue(...));

        return array_slice($due, 0, $limit);
    }

    public function add(PasswordResetGrant $passwordResetGrant): bool
    {
        if (
            !$this->addSucceeds
            || $this->getLatestByUserId($passwordResetGrant->getUserId()) instanceof PasswordResetGrant
            || !$this->isPristine($passwordResetGrant)
            || $this->hasGrantId($passwordResetGrant->getId())
            || $this->hasDeliveryId($passwordResetGrant->getDelivery()->getId())
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
            || !$current instanceof PasswordResetGrant
            || !$this->sameState($current, $terminalPredecessor)
            || $current->isIssued()
            || $current->getDelivery()->isRecoverable()
            || !$this->validSuccessor($current, $successor)
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
        ++$this->replaceCalls;
        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceSucceeds
            || $this->replaceFailureOnCall === $this->replaceCalls
            || ($replacement->isConsumed() && !$this->replaceConsumedSucceeds)
            || !$current instanceof PasswordResetGrant
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
        PasswordResetGrant $predecessor,
        PasswordResetGrant $terminalPredecessor,
        PasswordResetGrant $successor
    ): bool {
        $current = $this->getLatestByUserId($predecessor->getUserId());
        if (
            !$this->replaceWithSuccessorSucceeds
            || !$current instanceof PasswordResetGrant
            || !$this->sameState($current, $predecessor)
            || $terminalPredecessor->isIssued()
            || $terminalPredecessor->getDelivery()->isRecoverable()
            || !$this->sameGeneration($current, $terminalPredecessor)
            || $terminalPredecessor->getRevision() !== $current->getRevision() + 1
            || !$this->isAllowedReplacement($current, $terminalPredecessor)
            || !$this->validSuccessor($current, $successor)
        ) {
            return false;
        }

        $snapshot = $this->passwordResetGrants;
        foreach ($this->passwordResetGrants as $index => $passwordResetGrant) {
            if ($this->sameRevision($passwordResetGrant, $current)) {
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

    private function hasDeliveryId(PasswordResetDeliveryId $passwordResetDeliveryId): bool
    {
        return array_any(
            $this->passwordResetGrants,
            fn(PasswordResetGrant $passwordResetGrant): bool =>
                $passwordResetGrant->getDelivery()->getId()->equals($passwordResetDeliveryId)
        );
    }

    private function hasGrantId(PasswordResetGrantId $passwordResetGrantId): bool
    {
        return array_any(
            $this->passwordResetGrants,
            fn(PasswordResetGrant $passwordResetGrant): bool =>
                $passwordResetGrant->getId()->equals($passwordResetGrantId)
        );
    }

    private function compareDue(DueCredentialDelivery $left, DueCredentialDelivery $right): int
    {
        return [$left->getDueAt()->format('U.u'), $left->getDeliveryId()->toString()] <=> [
            $right->getDueAt()->format('U.u'),
            $right->getDeliveryId()->toString()
        ];
    }

    private function isAllowedReplacement(PasswordResetGrant $predecessor, PasswordResetGrant $replacement): bool
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
        } catch (PasswordResetGrantException) {
            return false;
        }

        return $this->sameState($expected, $replacement);
    }

    private function isAllowedDeliveryTransition(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $replacement
    ): bool {
        $before = $predecessor->getDelivery();
        $after = $replacement->getDelivery();

        try {
            $expected = match ($after->getStatus()) {
                CredentialDeliveryStatus::CLAIMED => $predecessor->claimDelivery(
                    $after->getClaimToken(),
                    $after->getClaimedAt(),
                    $after->getLeaseUntil()
                ),
                CredentialDeliveryStatus::RETRY_PENDING => $predecessor->failDelivery(
                    $before->getClaimToken(),
                    $after->getLastOutcomeAt(),
                    $after->getLastFailure()
                ),
                CredentialDeliveryStatus::PENDING => $predecessor->requestDeliveryRetry(),
                CredentialDeliveryStatus::DELIVERED => $predecessor->confirmDelivery(
                    $before->getClaimToken(),
                    $after->getLastOutcomeAt()
                ),
                CredentialDeliveryStatus::PERMANENT_FAILURE => $predecessor->failDeliveryPermanently(
                    $before->getClaimToken(),
                    $after->getLastOutcomeAt()
                ),
                CredentialDeliveryStatus::EXPIRED => $this->expiredTransition($predecessor, $replacement),
                CredentialDeliveryStatus::INVALIDATED => $predecessor->invalidateDelivery(),
            };
        } catch (Throwable) {
            return false;
        }

        return $this->sameState($expected, $replacement);
    }

    private function expiredTransition(
        PasswordResetGrant $predecessor,
        PasswordResetGrant $replacement
    ): PasswordResetGrant {
        $before = $predecessor->getDelivery();
        $after = $replacement->getDelivery();
        if (
            $before->getStatus() === CredentialDeliveryStatus::CLAIMED
            && $after->getLastOutcomeAt() instanceof DateTimeImmutable
            && $after->getLastFailure() !== null
        ) {
            return $predecessor->failDelivery(
                $before->getClaimToken(),
                $after->getLastOutcomeAt(),
                $after->getLastFailure()
            );
        }

        return $predecessor->expireDeliveryAt($after->getExpiresAt());
    }

    private function isPristine(PasswordResetGrant $passwordResetGrant): bool
    {
        $delivery = $passwordResetGrant->getDelivery();

        return $passwordResetGrant->getRevision() === 0
            && $passwordResetGrant->isIssued()
            && $delivery->isPristine()
            && $delivery->getUserId()->equals($passwordResetGrant->getUserId())
            && $delivery->getExpiresAt() == $passwordResetGrant->getExpiresAt();
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
            && $replacement->getDelivery()->getId()->equals($predecessor->getDelivery()->getId())
            && $predecessor->getDelivery()->getUserId()->equals($predecessor->getUserId())
            && $replacement->getDelivery()->getUserId()->equals($replacement->getUserId());
    }

    private function sameRevision(PasswordResetGrant $current, PasswordResetGrant $predecessor): bool
    {
        return $current->getId()->equals($predecessor->getId())
            && $current->getRevision() === $predecessor->getRevision();
    }

    private function sameState(PasswordResetGrant $current, PasswordResetGrant $predecessor): bool
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
            && $currentDelivery->sameStateAs($predecessorDelivery);
    }

    private function validSuccessor(PasswordResetGrant $predecessor, PasswordResetGrant $successor): bool
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
