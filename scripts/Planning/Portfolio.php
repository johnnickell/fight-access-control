<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\Planning;

use RuntimeException;

final readonly class Portfolio
{
    private string $planning;

    public function __construct(private string $root)
    {
        $this->planning = "$root/planning";
    }

    public function run(bool $write): int
    {
        $errors = [];
        $live = $this->records(false, $errors);
        $archived = $this->records(true, $errors);
        $overlap = array_keys(array_intersect_key($live, $archived));
        if ($overlap !== []) {
            sort($overlap, SORT_STRING);
            $errors[] = 'record is both live and archived: '.implode(', ', $overlap);
        }

        $all = $archived + $live;
        $this->validateRecords($all, $errors);
        $this->validateLinks($errors);
        if ($this->git('check-ignore', '-q', '.runs/planning-check') === null) {
            $errors[] = '.runs must be ignored';
        }

        if ($errors !== []) {
            echo 'Planning validation failed:', PHP_EOL, '- ', implode("\n- ", $errors), PHP_EOL;

            return 1;
        }

        $closures = $this->completeParents($all);
        $views = new Views($this->planning, $all)->generate($live);
        foreach ($closures as $path => $header) {
            $body = self::splitDocument($views[$path] ?? Files::read($path), $path)[1];
            $views[$path] = "---\n$header\n---\n$body";
        }

        $stale = array_filter(
            $views,
            static fn (string $text, string $path): bool => Files::read($path) !== $text,
            ARRAY_FILTER_USE_BOTH
        );
        if ($write) {
            foreach ($stale as $path => $text) {
                Files::write($path, $text);
            }
        } elseif ($stale !== []) {
            $head = $this->git('rev-parse', '--verify', 'HEAD') ?? 'unavailable';
            echo "Planning validation failed: generated planning files are stale.\n";
            echo "Checked working tree at Git HEAD: $head\n";
            foreach (array_keys($stale) as $path) {
                echo '- ', Files::relative($this->root, $path), PHP_EOL;
            }

            echo "Regenerate locally: ./bin/planning-check --write\n";
            echo "Review the changes with git diff, run ./bin/planning-check, and commit the refreshed views.\n";

            return 1;
        }

        $active = count(array_filter($live, static fn (Record $record): bool => !$record->terminal()));
        printf("Planning %s: %d records, %d active\n", $write ? 'updated' : 'validated', count($live), $active);

        return 0;
    }

    private static function splitDocument(string $text, string $path): array
    {
        $parts = explode("\n---\n", substr($text, 4), 2);
        if (count($parts) !== 2) {
            throw new RuntimeException("Invalid frontmatter delimiter: $path");
        }

        return $parts;
    }

    private function records(bool $archived, array &$errors): array
    {
        $records = [];
        foreach (Record::KINDS as $directory => $prefix) {
            $base = "$this->planning/$directory";
            $paths = $archived ? Files::markdown("$base/archive", "-$prefix.md") : (glob("$base/*-$prefix.md") ?: []);
            foreach ($paths as $path) {
                $label = Files::relative($this->root, $path);
                try {
                    $fields = Files::frontmatter($path);
                } catch (RuntimeException $exception) {
                    $errors[] = "$label: ".$exception->getMessage();
                    continue;
                }

                $id = $fields['id'] ?? '';
                $kind = $archived ? 'archived id' : 'id';
                if (preg_match("/^$prefix-[0-9]{5}$/D", $id) !== 1) {
                    $errors[] = "$label: invalid $kind ".var_export($id, true);
                } elseif (isset($records[$id])) {
                    $errors[] = "duplicate $kind $id";
                } else {
                    if (!in_array($fields['status'] ?? '', Record::STATUSES, true)) {
                        $errors[] = "$label: invalid status";
                    }

                    if (!$archived && ($fields['title'] ?? '') === '') {
                        $errors[] = "$label: missing title";
                    }

                    $records[$id] = new Record($path, $fields);
                }
            }
        }

        return $records;
    }

    private function validateRecords(array $records, array &$errors): void
    {
        foreach ($records as $id => $record) {
            $fields = $record->fields;
            $path = Files::relative($this->root, $record->path);
            if (str_starts_with($id, 'TICKET-')) {
                $parent = $fields['epic'] ?? '';
                if (!str_starts_with($parent, 'EPIC-') || !isset($records[$parent])) {
                    $errors[] = "$path: invalid epic ".var_export($parent, true);
                }
            }

            if (str_starts_with($id, 'TASK-')) {
                $parent = $fields['ticket'] ?? '';
                $kind = $fields['kind'] ?? '';
                if ($parent !== '' && (!str_starts_with($parent, 'TICKET-') || !isset($records[$parent]))) {
                    $errors[] = "$path: invalid ticket ".var_export($parent, true);
                } elseif ($parent === '' && !in_array($kind, Record::STANDALONE, true)) {
                    $errors[] = "$path: parentless TASK requires kind chore or bug";
                }

                if ($kind !== '' && !in_array($kind, Record::STANDALONE, true)) {
                    $errors[] = "$path: invalid TASK kind ".var_export($kind, true);
                }
            }

            if (
                (str_starts_with($id, 'TASK-') || isset($fields['order']))
                && preg_match('/^[+-]?[0-9](?:_?[0-9])*$/D', $fields['order'] ?? '') !== 1
            ) {
                $errors[] = "$path: missing or invalid order";
            }

            foreach ($record->blockers() as $blocker) {
                if (!str_starts_with($blocker, 'TASK-') || !isset($records[$blocker])) {
                    $errors[] = "$path: invalid blocker $blocker";
                }
            }
        }

        $visiting = [];
        $visited = [];
        $visit = static function (string $id) use (&$visit, &$visiting, &$visited, &$errors, $records): void {
            if (isset($visiting[$id])) {
                $errors[] = "dependency cycle at $id";

                return;
            }

            if (isset($visited[$id])) {
                return;
            }

            $visiting[$id] = true;
            foreach ($records[$id]->blockers() as $blocker) {
                if (str_starts_with($blocker, 'TASK-') && isset($records[$blocker])) {
                    $visit($blocker);
                }
            }

            unset($visiting[$id]);
            $visited[$id] = true;
        };
        $ids = array_keys($records);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            if (str_starts_with($id, 'TASK-')) {
                $visit($id);
            }
        }
    }

    private function validateLinks(array &$errors): void
    {
        foreach (Files::markdown($this->planning) as $path) {
            if (str_starts_with(basename($path), '_')) {
                continue;
            }

            preg_match_all('~!?\[[^\]]*\]\(([^\s)]+)~', Files::read($path), $links);
            foreach ($links[1] as $link) {
                $target = explode('#', $link, 2)[0];
                if (
                    str_ends_with($target, '.md') && preg_match('~^(?:https?:|/)~', $target) !== 1
                    && !is_file(Files::resolve(dirname($path).'/'.$target))
                ) {
                    $errors[] = Files::relative($this->root, $path).": broken link $link";
                }
            }
        }
    }

    private function completeParents(array $records): array
    {
        $closures = [];
        ksort($records, SORT_STRING);
        foreach (['TICKET-' => 'ticket', 'EPIC-' => 'epic'] as $prefix => $parentKey) {
            foreach ($records as $id => $record) {
                if (
                    !str_starts_with($id, $prefix) || $record->terminal()
                    || in_array('archive', explode('/', $record->path), true)
                ) {
                    continue;
                }

                $children = array_filter(
                    $records,
                    static fn (Record $child): bool => ($child->fields[$parentKey] ?? '') === $id
                );
                if ($children === [] || array_any($children, static fn (Record $child): bool => !$child->terminal())) {
                    continue;
                }

                $wontfix = static fn (Record $child): bool => $child->fields['status'] === 'wontfix';
                $allWontfix = array_all($children, $wontfix);
                $status = $allWontfix ? 'wontfix' : 'done';
                $header = self::splitDocument(Files::read($record->path), $record->path)[0];
                $closures[$record->path] = preg_replace('/^status:[^\n]*$/m', "status: $status", $header, 1);
                $record->fields['status'] = $status;
            }
        }

        return $closures;
    }

    private function git(string ...$arguments): ?string
    {
        $process = proc_open(
            ['git', '-c', "safe.directory=$this->root", ...$arguments],
            [1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
            $this->root
        );
        if ($process === false) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process) === 0 ? trim($output) : null;
    }
}
