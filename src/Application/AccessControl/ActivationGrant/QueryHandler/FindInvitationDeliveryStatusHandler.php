<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Retrieves a safe activation delivery-status view.
 */
final readonly class FindInvitationDeliveryStatusHandler implements QueryHandler
{
    /**
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
    public function handle(QueryMessage $queryMessage): ?InvitationDeliveryStatusView
    {
        /** @var FindInvitationDeliveryStatus $query */
        $query = $queryMessage->payload();
        $activationGrant = $this->activationGrantRepository->getLatestByUserId($query->getUserId());

        if (!$activationGrant instanceof ActivationGrant) {
            return null;
        }

        return InvitationDeliveryStatusView::fromWork($activationGrant->getDelivery());
    }
}
