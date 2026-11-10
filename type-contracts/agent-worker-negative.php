<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialReceiptLookup;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

return static function (
    AgentCredentialLifecycleService $lifecycle,
    AgentCredentialDeliveryService $delivery,
    AgentDeliveryMaintenanceService $maintenance,
    AgentCredentialSink $sink,
    AgentCredentialReceiptLookup $lookup,
    AgentDeliveryDecipher $decipher,
    AgentOperationKey $key,
    AgentCredentialDestination $destination,
    AgentDeliveryId $deliveryId,
    AgentIssuance $issuance,
    UserId $userId
): void {
    $delivery->deliver($key, $destination, $userId);
    $lifecycle->revoke($userId, $userId);
    $sink->verify($lookup->lookupReceipt($issuance), $issuance);
    $decipher->materialize($issuance, $issuance);
    (static fn (AgentDeliveryResult $result): string => $result->value)(
        $maintenance->expire($key, $destination, $deliveryId)
    );
};
