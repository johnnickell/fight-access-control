<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

return static function (
    CommandBus $commandBus,
    QueryBus $queryBus,
    ProvisionAgent $provision,
    RotateAgentCredential $rotate,
    GetAgentOperation $query
): void {
    (static fn (AgentIssuance $issuance): AgentIssuance => $issuance)($commandBus->execute($provision));
    (static fn (AgentIssuance $issuance): AgentIssuance => $issuance)(
        $commandBus->dispatch(CommandMessage::create($rotate))
    );
    CommandMessage::create($query);
    QueryMessage::create($provision);
    $commandBus->execute($query);
    $queryBus->fetch($rotate);
    /** @var AgentOperationView $view Known matching registered handler */
    $view = $queryBus->fetch($query);
    (static fn (AgentIssuance $issuance): AgentIssuance => $issuance)($view->getIssuance());
    (static fn (AgentCredentialDisposition $state): AgentCredentialDisposition => $state)(
        $view->getDeliveryDisposition()
    );
    new RotateAgentCredential($provision->getKey(), $provision->getRequest());
};
