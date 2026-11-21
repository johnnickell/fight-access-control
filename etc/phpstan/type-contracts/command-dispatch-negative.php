<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Meta;

return static function (CommandBus $commandBus, RegisterUser $command): void {
    $message = CommandMessage::create($command);
    $commandBus->execute($message);
    $commandBus->dispatch($command);
    CommandMessage::create(Meta::create());
    (static fn (RegisterUser $result): RegisterUser => $result)($commandBus->execute($command));
    (static fn (RegisterUser $result): RegisterUser => $result)($commandBus->dispatch($message));
};
