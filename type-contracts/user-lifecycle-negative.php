<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

return static function (EnableUserHandler $enable, CommandMessage $message): void {
    (static fn (UserId $result): UserId => $result)($enable->handle($message));
};
