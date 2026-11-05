<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Role\Command\CreateCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\GrantPermissionToCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RevokePermissionFromCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleCreated;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionGranted;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRolePermissionRevoked;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRemoved;
use Fight\AccessControl\Domain\AccessControl\Role\Event\CustomRoleRenamed;
use Fight\AccessControl\Domain\AccessControl\Role\Query\GetRoleById;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Payload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(CreateCustomRole::class)]
#[CoversClass(GrantPermissionToCustomRole::class)]
#[CoversClass(RemoveCustomRole::class)]
#[CoversClass(RenameCustomRole::class)]
#[CoversClass(RevokePermissionFromCustomRole::class)]
#[CoversClass(CustomRoleCreated::class)]
#[CoversClass(CustomRolePermissionGranted::class)]
#[CoversClass(CustomRolePermissionRevoked::class)]
#[CoversClass(CustomRoleRemoved::class)]
#[CoversClass(CustomRoleRenamed::class)]
#[CoversClass(GetRoleById::class)]
final class RoleMessageContractTest extends TestCase
{
    /** @return iterable<string, array{class-string<Payload>, array<string, string>}> */
    public static function messages(): iterable
    {
        $identity = [
            'actor_id' => '018f0000-0000-7000-8000-000000000101',
            'role_id'  => '018f0000-0000-7000-8000-000000000201'
        ];
        $membership = [...$identity, 'permission_id' => '018f0000-0000-7000-8000-000000000301'];
        $named = [...$identity, 'name' => 'ROLE_EDITOR'];
        $revision = ['expected_revision' => '11111111-1111-4111-8111-111111111111:2'];
        $now = '2030-01-01T00:00:00+00:00';
        yield 'create' => [CreateCustomRole::class, $named];
        yield 'grant' => [GrantPermissionToCustomRole::class, $membership];
        yield 'revoke' => [RevokePermissionFromCustomRole::class, $membership];
        yield 'rename' => [RenameCustomRole::class, [...$named, ...$revision]];
        yield 'remove' => [RemoveCustomRole::class, [...$identity, ...$revision]];
        yield 'created' => [CustomRoleCreated::class, [...$named, 'created_at' => $now]];
        yield 'granted' => [CustomRolePermissionGranted::class, [...$membership, 'granted_at' => $now]];
        yield 'revoked' => [CustomRolePermissionRevoked::class, [...$membership, 'revoked_at' => $now]];
        yield 'renamed' => [CustomRoleRenamed::class, [...$named, 'renamed_at' => $now]];
        yield 'removed' => [CustomRoleRemoved::class, [...$identity, 'removed_at' => $now]];
        yield 'get' => [GetRoleById::class, ['role_id' => $identity['role_id']]];
    }

    /**
     * @param class-string<Payload> $class
     * @param array<string, string> $data
     */
    #[DataProvider('messages')]
    public function test_current_round_trips_and_every_required_string_boundary(string $class, array $data): void
    {
        self::assertSame($data, $class::fromArray($data)->toArray());
        foreach ($data as $key => $value) {
            $missing = $data;
            unset($missing[$key]);
            $this->assertRejected($class, $missing);
            foreach (
                [null, 1, true, [], new readonly class ($value) implements Stringable {
                    public function __construct(private string $value)
                    {
                    }

                    public function __toString(): string
                    {
                        return $this->value;
                    }
                }] as $invalid
            ) {
                $this->assertRejected($class, array_replace($data, [$key => $invalid]));
            }

            if (str_ends_with($key, '_at')) {
                $this->assertRejected($class, array_replace($data, [$key => '']));
            }
        }
    }

    /**
     * @param class-string<Payload> $class
     * @param array<string, mixed> $data
     */
    private function assertRejected(string $class, array $data): void
    {
        try {
            $class::fromArray($data);
            self::fail('Malformed '.$class.' input was accepted.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
