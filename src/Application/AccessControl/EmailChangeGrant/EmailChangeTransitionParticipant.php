<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant;

/**
 * Interface EmailChangeTransitionParticipant
 *
 * Consumer-owned required notification intent writer on the transition owner's actual connection and Unit of Work.
 */
interface EmailChangeTransitionParticipant
{
    /**
     * Stages protected notification intent for the exact grant and stage
     *
     * Runs inside the owning transaction after the business writes succeed, before optional audit and commit.
     * Use only the owner's active transaction; do not open or commit another transaction, publish, or send mail.
     * Failure must throw; returning means all required intent is staged, not committed or delivered.
     */
    public function stage(EmailChangeTransition $transition): void;
}
