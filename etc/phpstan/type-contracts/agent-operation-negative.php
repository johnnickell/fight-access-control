<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;

static function (
    AgentCredentialOperationRepository $writer,
    AgentCredentialOperationReadRepository $reader,
    AgentOperationKey $key,
    AgentDeliveryId $deliveryId,
    AgentCredentialDestination $destination,
    AgentCredentialOperation $operation,
    AgentOperationView $view,
    DateTimeImmutable $now
): void {
    $writer->getByKey($deliveryId);
    (static function (AgentCredentialOperation $value): void {
    })($writer->getByKey($key));
    (static function (AgentOperationView $value): void {
    })($reader->getStatusByKey($key));
    $operation->claimDelivery(new AgentDeliveryReceipt('opaque-receipt-123'), new AgentDeliveryPolicy(), $now);
    $operation->rewrapMaterial($operation->getMaterial(), $now);
    $views = $reader->listDueDeliveries($key->getScope(), $destination, $now, 50);
    array_map(static fn(AgentIssuance $item): AgentOperationKey => $item->getKey(), $views);
    $payload = $view->toArray();
    if ($payload['issuance_outcome'] === 'indeterminate') {
        (static function (int $version): void {
            echo $version;
        })($payload['canonical_version']);
    }

    $reader->replaceDelivery($operation, $operation, $operation);
};
