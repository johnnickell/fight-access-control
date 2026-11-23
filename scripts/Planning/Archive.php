<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\Planning;

use RuntimeException;

final readonly class Archive
{
    private string $planning;

    public function __construct(private string $root)
    {
        $this->planning = "$root/planning";
    }

    public function run(string $kind, array $identifiers, bool $apply): int
    {
        try {
            if ($kind === 'wayfinder') {
                $moves = $this->wayfinder($identifiers);
            } else {
                $moves = $this->portfolioMoves($kind, $identifiers);
            }

            foreach ($moves as $source => $destination) {
                if (file_exists($destination) || is_link($destination)) {
                    throw new RuntimeException('an archive destination already exists');
                }

                if (Files::resolve($source) !== $source || Files::resolve($destination) !== $destination) {
                    throw new RuntimeException('archive paths must not cross symbolic links');
                }
            }
        } catch (RuntimeException $runtimeException) {
            fwrite(STDERR, 'Archive refused: '.$runtimeException->getMessage().PHP_EOL);

            return 2;
        }

        $action = $apply ? 'Archive' : 'Would archive';
        foreach ($moves as $source => $destination) {
            $from = Files::relative($this->root, $source);
            $to = Files::relative($this->root, $destination);
            printf("%s: %s -> %s\n", $action, $from, $to);
        }

        if (!$apply) {
            echo "Dry run only. Re-run with --apply after reviewing these moves.\n";

            return 0;
        }

        foreach ($moves as $source => $destination) {
            Files::mkdir(dirname($destination));
            if (!@rename($source, $destination)) {
                throw new RuntimeException("Cannot move archive record: $source");
            }
        }

        $this->rewriteLinks($moves);
        $portfolio = new Portfolio($this->root);
        $status = $portfolio->run(true);

        return $status === 0 ? $portfolio->run(false) : $status;
    }

    private static function closed(string $text): bool
    {
        return preg_match('/^\*\*Status:\*\*\s*(.+?)\s*$/m', $text, $match) === 1
            && strtolower(trim($match[1])) === 'closed';
    }

    private function portfolioMoves(string $kind, array $identifiers): array
    {
        $tasks = $this->records('tasks');
        $tickets = $kind === 'tasks' ? [] : $this->records('tickets');
        $current = match ($kind) {
            'tasks' => $tasks,
            'tickets' => $tickets,
            'epics' => $this->records('epics'),
        };
        $selected = [];
        foreach ($identifiers as $id) {
            $selected[$id] = $current[$id] ?? throw new RuntimeException(var_export($id, true));
        }

        $this->requireTerminal($selected);
        $selectedTickets = $kind === 'tickets' ? $selected : [];
        if ($kind === 'epics') {
            foreach ($selected as $id => $_epic) {
                $this->requireTerminal($this->children($tickets, 'epic', [$id => true]));
                foreach ($this->children($tickets, 'epic', [$id => true]) as $ticketId => $_ticket) {
                    $this->requireTerminal($this->children($tasks, 'ticket', [$ticketId => true]));
                }
            }

            $selectedTickets = $this->children($tickets, 'epic', $selected);
        }

        if ($kind === 'tickets') {
            foreach ($selected as $id => $_ticket) {
                $this->requireTerminal($this->children($tasks, 'ticket', [$id => true]));
            }
        }

        $selectedTasks = $kind === 'tasks' ? $selected : $this->children($tasks, 'ticket', $selectedTickets);
        $dependents = [];
        foreach (array_diff_key($tasks, $selectedTasks) as $id => $task) {
            $blockers = array_values(array_unique(array_intersect($task->blockers(), array_keys($selectedTasks))));
            if ($blockers !== []) {
                sort($blockers, SORT_STRING);
                $dependents[] = "$id depends on ".implode(', ', $blockers);
            }
        }

        if ($dependents !== []) {
            throw new RuntimeException('selected Tasks remain live blockers: '.implode('; ', $dependents));
        }

        $moves = [];
        foreach ($selected + $selectedTickets + $selectedTasks as $record) {
            $moves[$record->path] = dirname($record->path).'/archive/'.basename($record->path);
        }

        return $moves;
    }

    private function records(string $directory): array
    {
        $records = [];
        $suffix = Record::KINDS[$directory];
        foreach (glob("$this->planning/$directory/*-$suffix.md", GLOB_NOSORT) ?: [] as $path) {
            $fields = Files::frontmatter($path, false);
            if (($fields['id'] ?? '') !== '') {
                $records[$fields['id']] = new Record($path, $fields);
            }
        }

        return $records;
    }

    private function requireTerminal(array $records): void
    {
        foreach ($records as $record) {
            if (!$record->terminal()) {
                throw new RuntimeException(Files::relative($this->root, $record->path).' is not terminal');
            }
        }
    }

    private function children(array $records, string $field, array $parents): array
    {
        return array_filter($records, static fn (Record $child): bool => isset($parents[$child->fields[$field] ?? '']));
    }

    private function wayfinder(array $identifiers): array
    {
        if (count($identifiers) !== 1) {
            throw new RuntimeException('archive wayfinder accepts exactly one map name');
        }

        $name = $identifiers[0];
        $filename = str_ends_with($name, '.md') ? $name : "$name.md";
        $path = "$this->planning/wayfinder/$filename";
        if (basename($filename) !== $filename || !is_file($path)) {
            throw new RuntimeException("unknown Wayfinder map: $name");
        }

        $label = Files::relative($this->root, $path);
        $text = Files::read($path);
        if (!self::closed($text)) {
            throw new RuntimeException("$label is not Closed");
        }

        if (
            preg_match('~^## Frontier\s*\n+(.*?)(?=^## |\z)~ms', $text, $frontier) !== 1
            || preg_match('/\b(?:none|no wayfinder decision remains|no remaining frontier)\b/i', $frontier[1]) !== 1
        ) {
            throw new RuntimeException("$label still has a Wayfinder frontier");
        }

        preg_match_all('~\]\(tickets/(WF-\d{3}[^/)#]+\.md)\)~', $text, $links);
        if ($links[1] === []) {
            throw new RuntimeException("$label has no linked decision tickets");
        }

        foreach ($links[1] as $ticket) {
            $ticketPath = "$this->planning/wayfinder/tickets/$ticket";
            if (!is_file($ticketPath) || !self::closed(Files::read($ticketPath))) {
                throw new RuntimeException("$label has unresolved decision tickets");
            }
        }

        if (preg_match('~\]\(\.\./(?:epics|tickets|tasks)/~', $text) !== 1) {
            throw new RuntimeException("$label lacks a linked implementation handoff");
        }

        $moves = [$path => "$this->planning/wayfinder/archive/maps/$filename"];
        foreach ($links[1] as $ticket) {
            $moves["$this->planning/wayfinder/tickets/$ticket"] = "$this->planning/wayfinder/tickets/archive/$ticket";
            $research = substr($ticket, 0, -3).'-research.md';
            $researchPath = "$this->planning/wayfinder/research/$research";
            if (is_file($researchPath)) {
                $moves[$researchPath] = "$this->planning/wayfinder/research/archive/$research";
            }
        }

        return $moves;
    }

    private function rewriteLinks(array $moves): void
    {
        $sources = array_flip($moves);
        $excluded = array_map(
            fn (string $directory): string => "$this->root/$directory",
            ['.git', '.runs', 'vendor', 'var', 'graphify-out']
        );
        foreach (Files::markdown($this->root, '.md', $excluded) as $destination) {
            if (is_link($destination)) {
                continue;
            }

            $source = $sources[$destination] ?? $destination;
            $text = Files::read($destination);
            $replaceLink = static function (array $match) use ($source, $destination, $moves): string {
                $parts = explode('#', $match[2], 2);
                $path = $parts[0];
                if ($path === '' || preg_match('~^(?:/|https?:|mailto:)~', $path) === 1) {
                    return $match[0];
                }

                $oldTarget = Files::resolve(dirname($source).'/'.$path);
                $newTarget = $moves[$oldTarget] ?? $oldTarget;
                if ($source === $destination && $oldTarget === $newTarget) {
                    return $match[0];
                }

                $anchor = isset($parts[1]) ? '#'.$parts[1] : '';

                return $match[1].Files::relative(dirname($destination), $newTarget).$anchor.$match[3];
            };
            $rewritten = preg_replace_callback('~(!?\[[^\]]*\]\()([^\s)]+)(\))~', $replaceLink, $text);
            if ($rewritten !== $text) {
                Files::write($destination, $rewritten);
            }
        }
    }
}
