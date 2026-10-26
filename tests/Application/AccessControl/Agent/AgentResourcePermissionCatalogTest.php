<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionCatalog;
use Fight\Common\Application\Mcp\Resource\McpReadableResourceProvider;
use Fight\Common\Application\Mcp\Resource\McpResourceContent;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\ResourceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentResourcePermissionBinding::class)]
#[CoversClass(AgentResourcePermissionCatalog::class)]
final class AgentResourcePermissionCatalogTest extends TestCase
{
    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidRequirements(): iterable
    {
        yield 'empty' => [[]];
        yield 'not a list' => [['named' => 'READ_DOCUMENTS']];
        yield 'non-string' => [[42]];
        yield 'empty name' => [['']];
        yield 'lowercase' => [['read_documents']];
        yield 'padding' => [['READ_DOCUMENTS ']];
        yield 'malformed' => [['READ__DOCUMENTS']];
        yield 'terminal newline' => [["READ_DOCUMENTS\n"]];
    }

    /** @param array<array-key, mixed> $requirements */
    #[DataProvider('invalidRequirements')]
    public function test_invalid_requirements_reject_before_provider_work(array $requirements): void
    {
        $provider = $this->createMock(McpReadableResourceProvider::class);
        $provider->expects(self::never())->method('find');
        $provider->expects(self::never())->method('resources');
        $provider->expects(self::never())->method('open');
        $this->expectException(DomainException::class);
        new AgentResourcePermissionBinding($provider, 'docs:one', $requirements);
    }

    public function test_invalid_uri_uses_common_validation_without_lookup(): void
    {
        $provider = $this->createMock(McpReadableResourceProvider::class);
        $provider->expects(self::never())->method('find');
        $this->expectException(DomainException::class);
        new AgentResourcePermissionBinding($provider, '../private', ['READ_DOCUMENTS']);
    }

    public function test_explicit_ownership_is_validated_without_enumeration_or_opening_and_fresh_metadata_works(): void
    {
        $provider = $this->provider();
        $binding = new AgentResourcePermissionBinding($provider, 'docs:one', ['READ_DOCUMENTS', 'VIEW_DOCUMENTS']);
        $catalog = new AgentResourcePermissionCatalog([$binding]);
        self::assertSame([$provider], $catalog->providers());
        self::assertSame($provider, $binding->provider());
        self::assertSame('docs:one', $binding->uri());
        $first = $provider->find('docs:one');
        $fresh = $provider->find('docs:one');
        self::assertNotNull($first);
        self::assertNotNull($fresh);
        self::assertNotSame($first, $fresh);
        self::assertSame($binding->permissions(), $catalog->requirementsFor($fresh));
        self::assertSame(['READ_DOCUMENTS', 'VIEW_DOCUMENTS'], array_map(
            static fn($permission): string => $permission->toString(),
            $binding->permissions()
        ));
        self::assertSame(0, $provider->enumerations);
        self::assertSame([], $provider->opened);
        $provider->metadata['docs:one']['title'] = 'Current dynamic title';
        self::assertNull($catalog->requirementsFor($first));
        $current = $provider->find('docs:one');
        self::assertNotNull($current);
        self::assertSame($binding->permissions(), $catalog->requirementsFor($current));
        $foreign = McpResourceInfo::fromArray(['uri' => 'docs:foreign', 'name' => 'One']);
        self::assertNull($catalog->requirementsFor($foreign));
        unset($provider->metadata['docs:one']);
        self::assertNull($catalog->requirementsFor($first));
        $provider->lookup = static fn(): McpResourceInfo => McpResourceInfo::fromArray([
            'uri' => 'docs:other', 'name' => 'One'
        ]);
        self::assertNull($catalog->requirementsFor($first));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAssociations(): iterable
    {
        foreach (['missing', 'normalized', 'duplicate', 'conflicting owner', 'not binding', 'not list'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidAssociations')]
    public function test_invalid_observable_associations_reject_composition(string $case): void
    {
        $provider = $this->provider();
        $binding = new AgentResourcePermissionBinding($provider, 'docs:one', ['READ_DOCUMENTS']);
        $bindings = [$binding];
        if ($case === 'missing') {
            $provider->metadata = [];
        } elseif ($case === 'normalized') {
            $provider->lookup = static fn(): McpResourceInfo => McpResourceInfo::fromArray([
                'uri' => 'docs:other', 'name' => 'Other'
            ]);
        } elseif ($case === 'duplicate') {
            $bindings[] = new AgentResourcePermissionBinding($provider, 'docs:one', ['OTHER_PERMISSION']);
        } elseif ($case === 'conflicting owner') {
            $bindings[] = new AgentResourcePermissionBinding($this->provider(), 'docs:one', ['READ_DOCUMENTS']);
        } elseif ($case === 'not binding') {
            $bindings = ['docs:one'];
        } else {
            $bindings = ['named' => $binding];
        }

        $this->expectException(DomainException::class);
        new AgentResourcePermissionCatalog($bindings);
    }

    public function test_no_associations_grant_nothing_and_multiple_bindings_reuse_the_exact_provider(): void
    {
        $empty = new AgentResourcePermissionCatalog([]);
        self::assertSame([], $empty->providers());
        $unknown = McpResourceInfo::fromArray(['uri' => 'docs:one', 'name' => 'One']);
        self::assertNull($empty->requirementsFor($unknown));
        $provider = $this->provider();
        $provider->metadata['docs:two'] = ['uri' => 'docs:two', 'name' => 'Two'];
        $catalog = new AgentResourcePermissionCatalog([
            new AgentResourcePermissionBinding($provider, 'docs:one', ['READ_DOCUMENTS']),
            new AgentResourcePermissionBinding($provider, 'docs:two', ['VIEW_DOCUMENTS'])
        ]);
        self::assertSame([$provider], $catalog->providers());
    }

    private function provider(): ResourceProvider
    {
        return new ResourceProvider([
            'docs:one' => [
                'uri'   => 'docs:one',
                'name'  => 'One',
                '_meta' => ['example.org/key' => ['nested' => 'value']]
            ]
        ], static function (): McpResourceContent {
            self::fail('Composition and Permission checks must not open content.');
        });
    }
}
