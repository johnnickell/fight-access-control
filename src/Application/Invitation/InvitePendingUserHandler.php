<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\Invitation;

use DateInterval;
use Fight\AccessControl\Domain\Identity\ActivationGrant;
use Fight\AccessControl\Domain\Identity\User;
use Fight\Common\Application\Repository\UnitOfWork;

/**
 * Atomically records a pending invitation and its required durable work.
 */
final readonly class InvitePendingUserHandler
{
    /**
     * Creates the invitation handler.
     */
    public function __construct(
        private UserStore $users,
        private ActivationGrantStore $grants,
        private ActivationDeliveryWorkStore $deliveries,
        private AuditEvidenceStore $audits,
        private UnitOfWork $unitOfWork,
        private ActivationCredentialGenerator $credentials,
        private ActivationDeliveryCipher $cipher,
        private InvitationClock $clock
    ) {
    }

    /**
     * Records an invitation through one transactional boundary.
     */
    public function __invoke(InvitePendingUser $command): InvitationView
    {
        $issuedAt = $this->clock->now();

        return $this->unitOfWork->commitTransactional(function () use ($command, $issuedAt): InvitationView {
            $user = User::invite($command->email);
            if ($this->users->reserve($user) === false) {
                throw new DuplicateEmail('The email address is already reserved.');
            }

            $credential = $this->credentials->generate();
            $grant = ActivationGrant::issue(
                $user->id(),
                $credential,
                $issuedAt,
                $issuedAt->add(new DateInterval('P7D'))
            );
            $delivery = new ActivationDeliveryWork(
                $grant->userId(),
                $user->email()->value(),
                $this->cipher->encrypt($credential),
                $grant->expiresAt()
            );
            $this->grants->save($grant);
            $this->deliveries->save($delivery);
            $this->audits->save(new AuditEvidence($command->actorId, 'user.invited', $user->id()));

            return new InvitationView($user->email()->value(), $user->state()->value);
        });
    }
}
