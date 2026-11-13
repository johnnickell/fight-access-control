<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditUserRevisions
 *
 * Captures only authoritative state and revision counters, never User data or credentials.
 *
 * @phpstan-type UserRevisionsPayload array{
 *     state: string, authentication_version: int, authentication_authority: int,
 *     authorization_assignment: int, email_reservation: int, canonical_email: int
 * }
 */
final readonly class AuditUserRevisions implements Arrayable
{
    /**
     * Constructs AuditUserRevisions
     *
     * @throws DomainException When a revision is outside its authoritative range
     */
    public function __construct(
        private UserState $state,
        private int $authenticationVersion,
        private int $authenticationAuthority,
        private int $authorizationAssignment,
        private int $emailReservation,
        private int $canonicalEmail
    ) {
        if (
            $authenticationVersion < 1 || $authenticationAuthority < 0 || $authorizationAssignment < 0
            || $emailReservation < 0 || $canonicalEmail < 0
        ) {
            throw new DomainException('Audit User revisions are outside their supported ranges.');
        }
    }

    /**
     * Creates a snapshot of the authoritative counters without retaining the aggregate
     */
    public static function fromUser(User $user): self
    {
        return new self(
            $user->getState(),
            $user->getAuthenticationVersion(),
            $user->getAuthenticationAuthorityRevision(),
            $user->getAuthorizationAssignmentRevision(),
            $user->getEmailChangeReservationRevision(),
            $user->getCanonicalEmailRevision()
        );
    }

    /**
     * Creates revisions from their complete native persisted representation
     *
     * @phpstan-param array<array-key, mixed> $data
     *
     * @throws DomainException When fields are missing, unknown or coercive
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 6 || !isset($data['state']) || !is_string($data['state'])) {
            throw new DomainException('Audit User revisions require the complete current shape.');
        }

        foreach (
            ['authentication_version', 'authentication_authority', 'authorization_assignment',
                'email_reservation', 'canonical_email'] as $field
        ) {
            if (!isset($data[$field]) || !is_int($data[$field])) {
                throw new DomainException('Audit User revisions require native integer counters.');
            }
        }

        return new self(
            UserState::from($data['state']),
            $data['authentication_version'],
            $data['authentication_authority'],
            $data['authorization_assignment'],
            $data['email_reservation'],
            $data['canonical_email']
        );
    }

    /**
     * Validates the declared outcome against the bounded User lifecycle and counter transitions
     *
     * @throws DomainException When an action contradicts the actual User transition semantics
     */
    public function assertOutcome(AuditAction $action, ?self $before): void
    {
        if ($before === null) {
            $assignmentsValid = $this->authorizationAssignment === 0;
            if ($action === AuditAction::USER_REGISTERED) {
                $assignmentsValid = $this->authorizationAssignment > 0;
            }

            if (
                $this->state !== UserState::PENDING_ACTIVATION || $this->authenticationVersion !== 1
                || $this->authenticationAuthority !== 0 || $this->emailReservation !== 0
                || $this->canonicalEmail !== 0 || !$assignmentsValid
            ) {
                throw new DomainException('Audit User creation requires its actual initial lifecycle and counters.');
            }

            return;
        }

        $validState = match ($action) {
            AuditAction::USER_ACTIVATED => $before->state === UserState::PENDING_ACTIVATION
                && $this->state === UserState::ACTIVE,
            AuditAction::EMAIL_CHANGE_EXPIRED, AuditAction::USER_ROLE_ASSIGNED,
                AuditAction::USER_ROLE_REMOVED => $this->state === $before->state,
            AuditAction::USER_PENDING_INVITATION_CORRECTED, AuditAction::USER_PENDING_REGISTRATION_RECOVERED
                => $before->state === UserState::PENDING_ACTIVATION && $this->state === $before->state,
            AuditAction::USER_ENABLED => $before->state === UserState::DISABLED && $this->state === UserState::ACTIVE,
            AuditAction::USER_DISABLED => $before->state === UserState::ACTIVE && $this->state === UserState::DISABLED,
            AuditAction::USER_DELETED => in_array($before->state, [UserState::ACTIVE, UserState::DISABLED], true)
                && $this->state === UserState::DELETED,
            AuditAction::USER_RESTORED => $before->state === UserState::DELETED
                && in_array($this->state, [UserState::ACTIVE, UserState::PENDING_ACTIVATION], true),
            default => $before->state === UserState::ACTIVE && $this->state === UserState::ACTIVE
        };
        if (!$validState) {
            throw new DomainException('Audit User lifecycle must match its declared outcome.');
        }

        $advances = match ($action) {
            AuditAction::USER_ROLE_ASSIGNED, AuditAction::USER_ROLE_REMOVED => ['authorization_assignment'],
            AuditAction::USER_ACTIVATED, AuditAction::USER_LOGGED_IN => ['authentication_authority'],
            AuditAction::USER_DISABLED, AuditAction::USER_DELETED => ['authentication_version'],
            AuditAction::PASSWORD_CHANGED, AuditAction::PASSWORD_RESET_COMPLETED
                => ['authentication_version', 'authentication_authority'],
            AuditAction::EMAIL_CHANGE_REQUESTED, AuditAction::EMAIL_CHANGE_CANCELLED,
                AuditAction::EMAIL_CHANGE_EXPIRED => ['email_reservation'],
            AuditAction::EMAIL_CHANGE_CONFIRMED
                => ['authentication_version', 'authentication_authority', 'email_reservation', 'canonical_email'],
            AuditAction::USER_PENDING_INVITATION_CORRECTED => ['canonical_email'],
            AuditAction::USER_PENDING_REGISTRATION_RECOVERED
                => $this->canonicalEmail === $before->canonicalEmail ? [] : ['canonical_email'],
            default => []
        };
        $afterCounters = $this->toArray();
        $beforeCounters = $before->toArray();
        unset($afterCounters['state'], $beforeCounters['state']);
        foreach ($beforeCounters as $field => $value) {
            if (in_array($field, $advances, true)) {
                if ($value === PHP_INT_MAX || $afterCounters[$field] !== $value + 1) {
                    throw new DomainException('Audit User outcome must advance each affected counter exactly once.');
                }
            } elseif ($afterCounters[$field] !== $value) {
                throw new DomainException('Audit User outcome must preserve unaffected counters.');
            }
        }
    }

    /**
     * Returns only admitted state and revision facts
     *
     * @phpstan-return UserRevisionsPayload
     */
    public function toArray(): array
    {
        return [
            'state'                    => $this->state->value,
            'authentication_version'   => $this->authenticationVersion,
            'authentication_authority' => $this->authenticationAuthority,
            'authorization_assignment' => $this->authorizationAssignment,
            'email_reservation'        => $this->emailReservation,
            'canonical_email'          => $this->canonicalEmail
        ];
    }
}
