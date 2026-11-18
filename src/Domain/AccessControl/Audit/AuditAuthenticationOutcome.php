<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditAuthenticationOutcome
 *
 * Captures committed authentication authority and session issuance or rotation, never token receipt.
 *
 * @phpstan-import-type UserOutcomePayload from AuditUserOutcome
 * @phpstan-type AuthenticationOutcomePayload array{
 *     user: UserOutcomePayload, session_id: string, session_before: ?int, session_after: int
 * }
 */
final readonly class AuditAuthenticationOutcome implements Arrayable
{
    /**
     * Constructs AuditAuthenticationOutcome
     *
     * @throws DomainException When session revisions do not describe creation or one rotation
     */
    public function __construct(
        private AuditUserOutcome $user,
        private RefreshSessionId $sessionId,
        private ?int $sessionBefore,
        private int $sessionAfter
    ) {
        if (
            ($sessionBefore === null && $sessionAfter !== 0)
            || ($sessionBefore !== null && (
                $sessionBefore < 0 || $sessionBefore === PHP_INT_MAX || $sessionAfter !== $sessionBefore + 1
            ))
        ) {
            throw new DomainException('Audit authentication requires actual session issuance or rotation revisions.');
        }
    }

    /**
     * Creates admitted authentication facts from their complete current shape
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 4 || !isset($data['user'], $data['session_id'], $data['session_after'])
            || !is_array($data['user']) || !is_string($data['session_id']) || !is_int($data['session_after'])
            || !array_key_exists('session_before', $data)
            || ($data['session_before'] !== null && !is_int($data['session_before']))
        ) {
            throw new DomainException('Audit authentication requires its complete current representation.');
        }

        return new self(
            AuditUserOutcome::fromArray($data['user']),
            RefreshSessionId::fromString($data['session_id']),
            $data['session_before'],
            $data['session_after']
        );
    }

    /**
     * Validates the actual authentication action and its coupled authority transition
     *
     * @throws DomainException When session or grant relationships contradict the action
     */
    public function assertOutcome(AuditAction $action): void
    {
        $rotation = $action === AuditAction::SESSION_REFRESHED;
        if (
            !in_array($action, [
                AuditAction::USER_ACTIVATED, AuditAction::USER_LOGGED_IN, AuditAction::SESSION_REFRESHED
            ], true)
            || $rotation !== ($this->sessionBefore !== null)
            || $this->user->getBefore() === null
            || $this->user->getReplacedPredecessor() !== null
        ) {
            throw new DomainException('Audit authentication action must match its actual session transition.');
        }

        $before = $this->user->getGrantBefore();
        $after = $this->user->getGrantAfter();
        if ($action === AuditAction::USER_ACTIVATED) {
            if (
                !$before?->getId() instanceof ActivationGrantId
                || $after === null || !$before->getId()->equals($after->getId())
            ) {
                throw new DomainException('Audit activation requires the actual consumed activation grant.');
            }
        } elseif ($before !== null || $after !== null) {
            throw new DomainException('Audit login and rotation do not manufacture a grant mutation.');
        }

        $this->user->getAfter()->assertOutcome($action, $this->user->getBefore());
    }

    /**
     * Returns bounded authority and session relationship facts
     *
     * @phpstan-return AuthenticationOutcomePayload
     */
    public function toArray(): array
    {
        return [
            'user'           => $this->user->toArray(),
            'session_id'     => $this->sessionId->toString(),
            'session_before' => $this->sessionBefore,
            'session_after'  => $this->sessionAfter
        ];
    }
}
