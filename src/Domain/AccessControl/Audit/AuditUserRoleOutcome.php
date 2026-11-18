<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditUserRoleOutcome
 *
 * Captures a User assignment transition and the authoritative Role snapshot read for admission.
 * The Role reference is not a Role mutation or a promise that its definition cannot later change.
 *
 * @phpstan-import-type UserRevisionsPayload from AuditUserRevisions
 * @phpstan-type UserRolePayload array{
 *     before: UserRevisionsPayload, after: UserRevisionsPayload, role_id: string, role_reference_revision: string
 * }
 */
final readonly class AuditUserRoleOutcome implements Arrayable
{
    /**
     * Constructs AuditUserRoleOutcome
     */
    public function __construct(
        private AuditUserRevisions $before,
        private AuditUserRevisions $after,
        private RoleId $roleId,
        private RoleRevision $roleReferenceRevision
    ) {
        $after->assertOutcome(AuditAction::USER_ROLE_ASSIGNED, $before);
    }

    /**
     * Creates the complete current persistence representation
     *
     * @phpstan-param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 4 || !isset(
                $data['before'],
                $data['after'],
                $data['role_id'],
                $data['role_reference_revision']
            ) || !is_array($data['before']) || !is_array($data['after'])
            || !is_string($data['role_id']) || !is_string($data['role_reference_revision'])
        ) {
            throw new DomainException('Audit User Role outcome requires its complete current representation.');
        }

        return new self(
            AuditUserRevisions::fromArray($data['before']),
            AuditUserRevisions::fromArray($data['after']),
            RoleId::fromString($data['role_id']),
            RoleRevision::fromString($data['role_reference_revision'])
        );
    }

    /**
     * Returns authoritative predecessor User counters
     */
    public function getBefore(): AuditUserRevisions
    {
        return $this->before;
    }

    /**
     * Returns authoritative successor User counters
     */
    public function getAfter(): AuditUserRevisions
    {
        return $this->after;
    }

    /**
     * Returns the related Role identity
     */
    public function getRoleId(): RoleId
    {
        return $this->roleId;
    }

    /**
     * Returns the complete Role incarnation and revision read for admission
     */
    public function getRoleReferenceRevision(): RoleRevision
    {
        return $this->roleReferenceRevision;
    }

    /**
     * Returns bounded relationship and actual User revision facts
     *
     * @phpstan-return UserRolePayload
     */
    public function toArray(): array
    {
        return [
            'before'                  => $this->before->toArray(),
            'after'                   => $this->after->toArray(),
            'role_id'                 => $this->roleId->toString(),
            'role_reference_revision' => $this->roleReferenceRevision->toString()
        ];
    }
}
