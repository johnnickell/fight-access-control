<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class UpdateAgentHandler
 *
 * Coordinates one name-only transaction with publication after confirmed commit.
 */
final readonly class UpdateAgentHandler implements CommandHandler
{
    /**
     * Constructs UpdateAgentHandler
     */
    public function __construct(
        private AgentRepository $agents,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $events
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return UpdateAgent::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var UpdateAgent $command */
        $command = $commandMessage->payload();
        try {
            $name = AgentName::fromString($command->getName());
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $name): ?AgentNameChanged {
                $changedAt = $this->clock->now();
                if (!$this->agents->rename($command->getAgentId(), $name, $changedAt)) {
                    return null;
                }

                return new AgentNameChanged($command->getInitiator(), $command->getAgentId(), $name, $changedAt);
            });
            if ($event instanceof AgentNameChanged) {
                $this->events->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->events->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            } finally {
                throw $throwable;
            }
        }
    }
}
