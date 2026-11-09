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
