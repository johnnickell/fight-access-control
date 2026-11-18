<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\{
    CountAgentDeliveryKeyReferencesHandler,
    ListDueAgentDeliveriesHandler
};
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

static function (
    ListDueAgentDeliveriesHandler $due,
    CountAgentDeliveryKeyReferencesHandler $count,
    QueryMessage $message
): void {
    array_map(static fn (AgentIssuance $item): string => $item->getKey()->toString(), $due->handle($message));
    (static function (string $value): void {
        echo $value;
    })($count->handle($message));
};
