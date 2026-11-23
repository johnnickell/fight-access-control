<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\Planning;

final readonly class Views
{
    private const string TASK_COLUMNS = <<<'MARKDOWN'
        | Order | TASK ID | Title | Parent TICKET | Status | Blocked by | PR |
        | --- | --- | --- | --- | --- | --- | --- |
        MARKDOWN;

    public function __construct(private string $planning, private array $all)
    {
    }

    public function generate(array $live): array
    {
        $epics = Record::select($live, static fn (Record $record): bool => str_starts_with($record->id(), 'EPIC-'));
        $tickets = Record::select($live, static fn (Record $record): bool => str_starts_with($record->id(), 'TICKET-'));
        $tasks = Record::select($live, static fn (Record $record): bool => str_starts_with($record->id(), 'TASK-'));
        $views = [
            "$this->planning/epics/README.md"   => $this->index('epics', 'EPICs', $epics),
            "$this->planning/tickets/README.md" => $this->index('tickets', 'TICKETs', $tickets),
            "$this->planning/tasks/README.md"   => $this->taskIndex($tasks),
            "$this->planning/tasks/BOARD.md"    => $this->board($tasks),
            "$this->planning/ROADMAP.md"        => $this->roadmap($live, $epics)
        ];
        // Keep record traversal order for diagnostics, independent of row ordering.
        foreach ($live as $record) {
            if (str_starts_with($record->id(), 'EPIC-')) {
                $children = $this->children($live, 'epic', $record->id());
                $heading = '## Child Tickets';
                $empty = 'No Tickets recorded.';
                $columns = "| TICKET ID | Title | Status |\n| --- | --- | --- |";
                $hasOrder = false;
            } elseif (str_starts_with($record->id(), 'TICKET-')) {
                $children = $this->children($live, 'ticket', $record->id());
                $heading = '## Child Tasks';
                $empty = 'No Tasks recorded.';
                $columns = "| Order | TASK ID | Title | Status |\n| --- | --- | --- | --- |";
                $hasOrder = true;
            } else {
                continue;
            }

            $rows = [$columns];
            foreach ($children as $child) {
                $order = $hasOrder ? $child->fields['order'].' | ' : '';
                $rows[] = "| $order".$this->recordCells(dirname($record->path), $child).' |';
            }

            $content = $children === [] ? $empty : implode("\n", $rows);
            $views[$record->path] = self::replaceSection(Files::read($record->path), $heading, $content);
        }

        return $views;
    }

    public function board(array $tasks): string
    {
        $source = "$this->planning/tasks";
        $groups = array_fill_keys([
            'In Progress', 'Ready Frontier', 'Waiting', 'Needs Info', 'Human Action', 'Triage', 'Recently Done'
        ], []);
        foreach ($tasks as $task) {
            $unblocked = array_all($task->blockers(), fn (string $id): bool => $this->all[$id]->terminal());
            $group = match ($task->fields['status']) {
                'in-progress' => 'In Progress',
                'ready-for-agent' => $unblocked ? 'Ready Frontier' : 'Waiting',
                'needs-info' => 'Needs Info',
                'ready-for-human' => 'Human Action',
                'needs-triage' => 'Triage',
                'done', 'wontfix' => 'Recently Done',
            };
            $groups[$group][] = $task;
        }

        $active = $groups['In Progress'][0] ?? null;
        $activeLabel = $active === null ? '' : 'Active Task: '.$this->label($source, $active).'.';
        $ready = $groups['Ready Frontier'][0] ?? null;
        $info = $groups['Needs Info'][0] ?? null;
        $triage = $groups['Triage'][0] ?? null;
        $missing = 'This Task is not executable until the missing information is resolved.';
        $untriaged = 'This Task is not executable until it is triaged.';
        $empty = 'No non-terminal Task exists. Any future release decision requires separate human authorization.';
        $next = match (true) {
            isset($groups['Human Action'][0]) => rtrim(
                'Human decision required: '.$this->label($source, $groups['Human Action'][0]).'. '.$activeLabel
            ),
            $active !== null => $activeLabel,
            $ready !== null => 'First ready Task: '.$this->label($source, $ready).'.',
            $info !== null => 'Needs information: '.$this->label($source, $info).". $missing",
            $triage !== null => 'Needs triage: '.$this->label($source, $triage).". $untriaged",
            default => $empty,
        };
        $board = <<<MARKDOWN
            # Task Board

            This generated execution view preserves the portfolio. Individual Task records are canonical for scope,
            dependencies, acceptance, and verification.

            ## What's Next?

            $next
            MARKDOWN;
        foreach ($groups as $heading => $records) {
            $rows = self::TASK_COLUMNS."\n".$this->taskRows($records);
            $table = $records === [] ? 'No Tasks are currently in this state.' : $rows;
            $board .= "\n\n## $heading\n\n$table";
        }

        return $board."\n";
    }

    private static function replaceSection(string $text, string $heading, string $content): string
    {
        $pattern = '~^'.preg_quote($heading, '~').'\n.*?(?=^## |\z)~ms';
        $replacement = "$heading\n\n".rtrim($content)."\n";
        if (preg_match($pattern, $text) !== 1) {
            return rtrim($text)."\n\n$replacement";
        }

        return preg_replace_callback($pattern, static fn (): string => $replacement, $text);
    }

    private function taskIndex(array $tasks): string
    {
        $columns = self::TASK_COLUMNS;
        $rows = $this->taskRows($tasks);
        $standalone = 'standalone bugs and chores leave `ticket` empty and declare `kind`.';
        $projection = 'generated index is a projection; Task records are canonical';

        return <<<MARKDOWN
            # Tasks

            Tasks are executable vertical slices. Their `ticket` frontmatter normally connects them to a parent Ticket;
            $standalone `blocked_by` contains only Task IDs. This
            $projection for scope, dependencies, acceptance, and verification.
            The [Task Board](BOARD.md) is the operational projection.

            <!-- generated:task-index:start -->
            $columns
            $rows
            <!-- generated:task-index:end -->

            MARKDOWN;
    }

    private function taskRows(array $tasks): string
    {
        $source = "$this->planning/tasks";
        $rows = [];
        foreach ($tasks as $task) {
            $blockers = array_map(fn (string $id): string => $this->link($source, $this->all[$id]), $task->blockers());
            $blockedBy = $blockers === [] ? '—' : implode(', ', $blockers);
            $pr = $task->fields['pr'] ?? '—';
            $parent = $this->all[$task->fields['ticket'] ?? ''] ?? null;
            $label = $parent === null ? "— (standalone {$task->fields['kind']})" : $this->label($source, $parent);
            $cells = [$task->fields['order'], $this->link($source, $task), $task->fields['title'], $label];
            array_push($cells, $task->fields['status'], $blockedBy, $pr);
            $rows[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $rows);
    }

    private function index(string $directory, string $heading, array $records): string
    {
        $rows = implode("\n", array_map(
            fn (Record $record): string => '| '.$this->recordCells("$this->planning/$directory", $record).' |',
            $records
        ));

        return <<<MARKDOWN
            # $heading

            This generated index is a projection; individual records remain canonical.

            | ID | Title | Status |
            | --- | --- | --- |
            $rows

            MARKDOWN;
    }

    private function roadmap(array $live, array $epics): string
    {
        $rows = [
            '<!-- generated:epic-status:start -->',
            '| EPIC | Target | Status | Tickets | Tasks |',
            '| --- | --- | --- | --- | --- |'
        ];
        foreach ($epics as $epic) {
            $tickets = $this->children($live, 'epic', $epic->id());
            $tasks = 0;
            foreach ($tickets as $ticket) {
                $tasks += count($this->children($live, 'ticket', $ticket->id()));
            }

            $target = $epic->fields['target'] ?? '—';
            $ticketCount = count($tickets);
            $link = $this->link($this->planning, $epic);
            $rows[] = "| $link | $target | {$epic->fields['status']} | $ticketCount | $tasks |";
        }

        $rows[] = '<!-- generated:epic-status:end -->';

        $roadmap = Files::read("$this->planning/ROADMAP.md");

        return self::replaceSection($roadmap, '## Record Status Projection', implode("\n", $rows));
    }

    private function children(array $records, string $key, string $parent): array
    {
        return Record::select($records, static fn (Record $record): bool => ($record->fields[$key] ?? '') === $parent);
    }

    private function recordCells(string $source, Record $record): string
    {
        return $this->link($source, $record)." | {$record->fields['title']} | {$record->fields['status']}";
    }

    private function label(string $source, Record $record): string
    {
        return $this->link($source, $record).' — '.$record->fields['title'];
    }

    private function link(string $source, Record $record): string
    {
        return '['.$record->id().']('.Files::relative($source, $record->path).')';
    }
}
