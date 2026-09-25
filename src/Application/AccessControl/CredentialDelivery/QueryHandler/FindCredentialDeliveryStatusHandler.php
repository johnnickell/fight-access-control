<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Class FindCredentialDeliveryStatusHandler
 *
 * Retrieves safe state for one exact credential-delivery generation.
 */
final readonly class FindCredentialDeliveryStatusHandler implements QueryHandler
{
    /**
     * Constructs FindCredentialDeliveryStatusHandler
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return FindCredentialDeliveryStatus::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ?CredentialDeliveryStatusView
    {
        /** @var FindCredentialDeliveryStatus $query */
        $query = $queryMessage->payload();
        $grant = match ($query->getPurpose()) {
            'activation' => $this->activationGrantRepository->getByDeliveryId(
                ActivationDeliveryId::fromString($query->getDeliveryId())
            ),
            'password_reset' => $this->passwordResetGrantRepository->getByDeliveryId(
                PasswordResetDeliveryId::fromString($query->getDeliveryId())
            ),
            'email_change' => $this->emailChangeGrantRepository->getByDeliveryId(
                EmailChangeDeliveryId::fromString($query->getDeliveryId())
            )
        };

        if (
            !$grant instanceof ActivationGrant
            && !$grant instanceof PasswordResetGrant
            && !$grant instanceof EmailChangeGrant
        ) {
            return null;
        }

        return CredentialDeliveryStatusView::fromDelivery(
            $grant->purpose(),
            $grant->getDelivery(),
            $grant->getRevision()
        );
    }
}
