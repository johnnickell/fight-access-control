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
        usort($selected, static function (self $a, self $b): int {
            [$leftSign, $leftDigits] = $a->order();
            [$rightSign, $rightDigits] = $b->order();
            $magnitude = (strlen($leftDigits) <=> strlen($rightDigits)) ?: strcmp($leftDigits, $rightDigits);

            return ($leftSign <=> $rightSign) ?: ($leftSign * $magnitude) ?: strcmp($a->id(), $b->id());
        });

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

    private function order(): array
    {
        $order = str_replace('_', '', $this->fields['order'] ?? substr($this->id(), -5));
        $digits = ltrim($order, '+-0');
        $sign = $digits !== '' && str_starts_with($order, '-') ? -1 : 1;

        return [$sign, $digits];
    }
}
