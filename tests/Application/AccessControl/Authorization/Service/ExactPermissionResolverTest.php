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
        $permissionRepository = new InMemoryPermissionRepository();
        $permissionRepository->add($first);
        $permissionRepository->add($second);

        $snapshots = new ExactPermissionResolver($permissionRepository)->resolve([$second->getId(), $first->getId()]);

        self::assertSame([$second->getId(), $first->getId()], array_map(
            static fn($snapshot) => $snapshot->getPermissionId(),
            $snapshots
        ));
    }

    public function test_it_rejects_missing_authoritative_definitions(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $second = $this->permission('CONTENT_REVIEW');
        $permissionRepository = new InMemoryPermissionRepository(getByIdsResult: static fn(): array => [$first]);

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissionRepository)->resolve([$first->getId(), $second->getId()]);
    }

    public function test_it_rejects_unexpected_authoritative_definitions(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $second = $this->permission('CONTENT_REVIEW');
        $unexpected = $this->permission('CONTENT_DELETE');
        $permissionRepository = new InMemoryPermissionRepository(
            getByIdsResult: static fn(): array => [$first, $second, $unexpected]
        );

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissionRepository)->resolve([$first->getId(), $second->getId()]);
    }

    public function test_it_rejects_duplicated_authoritative_definitions(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $second = $this->permission('CONTENT_REVIEW');
        $permissionRepository = new InMemoryPermissionRepository(
            getByIdsResult: static fn(): array => [$first, $first]
        );

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissionRepository)->resolve([$first->getId(), $second->getId()]);
    }

    public function test_it_rejects_an_unresolved_stale_identity(): void
    {
        $first = $this->permission('CONTENT_PUBLISH');
        $unexpected = $this->permission('CONTENT_DELETE');
        $permissionRepository = new InMemoryPermissionRepository(
            getByIdsResult: static fn(): array => [$first, $unexpected]
        );

        $this->expectException(ExactPermissionResolutionException::class);
        new ExactPermissionResolver($permissionRepository)->resolve([$first->getId(), PermissionId::generate()]);
    }

    public function test_it_rejects_non_list_wrong_purpose_and_duplicate_requested_identities(): void
    {
        $permission = $this->permission('CONTENT_READ');
        foreach ([['key' => $permission->getId()], [null], [$permission->getId(), $permission->getId()]] as $ids) {
            $repository = new InMemoryPermissionRepository(getByIdsResult: static fn(): array => [$permission]);
            try {
                new ExactPermissionResolver($repository)->resolve($ids);
                self::fail('Requested identities must form a valid exact set.');
            } catch (ExactPermissionResolutionException $failure) {
                self::assertNull($failure->getPrevious());
            }
        }
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
