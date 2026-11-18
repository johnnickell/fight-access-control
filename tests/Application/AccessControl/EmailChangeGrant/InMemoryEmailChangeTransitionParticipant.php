<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeTransition;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeTransitionParticipant;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use RuntimeException;

final class InMemoryEmailChangeTransitionParticipant implements EmailChangeTransitionParticipant
{
    /** @var array<string, EmailChangeTransition> */
    public array $intents = [];

    public int $invocations = 0;

    public function __construct(
        private readonly InMemoryUnitOfWork $unitOfWork,
        private readonly ?RuntimeException $failure = null
    ) {
    }

    public function stage(EmailChangeTransition $transition): void
    {
        ++$this->invocations;
        if (!$this->unitOfWork->transactionActive) {
            throw new LogicException('Consumer intent must use the active transaction.');
        }

        $key = $transition->grantId->toString().':'.$transition->stage->value;
        if (isset($this->intents[$key])) {
            throw new LogicException('Duplicate intent for this grant and stage.');
        }

        $this->intents[$key] = $transition;
        $this->unitOfWork->onRollback(function () use ($key): void {
            unset($this->intents[$key]);
        });
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
