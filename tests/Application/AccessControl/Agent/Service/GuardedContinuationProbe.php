<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

/**
 * Models three consumer-owned protected boundaries without a Common runtime or an executor contract.
 */
final class GuardedContinuationProbe
{
    /** @var list<string> */
    public array $queued = ['prepared-first', 'prepared-second'];

    /** @var list<string> */
    public array $emitted = [];

    public int $steps = 0;

    /** @var list<string> */
    public array $effects = [];

    /** @var list<string> */
    public array $retained = ['accepted-first', 'accepted-second'];

    /** @var list<string> */
    public array $consumed = [];

    public function disclose(): void
    {
        $this->emitted[] = array_shift($this->queued);
    }

    public function step(): void
    {
        ++$this->steps;
        $this->effects[] = 'step-'.$this->steps;
    }

    public function consume(): void
    {
        $this->consumed[] = array_shift($this->retained);
    }
}
