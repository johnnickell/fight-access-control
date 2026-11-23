<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\Planning;

final class Record
{
    public const array KINDS = ['epics' => 'EPIC', 'tickets' => 'TICKET', 'tasks' => 'TASK'];

    public const array TERMINAL = ['done', 'wontfix'];

    public const array STANDALONE = ['bug', 'chore'];

    public const array STATUSES = [
        'needs-triage', 'needs-info', 'ready-for-agent', 'ready-for-human', 'in-progress', 'done', 'wontfix'
    ];

    public function __construct(public readonly string $path, public array $fields)
    {
    }

    public static function select(array $records, callable $matches): array
    {
        $selected = array_values(array_filter($records, $matches));
        usort($selected, static fn (self $a, self $b): int => [$a->order(), $a->id()] <=> [$b->order(), $b->id()]);

        return $selected;
    }

    public function id(): string
    {
        return $this->fields['id'];
    }

    public function terminal(): bool
    {
        return in_array($this->fields['status'] ?? '', self::TERMINAL, true);
    }

    public function blockers(): array
    {
        $ids = array_map(trim(...), explode(',', $this->fields['blocked_by'] ?? ''));

        return array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
    }

    private function order(): int|float
    {
        return str_replace('_', '', $this->fields['order'] ?? substr($this->id(), -5)) + 0;
    }
}
