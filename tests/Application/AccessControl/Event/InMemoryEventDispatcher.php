<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Event;

use Closure;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Messaging\Event\EventSubscriber;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Messaging\Event\EventMessage;

final class InMemoryEventDispatcher implements EventDispatcher
{
    /** @var list<Event> */
    private array $events = [];

    /**
     * Creates the in-memory event dispatcher.
     */
    public function __construct(private readonly ?Closure $onTrigger = null)
    {
    }

    public function trigger(Event $event): void
    {
        if ($this->onTrigger instanceof Closure) {
            ($this->onTrigger)($event);
        }

        $this->events[] = $event;
    }

    public function dispatch(EventMessage $eventMessage): void
    {
        /** @var Event $event */
        $event = $eventMessage->payload();
        $this->trigger($event);
    }

    public function register(EventSubscriber $subscriber): void
    {
    }

    public function unregister(EventSubscriber $subscriber): void
    {
    }

    public function addHandler(string $eventType, callable $handler, int $priority = 0): void
    {
    }

    public function getHandlers(?string $eventType = null): array
    {
        return [];
    }

    public function hasHandlers(?string $eventType = null): bool
    {
        return false;
    }

    public function removeHandler(string $eventType, callable $handler): void
    {
    }

    /**
     * @return list<Event>
     */
    public function events(): array
    {
        return $this->events;
    }
}
