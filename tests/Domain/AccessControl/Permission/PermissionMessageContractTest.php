<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Permission;

use Fight\AccessControl\Domain\AccessControl\Permission\Query\GetPermissionById;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\ListPermissions;
use Fight\AccessControl\Domain\AccessControl\Role\Query\ListRoles;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(GetPermissionById::class)]
#[CoversClass(ListPermissions::class)]
#[CoversClass(ListRoles::class)]
final class PermissionMessageContractTest extends TestCase
{
    public function test_permission_identity_requires_a_serialized_string(): void
    {
        $id = '018f0000-0000-7000-8000-000000000101';
        self::assertSame(['permission_id' => $id], GetPermissionById::fromArray(['permission_id' => $id])->toArray());
        $object = new readonly class ($id) implements Stringable {
            public function __construct(private string $id)
            {
            }

            public function __toString(): string
            {
                return $this->id;
            }
        };
        $this->expectException(DomainException::class);
        GetPermissionById::fromArray(['permission_id' => $object]);
    }

    public function test_pagination_messages_preserve_integer_and_ordering_shapes(): void
    {
        $valid = ['page' => 2, 'per_page' => 10, 'orderings' => ['name' => 'DESC']];
        foreach ([ListPermissions::class, ListRoles::class] as $class) {
            self::assertSame($valid, $class::fromArray($valid)->toArray());
            foreach (array_keys($valid) as $key) {
                $missing = $valid;
                unset($missing[$key]);
                try {
                    $class::fromArray($missing);
                    self::fail('A missing pagination field must reject.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }

            foreach (
                [
                    ['page' => '2'], ['per_page' => '10'], ['page' => null], ['page' => true],
                    ['per_page' => 1.5], ['orderings' => 'name'], ['orderings' => ['name' => 1]],
                    ['orderings' => ['DESC']]
                ] as $invalid
            ) {
                try {
                    $class::fromArray(array_replace($valid, $invalid));
                    self::fail('Malformed pagination must not be coerced into a different request.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }
}
