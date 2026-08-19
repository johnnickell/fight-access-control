<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\Query\FindInvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\Query\InvitationDeliveryStatusView;
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
    public function __construct(private InvitationDeliveryRepository $invitationDeliveryRepository)
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
        $work = $this->invitationDeliveryRepository->getByUserId($query->getUserId());

        if (!$work instanceof InvitationDelivery) {
            return null;
        }

        return InvitationDeliveryStatusView::fromWork($work);
    }
}
