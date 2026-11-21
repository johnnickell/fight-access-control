<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialReceiptLookup;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentCredentialCleanup;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryRewrapper;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;
use Fight\Common\Application\Messaging\Query\QueryBus;

return static function (
    AgentCredentialLifecycleService $lifecycle,
    AgentCredentialDeliveryService $delivery,
    AgentDeliveryRecoveryService $recovery,
    AgentDeliveryMaintenanceService $maintenance,
    AgentCredentialSink $sink,
    AgentCredentialReceiptLookup $lookup,
    AgentDeliveryAuthorization $deliveryAuthorization,
    AgentMaintenanceAuthorization $maintenanceAuthorization,
    AgentDeliveryDecipher $decipher,
    AgentDeliveryRewrapper $rewrapper,
    AgentCredentialCleanup $cleanup,
    AgentCredentialInvocation $invocation,
    AgentOperationKey $key,
    AgentCredentialDestination $destination,
    AgentDeliveryId $deliveryId,
    AgentDeliveryKeyVersion $target,
    AgentDeliveryMaterial $material,
    AgentIssuance $issuance,
    DateTimeImmutable $now,
    QueryBus $queryBus
): void {
    PHPStan\dumpType($lifecycle->revoke(...));
    PHPStan\dumpType($delivery->deliver($key, $destination, $deliveryId));
    PHPStan\dumpType($recovery->recover($key->getScope(), $destination));
    PHPStan\dumpType($recovery->nextRunAt());
    PHPStan\dumpType($maintenance->rewrap($key, $destination, $deliveryId, $target));
    PHPStan\dumpType($maintenance->expire($key, $destination, $deliveryId));
    PHPStan\dumpType($maintenance->cleanup($key, $destination, $deliveryId));
    PHPStan\dumpType($sink->stage($invocation));
    PHPStan\dumpType($lookup->lookupReceipt($issuance));
    PHPStan\dumpType($deliveryAuthorization->authorize($issuance, $now));
    PHPStan\dumpType($maintenanceAuthorization->authorize($key->getScope(), $destination, null, null, $now));
    PHPStan\dumpType($decipher->materialize($material, $issuance));
    PHPStan\dumpType($rewrapper->rewrap($material, $issuance, $target));
    PHPStan\dumpType($cleanup->remove(...));
    PHPStan\dumpType($invocation->__debugInfo());
    PHPStan\dumpType($invocation->revealSecret());
    PHPStan\dumpType($queryBus->fetch(new ListDueAgentDeliveries($key->getScope(), $destination)));
};
