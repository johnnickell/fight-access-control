<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\Role\ManagedRoleDefinition;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Announces one safely committed managed-policy reconciliation.
 */
final readonly class ManagedPolicyReconciled implements Event
{
    /** @var array{permissions: list<array<string, mixed>>, roles: list<array<string, mixed>>} */
    private array $plan;

    /**
     * @param array<string, mixed> $plan
     */
    public function __construct(array $plan, private DateTimeImmutable $occurredAt)
    {
        $this->plan = self::validatedPlan($plan);
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['plan', 'occurred_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        if (!is_array($data['plan'])) {
            throw new DomainException('Managed policy reconciliation plan must be an array.');
        }

        /** @var array<string, mixed> $plan */
        $plan = $data['plan'];

        return new static($plan, new DateTimeImmutable((string) $data['occurred_at']));
    }

    /**
     * @return array{permissions: list<array<string, mixed>>, roles: list<array<string, mixed>>}
     */
    public function getPlan(): array
    {
        return $this->plan;
    }

    /**
     * Returns when reconciliation committed.
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * Validates and canonicalizes the serialized managed-policy plan.
     *
     * @param array<string, mixed> $plan
     *
     * @return array{permissions: list<array<string, mixed>>, roles: list<array<string, mixed>>}
     */
    private static function validatedPlan(array $plan): array
    {
        self::assertExactKeys($plan, ['permissions', 'roles'], 'Managed policy reconciliation plan');
        if (!is_array($plan['permissions']) || !array_is_list($plan['permissions'])) {
            throw new DomainException('Managed policy reconciliation permissions must be a list.');
        }

        if (!is_array($plan['roles']) || !array_is_list($plan['roles'])) {
            throw new DomainException('Managed policy reconciliation roles must be a list.');
        }

        return [
            'permissions' => array_map(
                self::validatedPermissionItem(...),
                $plan['permissions']
            ),
            'roles' => array_map(
                self::validatedRoleItem(...),
                $plan['roles']
            ),
        ];
    }

    /**
     * Validates and canonicalizes one managed-permission plan item.
     *
     * @return array{id: string, name: string, tier: string, action: string}
     */
    private static function validatedPermissionItem(mixed $item): array
    {
        if (!is_array($item)) {
            throw new DomainException('A managed permission reconciliation item must be an array.');
        }

        self::assertExactKeys($item, ['id', 'name', 'tier', 'action'], 'Managed permission reconciliation item');
        if (
            !is_string($item['id'])
            || !is_string($item['name'])
            || !is_string($item['tier'])
            || PermissionTier::tryFrom($item['tier']) === null
        ) {
            throw new DomainException('A managed permission reconciliation item is malformed.');
        }

        $action = self::validatedAction($item['action']);
        $definition = ManagedPermissionDefinition::fromArray($item);

        return [...$definition->toArray(), 'action' => $action->value];
    }

    /**
     * Validates and canonicalizes one managed-role plan item.
     *
     * @return array{id: string, name: string, permission_ids: list<string>, action: string}
     */
    private static function validatedRoleItem(mixed $item): array
    {
        if (!is_array($item)) {
            throw new DomainException('A managed role reconciliation item must be an array.');
        }

        self::assertExactKeys($item, ['id', 'name', 'permission_ids', 'action'], 'Managed role reconciliation item');
        if (
            !is_string($item['id'])
            || !is_string($item['name'])
            || !is_array($item['permission_ids'])
            || !array_is_list($item['permission_ids'])
        ) {
            throw new DomainException('A managed role reconciliation item is malformed.');
        }

        foreach ($item['permission_ids'] as $permissionId) {
            if (!is_string($permissionId)) {
                throw new DomainException('Managed role reconciliation permission identifiers must be strings.');
            }
        }

        $action = self::validatedAction($item['action']);
        $definition = ManagedRoleDefinition::fromArray($item);

        return [...$definition->toArray(), 'action' => $action->value];
    }

    /**
     * Validates one managed-policy change action.
     */
    private static function validatedAction(mixed $action): ManagedPolicyChangeAction
    {
        if (!is_string($action) || ManagedPolicyChangeAction::tryFrom($action) === null) {
            throw new DomainException('A managed policy reconciliation action is invalid.');
        }

        return ManagedPolicyChangeAction::from($action);
    }

    /**
     * Requires exactly the declared serialized keys.
     *
     * @phpstan-param array<array-key, mixed> $data
     * @phpstan-param list<string> $requiredKeys
     */
    private static function assertExactKeys(array $data, array $requiredKeys, string $subject): void
    {
        if (count($data) !== count($requiredKeys)) {
            throw new DomainException($subject.' must contain exactly its declared keys.');
        }

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('%s is missing required key "%s".', $subject, $key));
            }
        }
    }
}
