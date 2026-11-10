<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Meta;

return static function (CommandBus $commandBus, RegisterUser $input): void {
    $message = CommandMessage::create($input);
    $message = $message->withMeta(Meta::create(['correlation' => 'contract']));
    $message = $message->mergeMeta(Meta::create(['attempt' => 2]));
    PHPStan\dumpType($message);
    PHPStan\dumpType($message->payload());
    /** @var RegisterUser $command */
    $command = $message->payload();
    PHPStan\dumpType($command);
    PHPStan\dumpType($command->getEmail());
    PHPStan\dumpType($commandBus->execute(...));
    PHPStan\dumpType($commandBus->dispatch(...));
    $commandBus->execute($command);
    $commandBus->dispatch($message);
};
