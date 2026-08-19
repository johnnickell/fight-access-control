<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\Query\ActivationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\User\Query\FindActivationDeliveryStatus;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Retrieves a safe activation delivery-status view.
 */
final readonly class FindActivationDeliveryStatusHandler implements QueryHandler
{
    /**
     * Creates the delivery-status query handler.
     */
    public function __construct(private ActivationDeliveryWorkRepository $activationDeliveryWorkRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return FindActivationDeliveryStatus::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ?ActivationDeliveryStatusView
    {
        /** @var FindActivationDeliveryStatus $query */
        $query = $queryMessage->payload();
        $work = $this->activationDeliveryWorkRepository->getByUserId($query->getUserId());

        if (!$work instanceof ActivationDeliveryWork) {
            return null;
        }

        return ActivationDeliveryStatusView::fromWork($work);
    }
}
