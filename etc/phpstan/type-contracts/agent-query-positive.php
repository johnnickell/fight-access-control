<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    CountAgentDeliveryKeyReferencesHandler,
    GetAgentOperationHandler,
    ListAgentDeliveryMaintenanceHandler,
    ListDueAgentDeliveriesHandler
};
use Fight\Common\Domain\Messaging\Query\QueryMessage;

use function PHPStan\dumpType;

static function (
    GetAgentOperationHandler $status,
    ListDueAgentDeliveriesHandler $due,
    ListAgentDeliveryMaintenanceHandler $maintenance,
    CountAgentDeliveryKeyReferencesHandler $count,
    QueryMessage $message
): void {
    dumpType($status->handle($message));
    dumpType($due->handle($message));
    dumpType($maintenance->handle($message));
    dumpType($count->handle($message));
};
