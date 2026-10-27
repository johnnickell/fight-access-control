<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent;

use Fight\AccessControl\Application\AccessControl\Agent\AgentSkillPermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentSkillPermissionCatalog;
use Fight\Common\Application\Mcp\Skill\McpSkillFile;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture\SkillRevision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentSkillPermissionBinding::class)]
#[CoversClass(AgentSkillPermissionCatalog::class)]
final class AgentSkillPermissionCatalogTest extends TestCase
{
    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidRequirements(): iterable
    {
        yield 'empty' => [[]];
        yield 'not list' => [['named' => 'READ_SKILLS']];
        yield 'non-string' => [[42]];
        yield 'empty name' => [['']];
        yield 'lowercase' => [['read_skills']];
        yield 'padding' => [['READ_SKILLS ']];
        yield 'malformed' => [['READ__SKILLS']];
        yield 'newline' => [["READ_SKILLS\n"]];
    }

    /** @param array<array-key, mixed> $requirements */
    #[DataProvider('invalidRequirements')]
    public function test_invalid_requirements_reject_composition(array $requirements): void
    {
        $this->expectException(DomainException::class);
        new AgentSkillPermissionBinding(SkillRevision::create(), $requirements);
    }

    public function test_validated_revisions_and_complete_exports_not_object_identity_own_requirements(): void
    {
        $revision = SkillRevision::create();
        $historical = SkillRevision::create('v0');
        $binding = new AgentSkillPermissionBinding($revision, ['VIEW_SKILLS', 'READ_SKILLS']);
        $catalog = new AgentSkillPermissionCatalog([
            $binding, new AgentSkillPermissionBinding($historical, ['READ_HISTORY'])
        ]);
        self::assertSame([$revision, $historical], $catalog->revisions());
        self::assertSame($revision, $binding->revision());
        self::assertSame(['VIEW_SKILLS', 'READ_SKILLS'], array_map(
            static fn($permission): string => $permission->toString(),
            $binding->permissions()
        ));
        $fresh = StrictJson::fromString($revision->entry()->toString());
        self::assertNotSame($revision->entry(), $fresh);
        self::assertSame($binding->permissions(), $catalog->requirementsFor($fresh));
        self::assertSame('READ_HISTORY', $catalog->requirementsFor($historical->entry())[0]->toString());
        self::assertSame($binding->permissions(), $catalog->requirementsFor(SkillRevision::create()->entry()));
        self::assertNull($catalog->requirementsFor(
            SkillRevision::create(description: 'Different immutable bytes')->entry()
        ));
        self::assertNull($catalog->requirementsFor(SkillRevision::create('foreign')->entry()));
        self::assertSame([], new AgentSkillPermissionCatalog([])->revisions());
        self::assertNull(new AgentSkillPermissionCatalog([])->requirementsFor($fresh));
    }

    /** @return iterable<string, array{StrictJson}> */
    public static function invalidEntries(): iterable
    {
        $entry = SkillRevision::create()->entry();
        yield 'not object' => [StrictJson::fromData([])];
        yield 'missing uri' => [StrictJson::fromObject([])];
        yield 'typed uri' => [$entry->with('uri', 42)];
        yield 'unknown' => [$entry->with('uri', 'skill://catalog/unknown/work/SKILL.md')];
        yield 'alias' => [$entry->with('uri', 'skill://catalog/v1/work/%53KILL.md')];
        yield 'partial manifest' => [$entry->with('resources', [])];
        yield 'changed digest' => [$entry->with('resources', [
            $entry->get('resources')[0]->with('digest', 'sha256:'.str_repeat('0', 64)),
            ...array_slice($entry->get('resources'), 1)
        ])];
        yield 'changed size type' => [$entry->with('resources', [
            $entry->get('resources')[0]->with('size', (string) $entry->get('resources')[0]->get('size')),
            ...array_slice($entry->get('resources'), 1)
        ])];
        yield 'typed nested frontmatter' => [
            $entry->with('frontmatter', $entry->get('frontmatter')->with('unknown', true))
        ];
        yield 'extra field' => [$entry->with('extra', 'not the immutable export')];
    }

    #[DataProvider('invalidEntries')]
    public function test_inexact_or_malformed_entries_cannot_borrow_revision_authority(StrictJson $entry): void
    {
        $catalog = new AgentSkillPermissionCatalog([
            new AgentSkillPermissionBinding(SkillRevision::create(), ['READ_SKILLS'])
        ]);
        self::assertNull($catalog->requirementsFor($entry));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAssociations(): iterable
    {
        foreach (
            [
            'not list', 'not binding', 'duplicate', 'conflicting requirements', 'changed revision', 'overlap'
            ] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidAssociations')]
    public function test_observable_invalid_or_conflicting_associations_reject_statically(string $case): void
    {
        $binding = new AgentSkillPermissionBinding(SkillRevision::create(), ['READ_SKILLS']);
        $bindings = match ($case) {
            'not list' => ['named' => $binding],
            'not binding' => [SkillRevision::create()],
            'duplicate' => [$binding, $binding],
            'conflicting requirements' => [
                $binding, new AgentSkillPermissionBinding(SkillRevision::create(), ['OTHER_PERMISSION'])
            ],
            'changed revision' => [
                $binding,
                new AgentSkillPermissionBinding(SkillRevision::create(description: 'Changed'), ['READ_SKILLS'])
            ],
            'overlap' => [
                new AgentSkillPermissionBinding(SkillRevision::create(
                    extra: [McpSkillFile::fromBytes('work/SKILL.md', 'Overlapping parent member')]
                ), ['READ_SKILLS']),
                new AgentSkillPermissionBinding(SkillRevision::create('v1/work'), ['READ_SKILLS'])
            ],
            default => throw new DomainException('Unknown case.')
        };
        $this->expectException(DomainException::class);
        new AgentSkillPermissionCatalog($bindings);
    }
}
