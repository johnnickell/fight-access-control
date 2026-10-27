<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture;

use Fight\Common\Application\Mcp\Skill\McpSkillFile;
use Fight\Common\Application\Mcp\Skill\McpSkillFrontmatterParser;
use Fight\Common\Application\Mcp\Skill\McpSkillLimits;
use Fight\Common\Application\Mcp\Skill\McpSkillRevision;
use Fight\Common\Domain\Value\Basic\StrictJson;

final class SkillRevision
{
    /** @param list<McpSkillFile> $extra */
    public static function create(
        string $version = 'v1',
        string $description = 'Protected work',
        array $extra = []
    ): McpSkillRevision {
        // This controlled parser port exports the authored fixture fields, not a production YAML parser.
        $parser = new readonly class ($description) implements McpSkillFrontmatterParser {
            public function __construct(private string $description)
            {
            }

            public function parse(string $yaml, McpSkillLimits $limits): StrictJson
            {
                return StrictJson::fromObject([
                    'name'        => 'work',
                    'description' => $this->description,
                    'unknown'     => [
                        'values' => [true, null, 1, 1.0, '01', '雪'], 'empty' => StrictJson::fromObject([])
                    ]
                ]);
            }
        };

        return McpSkillRevision::fromFiles('skill://catalog/'.$version.'/work/SKILL.md', [
            McpSkillFile::fromBytes(
                'SKILL.md',
                "---\nname: work\ndescription: ".$description."\n---\nInert instructions\r\n"
            ),
            McpSkillFile::fromBytes('references/guide.md', "é\r\n<script>inert</script>\n"),
            McpSkillFile::fromBytes('assets/data.bin', "\x00\xff\x01\x80", 'application/octet-stream', false),
            ...$extra
        ], $parser);
    }
}
