<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\ManagedPolicy;

use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Command\ReconcileManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Event\ManagedPolicyReconciled;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedRoleDefinition;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(ManagedPermissionDefinition::class)]
#[CoversClass(ManagedRoleDefinition::class)]
#[CoversClass(ManagedPolicy::class)]
#[CoversClass(ReconcileManagedPolicy::class)]
#[CoversClass(PreviewManagedPolicy::class)]
#[CoversClass(ManagedPolicyReconciled::class)]
final class PolicyInputContractTest extends TestCase
{
    /** @return array{permissions: list<array{id: string, name: string, tier: string}>, roles: list<array{id: string, name: string, permission_ids: list<string>}>, referenced_permission_ids: list<string>} */
    private static function policy(): array
    {
        $id = '018f0000-0000-7000-8000-000000000101';

        return [
            'permissions'               => [['id' => $id, 'name' => 'VIEW_USERS', 'tier' => 'ADMIN_SAFE']],
            'roles'                     => [[
                'id'             => '018f0000-0000-7000-8000-000000000201',
                'name'           => 'ROLE_EDITOR',
                'permission_ids' => [$id]
            ]],
            'referenced_permission_ids' => [$id]
        ];
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function malformedDefinitions(): iterable
    {
        $policy = self::policy();
        foreach (['id', 'name', 'tier'] as $field) {
            yield 'permission '.$field => ['permission', array_replace(
                $policy['permissions'][0],
                [$field => self::stringObject($policy['permissions'][0][$field])]
            )];
        }

        foreach (['id', 'name'] as $field) {
            yield 'role '.$field => ['role', array_replace(
                $policy['roles'][0],
                [$field => self::stringObject($policy['roles'][0][$field])]
            )];
        }

        yield 'role member' => ['role', array_replace($policy['roles'][0], [
            'permission_ids' => [self::stringObject($policy['referenced_permission_ids'][0])]
        ])];
        foreach (['permissions', 'roles'] as $field) {
            $invalid = $policy;
            $invalid[$field] = [(object) $policy[$field][0]];
            yield 'object '.$field => ['policy', $invalid];
            $invalid[$field] = ['named' => $policy[$field][0]];
            yield 'map '.$field => ['policy', $invalid];
        }

        yield 'object reference' => ['policy', array_replace($policy, [
            'referenced_permission_ids' => [self::stringObject($policy['referenced_permission_ids'][0])]
        ])];
        yield 'map references' => ['policy', array_replace($policy, [
            'referenced_permission_ids' => ['named' => $policy['referenced_permission_ids'][0]]
        ])];
        yield 'map membership' => ['role', array_replace($policy['roles'][0], [
            'permission_ids' => ['named' => $policy['referenced_permission_ids'][0]]
        ])];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('malformedDefinitions')]
    public function test_decoded_policy_values_are_validated_not_coerced(string $kind, array $data): void
    {
        $this->expectException(DomainException::class);
        match ($kind) {
            'permission' => ManagedPermissionDefinition::fromArray($data),
            'role' => ManagedRoleDefinition::fromArray($data),
            default => ManagedPolicy::fromArray($data)
        };
    }

    public function test_policy_messages_preserve_the_current_serialized_contract(): void
    {
        $data = self::policy();
        self::assertSame($data, ManagedPolicy::fromArray($data)->toArray());
        self::assertSame($data, PreviewManagedPolicy::fromArray($data)->toArray());
        self::assertSame($data, ReconcileManagedPolicy::fromArray($data)->toArray());
    }

    public function test_event_time_cannot_be_missing_typed_or_empty_default_to_now(): void
    {
        foreach ([null, 0, false, [], '', self::stringObject('2030-01-01T00:00:00+00:00')] as $invalid) {
            try {
                ManagedPolicyReconciled::fromArray([
                    'plan'        => ['permissions' => [], 'roles' => []],
                    'occurred_at' => $invalid
                ]);
                self::fail('An invalid event time must not become a committed fact.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function stringObject(string $value): Stringable
    {
        return new readonly class ($value) implements Stringable {
            public function __construct(private string $value)
            {
            }

            public function __toString(): string
            {
                return $this->value;
            }
        };
    }
}
