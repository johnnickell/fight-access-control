<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Audit\Conformance;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use RuntimeException;

/** Complete mapping for the deliberately material-free, relationship-free User used by this binding */
final class StoredAuditUser extends User
{
    public static function encode(User $user): string
    {
        if (
            $user->getPasswordHash() !== null || $user->getRoleIds() !== [] || $user->getPendingEmailChange() !== null
        ) {
            throw new RuntimeException('This modeled User mapping does not support credential or relationship state.');
        }

        return json_encode([
            'id'         => $user->getId()->toString(),
            'email'      => $user->getEmail()->toString(),
            'created_at' => $user->getCreatedAt()->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $user->getUpdatedAt()->format('Y-m-d\TH:i:s.uP'),
            'revisions'  => AuditUserRevisions::fromUser($user)->toArray()
        ], JSON_THROW_ON_ERROR);
    }

    public static function decode(string $bytes): User
    {
        /** @var array{id: string, email: string, created_at: string, updated_at: string, revisions: array{state: string, authentication_version: int, authentication_authority: int, authorization_assignment: int, email_reservation: int, canonical_email: int}} $data */
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $revisions = AuditUserRevisions::fromArray($data['revisions'])->toArray();

        return new self(
            UserId::fromString($data['id']),
            EmailAddress::fromString($data['email']),
            UserState::from($revisions['state']),
            new DateTimeImmutable($data['created_at']),
            new DateTimeImmutable($data['updated_at']),
            authenticationVersion: $revisions['authentication_version'],
            authenticationAuthorityRevision: $revisions['authentication_authority'],
            authorizationAssignmentRevision: $revisions['authorization_assignment'],
            emailChangeReservationRevision: $revisions['email_reservation'],
            canonicalEmailRevision: $revisions['canonical_email']
        );
    }
}
