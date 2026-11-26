<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\ContractInventory;

use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;

final readonly class Inventory
{
    public const array SEMANTICS = [
        'actual_contract', 'callers', 'input', 'result', 'absence', 'failure', 'validation', 'permissions',
        'secrets', 'events_effects', 'finding', 'disposition', 'verification'
    ];

    public function __construct(private string $toolRoot)
    {
    }

    public function run(string $root, ?string $output, ?string $previous, ?string $judgments, bool $refresh): bool
    {
        $root = realpath($root) ?: $root;
        $config = InventoryJson::load($root.'/etc/contract-owners.json');
        self::validateConfig($config, $root);
        [$inputs, $records] = new Discovery()->collect($root, $config);
        $tools = [];
        $paths = ['scripts/contract_inventory.php', ...glob($this->toolRoot.'/scripts/ContractInventory/*.php')];
        foreach ($paths as $path) {
            $relative = str_starts_with($path, '/') ? substr($path, strlen($this->toolRoot) + 1) : $path;
            $tools[$relative] = hash_file('sha256', $this->toolRoot.'/'.$relative);
        }

        $identity = (object) [
            'files'         => $inputs,
            'configuration' => InventoryJson::hash($config),
            'tools'         => (object) $tools,
            'lockfile'      => hash_file('sha256', $root.'/composer.lock'),
            'installed'     => hash_file('sha256', $this->toolRoot.'/vendor/composer/installed.json')
        ];
        $candidate = InventoryJson::hash($identity);
        $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $root)->mustRun()->getOutput());
        $observed = self::structure($records);
        $lock = $root.'/etc/contract-inventory.json';
        self::require(
            $refresh || is_file($lock),
            'Missing structural inventory; review ownership and explicitly --refresh'
        );
        $expected = $refresh ? $observed : InventoryJson::load($lock);
        $drift = InventoryJson::hash($observed) !== InventoryJson::hash($expected);
        $changed = [];
        foreach (array_keys((array) $observed->owners + (array) $expected->owners) as $task) {
            if (($observed->owners->$task ?? null) != ($expected->owners->$task ?? null)) {
                $changed[] = $task;
            }
        }

        sort($changed, SORT_STRING);
        $summary = (object) [
            'files'      => count((array) $inputs),
            'boundaries' => count($records),
            'kinds'      => (object) array_count_values(array_column($records, 'kind'))
        ];
        $report = (object) [
            'schema'                  => 1,
            'discovery_complete'      => true,
            'structural_match'        => !$drift,
            'semantic_audit_complete' => false,
            'candidate'               => $candidate,
            'identity'                => $identity,
            'head'                    => $head,
            'summary'                 => $summary,
            'drift_owners'            => $changed,
            'structure'               => $observed,
            'boundaries'              => $records
        ];
        if ($previous !== null) {
            $report->difference = self::difference(InventoryJson::load($previous), $records);
        }

        self::attachJudgments($judgments, $candidate, $records);
        if ($refresh) {
            InventoryJson::save($lock, $observed);
        }

        if ($output !== null) {
            InventoryJson::save($output, $report);
        }

        printf(
            "Contract inventory: %d files, %d boundaries; semantic judgments remain owner-supplied\n",
            $summary->files,
            $summary->boundaries
        );
        if ($drift) {
            $owners = implode(', ', $changed);
            fwrite(STDERR, 'Structural ownership drift: '.$owners."; inspect --output/--previous before --refresh\n");
        } else {
            echo "Structural ownership matches; this is not semantic audit completion\n";
        }

        return !$drift;
    }

    private static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private static function validateConfig(stdClass $config, string $root): void
    {
        self::require($config->schema === 1 && $config->rules !== [], 'Invalid ownership configuration');
        foreach ([...$config->rules, ...$config->concerns] as $rule) {
            self::require(preg_match('/\ATASK-\d{5}\z/', $rule->task) === 1, 'Invalid correction task');
            self::require(preg_match('/\ATICKET-\d{5}\z/', $rule->ticket) === 1, 'Invalid requirement owner');
            $filename = substr($rule->task, 5).'-TASK.md';
            $taskPath = "$root/planning/tasks/$filename";
            if (!is_file($taskPath)) {
                $taskPath = "$root/planning/tasks/archive/$filename";
            }

            self::require(is_file($taskPath), 'Missing correction task: '.$rule->task);
            $task = file_get_contents($taskPath);
            $parent = '/^ticket: '.$rule->ticket.'\s*$/m';
            self::require(preg_match($parent, $task) === 1, 'Correction task/parent mismatch');
            preg_match(Discovery::pattern($rule->path), '');
            preg_match(Discovery::pattern($rule->except ?? '(?!)'), '');
        }

        $concerns = array_map(InventoryJson::hash(...), $config->concerns);
        self::require(count(array_unique($concerns)) === count($concerns), 'Duplicate concern rule');
    }

    private static function structure(array $records): stdClass
    {
        $groups = [];
        $fields = array_flip(['id', 'kind', 'path', 'owner', 'concerns', 'contract_hash']);
        foreach ($records as $row) {
            $groups[$row->owner->task][] = (object) array_intersect_key((array) $row, $fields);
        }

        $owners = [];
        foreach ($groups as $task => $rows) {
            $owners[$task] = (object) ['boundaries' => count($rows), 'sha256' => InventoryJson::hash($rows)];
        }

        return (object) ['schema' => 1, 'owners' => (object) $owners];
    }

    private static function difference(stdClass $previous, array $records): stdClass
    {
        self::require(
            ($previous->schema ?? null) === 1 && ($previous->discovery_complete ?? null) === true,
            'Previous inventory is incomplete or unsupported'
        );
        $prior = $previous->boundaries;
        $files = (array) $previous->identity->files;
        self::require(
            $previous->summary->boundaries === count($prior) && $previous->summary->files === count($files)
                && $previous->candidate === InventoryJson::hash($previous->identity)
                && InventoryJson::hash($previous->structure) === InventoryJson::hash(self::structure($prior)),
            'Partial or inconsistent previous inventory'
        );
        $fileRows = array_filter($prior, static fn (stdClass $row): bool => $row->kind === 'file');
        $priorFiles = array_column($fileRows, 'path');
        self::require(
            array_diff(array_keys($files), $priorFiles) === [] && array_diff($priorFiles, array_keys($files)) === [],
            'Missing previous file boundaries'
        );
        foreach ($prior as $row) {
            self::require(($files[$row->path] ?? null) === $row->source_hash, 'Inconsistent previous source identity');
        }

        $old = array_column($prior, null, 'id');
        self::require(count($old) === count($prior), 'Duplicate previous boundary');
        $new = array_column($records, null, 'id');
        $added = array_keys(array_diff_key($new, $old));
        $removed = array_keys(array_diff_key($old, $new));
        sort($removed, SORT_STRING);
        $moved = [];
        $changed = [];
        foreach (array_intersect_key($new, $old) as $id => $row) {
            $before = $old[$id];
            if ($row->path !== $before->path) {
                $moved[] = $id;
            }

            if (
                $row->owner != $before->owner || $row->concerns != $before->concerns
                || $row->contract_hash !== $before->contract_hash
            ) {
                $changed[] = $id;
            }
        }

        return (object) compact('added', 'removed', 'moved', 'changed');
    }

    private static function attachJudgments(?string $path, string $candidate, array $records): void
    {
        $supplied = $path === null ? (object) ['candidate' => $candidate, 'records' => []] : InventoryJson::load($path);
        self::require($supplied->candidate === $candidate, 'Stale judgment candidate');
        self::require(is_array($supplied->records), 'Invalid judgment records');
        $lookup = array_column($records, null, 'id');
        foreach ($supplied->records as $judgment) {
            $id = $judgment->boundary;
            $task = $judgment->task;
            self::require(isset($lookup[$id]), 'Unknown judgment boundary: '.$id);
            $row = $lookup[$id];
            $owners = array_column([$row->owner, ...$row->concerns], 'task');
            self::require(in_array($task, $owners, true), 'Wrong judgment owner');
            self::require(!isset($row->judgments->$task), 'Duplicate judgment');
            self::require(
                in_array($judgment->status, ['pending', 'corrected', 'unchanged'], true),
                'Unsupported judgment status'
            );
            foreach (self::SEMANTICS as $key) {
                self::require(
                    self::nonblank($judgment->$key ?? null),
                    'Judgment needs contract/callers/semantics/disposition/verification; justify each N/A'
                );
            }

            self::require(is_array($judgment->evidence ?? null), 'Missing judgment evidence list');
            if ($judgment->status !== 'pending') {
                self::require(
                    $judgment->evidence !== [] && array_all($judgment->evidence, self::nonblank(...)),
                    'Verified disposition requires evidence locators'
                );
            }

            $row->judgments ??= new stdClass();
            $row->judgments->$task = $judgment;
        }

        $pending = array_fill_keys(self::SEMANTICS, 'Pending owner review; discovery supplies no semantic judgment');
        foreach ($records as $row) {
            $row->judgments ??= new stdClass();
            foreach ([$row->owner, ...$row->concerns] as $owner) {
                $row->judgments->{$owner->task} ??= (object) ([
                    'boundary' => $row->id,
                    'task'     => $owner->task,
                    'status'   => 'pending',
                    'evidence' => []
                ] + $pending);
            }
        }

        // Judgments are owner assertions, not independent acceptance or verified evidence.
    }

    private static function nonblank(mixed $value): bool
    {
        return is_string($value) && preg_match('/[^\s\x{001c}-\x{001f}]/u', $value) === 1;
    }
}
