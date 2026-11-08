<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

return static function (
    ProvisionAgentHandler $provision,
    RotateAgentCredentialHandler $rotate,
    AgentOperationAuthorization $authorization,
    AgentDeliveryCipher $deliveryCipher,
    AgentOperationScope $scope,
    AgentCredentialDestination $destination,
    UserId $userId,
    CommandMessage $message
): void {
    (static fn (mixed $result): mixed => $result)($provision->handle($message));
    (static fn (mixed $result): mixed => $result)($rotate->handle($message));
    $authorization->authorizeRotation($scope, $destination, $userId);
    $deliveryCipher->encrypt('test-bytes', $destination);
};
