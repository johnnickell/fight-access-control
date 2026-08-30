<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Authorization\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolutionException;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\ExactPermissionResolver;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExactPermissionResolver::class)]
#[CoversClass(ExactPermissionResolutionException::class)]
final class ExactPermissionResolverTest extends TestCase
{
    public function test_it_returns_snapshots_in_first_requested_identity_order(): void
    {
        $first = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('CONTENT_PUBLISH'),
            new DateTimeImmutable('2026-08-29T12:00:00+00:00')
        );
        $second = Permission::define(
            PermissionId::generate(),
            PermissionName::fromString('CONTENT_REVIEW'),
            new DateTimeImmutable('2026-08-29T12:00:00+00:00')
        );
        $permissions = new InMemoryPermissionRepository();
        $permissions->add($first);
        $permissions->add($second);

        $snapshots = new ExactPermissionResolver($permissions)->resolve([$second->getId(), $first->getId()]);

        self::assertSame([$second->getId(), $first->getId()], array_map(
            static fn($snapshot) => $snapshot->getPermissionId(),
            $snapshots
        ));
    }

    public function test_it_rejects_missing_authoritative_definitions(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $second = $this->permission('CONTENT_REVIEW');
        $permissions = new InMemoryPermissionRepository(getByIdsResult: static fn(): array => [$first]);

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissions)->resolve([$first->getId(), $second->getId()]);
    }

    public function test_it_rejects_unexpected_authoritative_definitions(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $second = $this->permission('CONTENT_REVIEW');
        $unexpected = $this->permission('CONTENT_DELETE');
        $permissions = new InMemoryPermissionRepository(
            getByIdsResult: static fn(): array => [$first, $second, $unexpected]
        );

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissions)->resolve([$first->getId(), $second->getId()]);
    }

    public function test_it_rejects_duplicated_authoritative_definitions(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $second = $this->permission('CONTENT_REVIEW');
        $permissions = new InMemoryPermissionRepository(getByIdsResult: static fn(): array => [$first, $first]);

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissions)->resolve([$first->getId(), $second->getId()]);
    }

    public function test_it_rejects_an_unresolved_stale_identity(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $unexpected = $this->permission('CONTENT_DELETE');
        $permissions = new InMemoryPermissionRepository(
            getByIdsResult: static fn(): array => [$first, $unexpected]
        );

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissions)->resolve([$first->getId(), PermissionId::generate()]);
    }

    private function permission(string $name): Permission
    {
        return Permission::define(
            PermissionId::generate(),
            PermissionName::fromString($name),
            new DateTimeImmutable('2026-08-29T12:00:00+00:00')
        );
    }
}
