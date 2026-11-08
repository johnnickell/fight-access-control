<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;

use function PHPStan\dumpType;

return static function (
    ProvisionAgentHandler $provision,
    RotateAgentCredentialHandler $rotate,
    HmacSharedSecretGenerator $generator,
    HmacSharedSecretCipher $cipher,
    AgentDeliveryCipher $deliveryCipher,
    AgentOperationAuthorization $authorization,
    GetAgentOperationHandler $status
): void {
    dumpType($provision->handle(...));
    dumpType($rotate->handle(...));
    dumpType($generator->generate());
    dumpType($cipher->encrypt(...));
    dumpType($deliveryCipher->encrypt(...));
    dumpType($authorization->authorize(...));
    dumpType($authorization->authorizeRotation(...));
    dumpType($authorization->authorizeRead(...));
    dumpType($status->handle(...));
};
