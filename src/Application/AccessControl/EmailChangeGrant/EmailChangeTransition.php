<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeRequestOrigin;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Class EmailChangeTransition
 *
 * Protected in-process facts for staged notification intent, not a message or a committed outcome.
 * CONFIRMED means valid mailbox credential proof, not proof of the holder's identity. Confirmation attribution,
 * when supplied, comes from the trusted caller separately and is never inferred from the requester or target.
 */
final readonly class EmailChangeTransition
{
    /**
     * Constructs EmailChangeTransition
     */
    public function __construct(
        public EmailChangeTransitionStage $stage,
        public UserId $userId,
        public EmailChangeGrantId $grantId,
        public int $reservationRevision,
        public EmailChangeRequestOrigin $origin,
        public DateTimeImmutable $occurredAt,
        public UserId|AgentId|SystemId|AnonymousId|null $confirmationActorId
    ) {
    }
}
