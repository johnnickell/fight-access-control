<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User;

use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

final class InMemoryCommandBus implements CommandBus
{
    /** @var list<Command> */
    private array $executedCommands = [];

    /** @var list<CommandMessage> */
    private array $dispatchedMessages = [];

    public function execute(Command $command): void
    {
        $this->executedCommands[] = $command;
    }

    public function dispatch(CommandMessage $commandMessage): void
    {
        $this->dispatchedMessages[] = $commandMessage;
    }

    /**
     * Returns every command sent through execute().
     *
     * @return list<Command>
     */
    public function executedCommands(): array
    {
        return $this->executedCommands;
    }

    /**
     * Returns every command message sent through dispatch().
     *
     * @return list<CommandMessage>
     */
    public function dispatchedMessages(): array
    {
        return $this->dispatchedMessages;
    }
}
