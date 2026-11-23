<?php

declare(strict_types=1);

use Fight\AccessControl\Tooling\Planning\Record;
use Fight\AccessControl\Tooling\Planning\Views;

require __DIR__.'/Planning/bootstrap.php';

$task = static fn (
    int $number,
    string $status,
    string $title,
    string $blocker = '',
    ?string $order = null
): Record => new Record(
    sprintf('planning/tasks/%05d-TASK.md', $number),
    [
        'id'         => sprintf('TASK-%05d', $number),
        'kind'       => 'bug',
        'order'      => $order ?? (string) $number,
        'status'     => $status,
        'title'      => $title,
        'blocked_by' => $blocker
    ]
);

$expectNext = static function (string $expected, Record ...$tasks): void {
    $records = [];
    foreach ($tasks as $task) {
        $records[$task->id()] = $task;
    }

    $board = new Views('planning', $records)->board(Record::select($records, static fn (): bool => true));
    $section = explode("## What's Next?\n\n", $board, 2)[1];
    $actual = explode("\n\n## ", $section, 2)[0];
    if ($actual !== $expected) {
        throw new RuntimeException("Expected $expected; got $actual.");
    }
};

$info = $task(35, 'needs-info', 'Clarify Common compatibility');
$triage = $task(36, 'needs-triage', 'Triage the planning gap');
$ready = $task(37, 'ready-for-agent', 'Execute the ready slice');
$active = $task(38, 'in-progress', 'Continue the active slice');
$human = $task(39, 'ready-for-human', 'Decide the release boundary');
$waiting = $task(40, 'ready-for-agent', 'Wait for the missing information', $info->id());

$missing = 'This Task is not executable until the missing information is resolved.';
$information = "Needs information: [TASK-00035](00035-TASK.md) — Clarify Common compatibility. $missing";
$untriaged = 'This Task is not executable until it is triaged.';
$triageLabel = "Needs triage: [TASK-00036](00036-TASK.md) — Triage the planning gap. $untriaged";
$running = 'Active Task: [TASK-00038](00038-TASK.md) — Continue the active slice.';
$decision = 'Human decision required: [TASK-00039](00039-TASK.md) — Decide the release boundary. '.$running;
$expectNext($information, $info);
$expectNext($triageLabel, $triage);
$expectNext($information, $info, $triage, $waiting);
$expectNext('First ready Task: [TASK-00037](00037-TASK.md) — Execute the ready slice.', $ready, $info, $triage);
$expectNext($running, $active, $ready, $info, $triage);
$expectNext($decision, $human, $active, $ready, $info, $triage);
$expectNext('No non-terminal Task exists. Any future release decision requires separate human authorization.');

$larger = $task(1, 'ready-for-agent', 'Larger order', order: '9223372036854775809');
$smaller = $task(2, 'ready-for-agent', 'Smaller order', order: '+9_223_372_036_854_775_808');
$tied = $task(3, 'ready-for-agent', 'Equal order', order: '09223372036854775808');
$expectNext('First ready Task: [TASK-00002](00002-TASK.md) — Smaller order.', $tied, $larger, $smaller);

$negative = $task(4, 'ready-for-agent', 'Negative order', order: '-9223372036854775808');
$moreNegative = $task(5, 'ready-for-agent', 'More negative order', order: '-9_223_372_036_854_775_809');
$expectNext('First ready Task: [TASK-00005](00005-TASK.md) — More negative order.', $negative, $moreNegative);

echo "Planning portfolio next-action qualification passed.\n";
