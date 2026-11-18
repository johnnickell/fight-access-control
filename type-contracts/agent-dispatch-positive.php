<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

return static function (
    CommandBus $commandBus,
    QueryBus $queryBus,
    ProvisionAgent $provision,
    RotateAgentCredential $rotate,
    GetAgentOperation $query,
    ProvisionAgentHandler $provisionHandler,
    RotateAgentCredentialHandler $rotateHandler,
    GetAgentOperationHandler $queryHandler
): void {
    $message = CommandMessage::create($provision)->withMeta(Meta::create(['correlation' => 'safe']))
        ->mergeMeta(Meta::create(['attempt' => 2]));
    PHPStan\dumpType($message);
    PHPStan\dumpType($message->payload());
    /** @var ProvisionAgent $command */
    $command = $message->payload();
    PHPStan\dumpType($command);
    PHPStan\dumpType($command->getKey());
    PHPStan\dumpType($command->getRequest());
    $rotationMessage = CommandMessage::create($rotate);
    /** @var RotateAgentCredential $rotation */
    $rotation = $rotationMessage->payload();
    PHPStan\dumpType($rotation->getRequest());
    PHPStan\dumpType($rotation->getRequest()->getExpectedCredentialId());
    PHPStan\dumpType($commandBus->execute(...));
    PHPStan\dumpType($commandBus->dispatch(...));
    PHPStan\dumpType($provisionHandler->handle(...));
    PHPStan\dumpType($rotateHandler->handle(...));
    $commandBus->execute($command);
    $commandBus->dispatch($rotationMessage);
    $queryMessage = QueryMessage::create($query)->withMeta($message->meta())
        ->mergeMeta(Meta::create(['observation' => 'independent']));
    PHPStan\dumpType($queryMessage);
    PHPStan\dumpType($queryMessage->payload());
    /** @var GetAgentOperation $outcomeQuery */
    $outcomeQuery = $queryMessage->payload();
    PHPStan\dumpType($outcomeQuery);
    PHPStan\dumpType($queryHandler->handle($queryMessage));
    PHPStan\dumpType($queryBus->fetch($outcomeQuery));
    PHPStan\dumpType($queryBus->dispatch($queryMessage));
    /** @var AgentOperationView $fetched Known matching registered handler */
    $fetched = $queryBus->fetch($outcomeQuery);
    /** @var AgentOperationView $dispatched Known matching registered handler */
    $dispatched = $queryBus->dispatch($queryMessage);
    PHPStan\dumpType($fetched);
    PHPStan\dumpType($dispatched);
    PHPStan\dumpType($fetched->getIssuance());
    PHPStan\dumpType($dispatched->getDeliveryDisposition());
    PHPStan\dumpType($dispatched->getCredentialDisposition());
    PHPStan\dumpType($fetched->getIssuance()?->getCredentialId());
};
