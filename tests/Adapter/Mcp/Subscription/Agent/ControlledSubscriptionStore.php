<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Subscription\Agent;

use Closure;
use Fight\Common\Application\Mcp\Subscription\McpSubscriptionStore;

final class ControlledSubscriptionStore implements McpSubscriptionStore
{
    public int $heartbeats = 0;

    public ?Closure $beforeHeartbeat = null;

    public function __construct(private readonly McpSubscriptionStore $store)
    {
    }

    public function open(string $id, string $owner, array $context, int $now, int $expires): void
    {
        $this->store->open($id, $owner, $context, $now, $expires);
    }

    public function heartbeat(string $id, string $owner, int $now, int $expires): void
    {
        ++$this->heartbeats;
        ($this->beforeHeartbeat)?->__invoke($this->heartbeats);
        $this->store->heartbeat($id, $owner, $now, $expires);
    }

    public function close(string $id, string $owner, int $now): void
    {
        $this->store->close($id, $owner, $now);
    }

    public function cleanup(int $now, int $limit): int
    {
        return $this->store->cleanup($now, $limit);
    }
}
