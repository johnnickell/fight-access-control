<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioned;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationReadRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentDeliveryWorkView as WorkView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\ListDueAgentDeliveries;

use function PHPStan\dumpType;

static function (
    AgentCredentialOperationRepository $writer,
    AgentCredentialOperationReadRepository $reader,
    AgentOperationKey $key,
    AgentCredentialDestination $destination,
    AgentCredentialOperation $operation,
    AgentIssuance $issuance,
    AgentOperationView $view,
    ProvisionAgent $provision,
    RotateAgentCredential $rotate,
    GetAgentOperation $status,
    ListDueAgentDeliveries $due,
    ListAgentDeliveryMaintenance $maintenance,
    CountAgentDeliveryKeyReferences $count,
    AgentProvisioned $event,
    DateTimeImmutable $now
): void {
    dumpType($writer->getByKey($key));
    dumpType($reader->getStatusByKey($key));
    $views = $reader->listDueDeliveries($key->getScope(), $destination, $now, 50);
    dumpType($views);
    dumpType(array_map(static fn(WorkView $item): AgentOperationKey => $item->getOperation()->getKey(), $views));
    dumpType($reader->listMaintenance(
        $key->getScope(),
        $destination,
        AgentMaintenanceWork::MATERIAL,
        $now,
        new AgentMaintenancePolicy(),
        null
    ));
    dumpType($issuance->toArray());
    dumpType($key->toArray());
    dumpType($provision->toArray());
    dumpType($rotate->toArray());
    dumpType($status->toArray());
    dumpType($due->toArray());
    dumpType($maintenance->toArray());
    dumpType($count->toArray());
    dumpType($event->toArray());
    dumpType(new AgentDeliveryPolicy()->toArray());
    dumpType($operation->getMaterial());
    dumpType($operation->getAttempt());
    $payload = $view->toArray();
    if ($payload['issuance_outcome'] === 'confirmed') {
        dumpType($payload['issuance']);
        dumpType($payload['delivery_disposition']);
    } else {
        dumpType($payload['issuance']);
        dumpType($payload['canonical_version']);
    }
};
