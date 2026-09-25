<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeGrantException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Throwable;

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

    public function findDue(DateTimeImmutable $at, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $due = [];
        foreach ($this->emailChangeGrants as $emailChangeGrant) {
            $latest = $this->getLatestByUserId($emailChangeGrant->getUserId());
            $delivery = $emailChangeGrant->getDelivery();
            if (
                !$latest instanceof EmailChangeGrant
                || !$latest->getId()->equals($emailChangeGrant->getId())
                || !$delivery->isDueAt($at)
            ) {
                continue;
            }

            $due[] = new DueCredentialDelivery(
                $emailChangeGrant->purpose(),
                $delivery->getId(),
                $emailChangeGrant->getUserId(),
                $delivery->getNextAttemptAt(),
                $emailChangeGrant->getRevision(),
                $delivery->getStatus()
            );
        }

        usort($due, $this->compareDue(...));

        return array_slice($due, 0, $limit);
    }

    public function add(EmailChangeGrant $emailChangeGrant): bool
    {
        if (
            !$this->addSucceeds
            || $this->getLatestByUserId($emailChangeGrant->getUserId()) instanceof EmailChangeGrant
            || !$this->isPristine($emailChangeGrant)
            || $this->hasGrantId($emailChangeGrant->getId())
            || $this->hasDeliveryId($emailChangeGrant->getDelivery()->getId())
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
            (int) $replacement->isExpired()
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

    private function compareDue(DueCredentialDelivery $left, DueCredentialDelivery $right): int
    {
        return [$left->getDueAt()->format('U.u'), $left->getDeliveryId()->toString()] <=> [
            $right->getDueAt()->format('U.u'),
            $right->getDeliveryId()->toString()
        ];
    }

    private function validSuccessor(EmailChangeGrant $predecessor, EmailChangeGrant $successor): bool
    {
        return $this->isPristine($successor)
            && $successor->getUserId()->equals($predecessor->getUserId())
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
                CredentialDeliveryStatus::INVALIDATED => null,
            };
        } catch (Throwable) {
            return false;
        }

        return $expected instanceof EmailChangeGrant && $this->sameState($expected, $replacement);
    }

    private function expiredTransition(
        EmailChangeGrant $predecessor,
        EmailChangeGrant $replacement
    ): EmailChangeGrant {
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

    private function hasDeliveryId(EmailChangeDeliveryId $emailChangeDeliveryId): bool
    {
        return array_any(
            $this->emailChangeGrants,
            fn(EmailChangeGrant $emailChangeGrant): bool =>
                $emailChangeGrant->getDelivery()->getId()->equals($emailChangeDeliveryId)
        );
    }

    private function hasGrantId(EmailChangeGrantId $emailChangeGrantId): bool
    {
        return array_any(
            $this->emailChangeGrants,
            fn(EmailChangeGrant $emailChangeGrant): bool => $emailChangeGrant->getId()->equals($emailChangeGrantId)
        );
    }

    private function isPristine(EmailChangeGrant $emailChangeGrant): bool
    {
        $delivery = $emailChangeGrant->getDelivery();

        return $emailChangeGrant->getRevision() === 0
            && $emailChangeGrant->isIssued()
            && $delivery->isPristine()
            && $delivery->getUserId()->equals($emailChangeGrant->getUserId())
            && $delivery->getExpiresAt() == $emailChangeGrant->getExpiresAt();
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
            && $leftDelivery->sameStateAs($rightDelivery);
    }
}
