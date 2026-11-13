<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Class AuditAgentPermissionOutcome
 *
 * Retains only changed relationships and the actual single assignment-revision advance.
 *
 * @phpstan-type AgentPermissionPayload array{
 *     before_revision: int, after_revision: int,
 *     granted_permission_ids: list<string>, revoked_permission_ids: list<string>
 * }
 */
final readonly class AuditAgentPermissionOutcome implements Arrayable
{
    /**
     * Constructs AuditAgentPermissionOutcome
     *
     * @phpstan-param list<PermissionId> $grantedPermissionIds
     * @phpstan-param list<PermissionId> $revokedPermissionIds
     */
    public function __construct(
        private int $beforeRevision,
        private int $afterRevision,
        private array $grantedPermissionIds,
        private array $revokedPermissionIds
    ) {
        if ($beforeRevision < 1 || $beforeRevision === PHP_INT_MAX || $afterRevision !== $beforeRevision + 1) {
            throw new DomainException('Audit assignments require one actual positive revision advance.');
        }

        if ($grantedPermissionIds === [] && $revokedPermissionIds === []) {
            throw new DomainException('Audit assignments require a meaningful relationship change.');
        }

        $seen = [];
        foreach ([$grantedPermissionIds, $revokedPermissionIds] as $permissionIds) {
            foreach ($permissionIds as $permissionId) {
                $key = $permissionId->toString();
                if (isset($seen[$key])) {
                    throw new DomainException('Audit assignment relationships must be unique and disjoint.');
                }

                $seen[$key] = true;
            }
        }
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
                $data['before_revision'],
                $data['after_revision'],
                $data['granted_permission_ids'],
                $data['revoked_permission_ids']
            ) || !is_int($data['before_revision']) || !is_int($data['after_revision'])
            || !is_array($data['granted_permission_ids']) || !is_array($data['revoked_permission_ids'])
        ) {
            throw new DomainException('Audit assignments require their complete current representation.');
        }

        return new self(
            $data['before_revision'],
            $data['after_revision'],
            self::readIds($data['granted_permission_ids']),
            self::readIds($data['revoked_permission_ids'])
        );
    }

    /**
     * Validates the action-specific changed relationships
     */
    public function assertOutcome(AuditAction $action): void
    {
        $valid = match ($action) {
            AuditAction::AGENT_PERMISSION_GRANTED => count($this->grantedPermissionIds) === 1
                && $this->revokedPermissionIds === [],
            AuditAction::AGENT_PERMISSION_REVOKED => $this->grantedPermissionIds === []
                && count($this->revokedPermissionIds) === 1,
            AuditAction::AGENT_PERMISSIONS_REPLACED => true,
            default => false
        };
        if (!$valid) {
            throw new DomainException('Audit assignment relationships must match their declared action.');
        }
    }

    /**
     * Returns bounded relationship and revision facts without an Agent snapshot
     *
     * @phpstan-return AgentPermissionPayload
     */
    public function toArray(): array
    {
        return [
            'before_revision'        => $this->beforeRevision,
            'after_revision'         => $this->afterRevision,
            'granted_permission_ids' => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->grantedPermissionIds
            ),
            'revoked_permission_ids' => array_map(
                static fn(PermissionId $id): string => $id->toString(),
                $this->revokedPermissionIds
            )
        ];
    }

    /**
     * Reads exact typed relationship identities without coercion
     *
     * @phpstan-param array<array-key, mixed> $values
     * @phpstan-return list<PermissionId>
     */
    private static function readIds(array $values): array
    {
        if (!array_is_list($values)) {
            throw new DomainException('Audit assignment relationships require ordered lists.');
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new DomainException('Audit assignment relationships require canonical identities.');
            }

            $ids[] = PermissionId::fromString($value);
        }

        return $ids;
    }
}
