<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryAbsent;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use LogicException;

/**
 * Class FindInvitationDeliveryStatusHandler
 *
 * Retrieves a safe activation delivery-status view.
 */
final readonly class FindInvitationDeliveryStatusHandler implements QueryHandler
{
    /**
     * Constructs FindInvitationDeliveryStatusHandler
     *
     * Creates the delivery-status query handler.
     */
    public function __construct(private ActivationGrantRepository $activationGrantRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return FindInvitationDeliveryStatus::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): InvitationDeliveryStatusView|CredentialDeliveryAbsent
    {
        /** @var FindInvitationDeliveryStatus $query */
        $query = $queryMessage->payload();
        $activationGrant = $this->activationGrantRepository->getLatestByUserId($query->getUserId());

        if ($activationGrant === null) {
            return new CredentialDeliveryAbsent();
        }

        $delivery = $activationGrant->getDelivery();
        if (
            $activationGrant->purpose() !== 'activation'
            || !$activationGrant->getUserId()->equals($query->getUserId())
            || !$delivery->getUserId()->equals($activationGrant->getUserId())
            || $delivery->getExpiresAt() != $activationGrant->getExpiresAt()
        ) {
            throw new LogicException('Invalid invitation status repository result.');
        }

        return InvitationDeliveryStatusView::fromWork($delivery, $activationGrant->getOrigin());
    }
}
