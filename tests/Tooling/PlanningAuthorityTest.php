<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class PlanningAuthorityTest extends TestCase
{
    private string $root;

    public function test_that_repository_guidance_resolves_local_authority_and_completion(): void
    {
        $agents = $this->read('AGENTS.md');
        $claude = $this->read('CLAUDE.md');
        $context = $this->read('CONTEXT.md');

        self::assertStringContainsString('[`AGENTS.md`](AGENTS.md)', $claude);
        self::assertStringContainsString('planning/agents/', $agents);
        self::assertStringContainsString('./bin/planning-check', $agents);
        self::assertStringContainsString('./bin/build', $agents);
        self::assertStringContainsString('feature/', $agents);
        self::assertStringContainsString('.runs/', $agents);
        self::assertStringContainsString('/worktree', $agents);

        self::assertStringContainsString('Domain <- Application', $context);
        self::assertStringContainsString('no production Adapter layer', $context);
        self::assertStringContainsString('PRD-00001', $context);
        self::assertStringContainsString('planning/tickets/', $context);
    }

    public function test_that_prd_00001_is_the_valid_local_behavioral_authority(): void
    {
        $spec = $this->read('planning/specs/00001-PRD.md');
        $index = $this->read('planning/specs/README.md');
        $provenance = $this->read('planning/provenance/fight-common-bootstrap.md');

        self::assertStringContainsString('id: PRD-00001', $spec);
        self::assertStringContainsString('epic: EPIC-00001', $spec);
        self::assertStringNotContainsString('epic: EPIC-00004', $spec);
        self::assertStringContainsString('repository-local behavioral and security authority', $spec);
        self::assertStringContainsString('[00001](00001-PRD.md)', $index);
        self::assertStringContainsString('1b58c1455225965cbadcd35b6899d642a2141140', $provenance);
        self::assertStringContainsString('planning/specs/00016-PRD.md', $provenance);
        self::assertStringContainsString(
            'a23423dd933935554c559821f3009bb302fafc4ba39f74e64c62b4817ce3e24b',
            $provenance
        );
        self::assertStringContainsString('planning/specs/00017-PRD.md', $provenance);
        self::assertStringContainsString(
            '1890912f75896f8044882b9c5a6ec37f33dae6ab8f0f91e9d3d0e502c8d5793d',
            $provenance
        );
    }

    public function test_that_the_local_roadmap_epic_and_accepted_foundation_decisions_are_indexed(): void
    {
        $roadmap = $this->read('planning/ROADMAP.md');
        $epicIndex = $this->read('planning/epics/README.md');
        $epic = $this->read('planning/epics/00001-EPIC.md');
        $adrIndex = $this->read('planning/adr/README.md');
        $architecture = $this->read('planning/adr/0001-domain-application-package-boundary.md');
        $quality = $this->read('planning/adr/0002-single-quality-gate.md');

        self::assertStringContainsString('[EPIC-00001](epics/00001-EPIC.md)', $roadmap);
        self::assertStringContainsString('[00001](00001-EPIC.md)', $epicIndex);
        self::assertStringContainsString('id: EPIC-00001', $epic);
        self::assertStringContainsString('PRD-00001', $epic);
        self::assertStringContainsString('[0001](0001-domain-application-package-boundary.md)', $adrIndex);
        self::assertStringContainsString('[0002](0002-single-quality-gate.md)', $adrIndex);
        self::assertStringContainsString('- Status: accepted', $architecture);
        self::assertStringContainsString('Domain <- Application', $architecture);
        self::assertStringContainsString('no production Adapter layer', $architecture);
        self::assertStringContainsString('- Status: accepted', $quality);
        self::assertStringContainsString('./bin/quality', $quality);
        self::assertStringContainsString('./bin/build', $quality);
    }

    public function test_that_ticket_readiness_is_local_and_the_completed_capability_is_indexed(): void
    {
        $tracker = $this->read('planning/agents/issue-tracker.md');
        $triage = $this->read('planning/agents/triage-labels.md');
        $tickets = $this->read('planning/tickets/README.md');
        $board = $this->read('planning/tickets/BOARD.md');
        $completedTicket = $this->read('planning/tickets/00017-TICKET.md');
        $completedFrontier = $this->read('planning/tickets/00018-TICKET.md');

        self::assertStringContainsString('ready-for-agent', $tracker);
        self::assertStringContainsString('blocked_by', $tracker);
        self::assertStringContainsString('ready-for-agent', $triage);
        self::assertStringContainsString('ready-for-human', $triage);
        self::assertStringContainsString('Ticket files are canonical', $tickets);
        self::assertStringContainsString('Ready Frontier', $board);
        self::assertStringContainsString('What’s Next?', $board);
        self::assertStringContainsString('Recently Done', $board);
        self::assertStringContainsString('T-00018', $board);
        self::assertStringContainsString('id: T-00018', $completedFrontier);
        self::assertStringContainsString('prd: PRD-00001', $completedFrontier);
        self::assertStringContainsString('status: done', $completedFrontier);
        self::assertStringContainsString('id: T-00017', $completedTicket);
        self::assertStringContainsString('status: done', $completedTicket);
        self::assertStringContainsString('blocked_by:', $completedTicket);

        self::assertFileExists($this->root.'/planning/CONVENTIONS.md');
        self::assertFileExists($this->root.'/planning/tickets/_TICKET_TEMPLATE.md');
        self::assertFileExists($this->root.'/planning/wayfinder/_MAP_TEMPLATE.md');
        self::assertFileExists($this->root.'/planning/wayfinder/tickets/_WAYFINDER_TICKET_TEMPLATE.md');
        self::assertFileExists($this->root.'/planning/tickets/archive/README.md');
        self::assertFileExists($this->root.'/bin/archive-planning');
    }

    public function test_that_the_repository_validator_accepts_the_indexed_local_authority(): void
    {
        $process = new Process(['python3', 'scripts/planning_portfolio.py'], $this->root);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertStringContainsString('Planning validation passed: 26 records, 7 active', $process->getOutput());
    }

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root.'/'.$path);
        self::assertNotFalse($contents, sprintf('Expected repository-local authority file %s.', $path));

        return $contents;
    }
}
