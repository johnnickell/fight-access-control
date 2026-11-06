<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryAbsent;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use LogicException;

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
    public function handle(QueryMessage $queryMessage): CredentialDeliveryStatusView|CredentialDeliveryAbsent
    {
        /** @var FindCredentialDeliveryStatus $query */
        $query = $queryMessage->payload();
        $deliveryId = match ($query->getPurpose()) {
            'activation' => ActivationDeliveryId::fromString($query->getDeliveryId()),
            'password_reset' => PasswordResetDeliveryId::fromString($query->getDeliveryId()),
            'email_change' => EmailChangeDeliveryId::fromString($query->getDeliveryId())
        };
        $grant = match (true) {
            $deliveryId instanceof ActivationDeliveryId => $this->activationGrantRepository->getByDeliveryId(
                $deliveryId
            ),
            $deliveryId instanceof PasswordResetDeliveryId => $this->passwordResetGrantRepository->getByDeliveryId(
                $deliveryId
            ),
            default => $this->emailChangeGrantRepository->getByDeliveryId($deliveryId)
        };

        if ($grant === null) {
            return new CredentialDeliveryAbsent();
        }

        $delivery = $grant->getDelivery();
        if (
            $grant->purpose() !== $query->getPurpose()
            || !$delivery->getId()->equals($deliveryId)
            || !$delivery->getUserId()->equals($grant->getUserId())
            || $delivery->getExpiresAt() != $grant->getExpiresAt()
        ) {
            throw new LogicException('Invalid credential status repository result.');
        }

        return CredentialDeliveryStatusView::fromDelivery($grant->purpose(), $delivery, $grant->getRevision());
    }
}
