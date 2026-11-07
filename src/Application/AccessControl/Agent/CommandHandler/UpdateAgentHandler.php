<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameUpdateConflictException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentUnavailableException;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
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
        private AgentRepository $agentRepository,
        private Clock $clock,
        private TransactionalUnitOfWork $unitOfWork,
        private EventDispatcher $eventDispatcher
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
            try {
                $name = AgentName::fromString($command->getName());
            } catch (AgentNameException $exception) {
                throw new InvalidInput('The Agent name is invalid.', previous: $exception);
            }

            /** @var AgentNameChanged|null $event */
            $event = $this->unitOfWork->commitTransactional(function () use ($command, $name): ?AgentNameChanged {
                try {
                    $changedAt = $this->agentRepository->rename($command->getAgentId(), $name, $this->clock->now(...));
                } catch (AgentUnavailableException $exception) {
                    throw new ResourceNotFound('The Agent profile is unavailable.', previous: $exception);
                } catch (AgentNameUpdateConflictException $exception) {
                    throw new StateConflict(
                        'The Agent name update conflicts with current state.',
                        previous: $exception
                    );
                }

                if ($changedAt === null) {
                    return null;
                }

                return new AgentNameChanged($command->getInitiator(), $command->getAgentId(), $name, $changedAt);
            });
            if ($event !== null) {
                $this->eventDispatcher->trigger($event);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Agent update failed.'));
            } finally {
                throw $throwable;
            }
        }
    }
}
