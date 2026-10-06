<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentToolAvailability;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\AgentProfileToolFailures;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\GetAgentProfileTool;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\UpdateAgentProfileTool;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;
use Fight\Common\Application\Messaging\Command\SynchronousCommandBus;
use Fight\Common\Application\Messaging\Query\QueryBus;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;

final class ProfileToolEnvironment
{
    public readonly ProvisioningEnvironment $storage;

    public readonly InMemoryPermissionRepository $permissions;

    public readonly Agent $original;

    public readonly InMemoryAgentRequestNonceConsumer $nonces;

    public CurrentAgentPrincipalProvider $provider;

    public SignedAgentRequest $request;

    public string $correlationId;

    public McpToolRegistry $registry;

    public AgentToolAvailability $availability;

    private int $requests = 0;

    public function __construct()
    {
        $this->storage = new ProvisioningEnvironment();
        $this->storage->service()->provision($this->storage->key, $this->storage->request);
        $this->permissions = new InMemoryPermissionRepository($this->storage->transaction);
        $agent = $this->storage->agents->all()[0];
        foreach ([AgentProfilePermissions::READ, AgentProfilePermissions::UPDATE] as $name) {
            $id = PermissionId::generate();
            $this->permissions->add(Permission::define($id, PermissionName::fromString($name), $this->now()));
            $agent = $agent->grantPermission($id, $this->now());
        }

        // Modeled committed authority for the existing correlated Agent; no database qualification.
        $this->storage->agents->restoreSnapshot([$agent]);
        $this->original = $agent;
        $this->storage->events = new InMemoryEventDispatcher();
        $this->nonces = new InMemoryAgentRequestNonceConsumer($this->storage->agents, $this->storage->transaction);
        $this->nextRequest();
    }

    public function nextRequest(): void
    {
        ++$this->requests;
        $this->correlationId = 'profile-request-'.$this->requests;
        $this->request = new SignedAgentRequest(
            'POST',
            'mcp.example',
            '/mcp',
            '',
            $this->now(),
            'profile-nonce-'.$this->requests,
            $this->original->getCredentialId(),
            'HMAC-SHA256',
            'fixture-signature',
            null,
            ''
        );
        $this->provider = new CurrentAgentPrincipalProvider(
            $this->storage->agents,
            $this->permissions,
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($this->request, 'original-test-secret'),
            new FixedClock($this->now()),
            $this->nonces,
            $this->storage->transaction
        );
    }

    public function read(QueryBus $queries): GetAgentProfileTool
    {
        return new GetAgentProfileTool($this->provider, $this->request, $this->correlationId, $queries);
    }

    public function update(SynchronousCommandBus $commands): UpdateAgentProfileTool
    {
        return new UpdateAgentProfileTool($this->provider, $this->request, $this->correlationId, $commands);
    }

    public function invoker(?QueryBus $queries = null, ?SynchronousCommandBus $commands = null): McpToolInvoker
    {
        $queryRouter = new InMemoryQueryRouter();
        $queryRouter->registerHandler(GetAgentProfile::class, new GetAgentProfileHandler($this->storage->agents));

        $commandRouter = new InMemoryCommandRouter();
        $commandRouter->registerHandler(UpdateAgent::class, new UpdateAgentHandler(
            $this->storage->agents,
            new FixedClock($this->now()),
            $this->storage->transaction,
            $this->storage->events
        ));
        $this->registry = new McpToolRegistry([
            $this->read($queries ?? new RoutingQueryBus($queryRouter)),
            $this->update($commands ?? new RoutingCommandBus($commandRouter))
        ]);
        $this->availability = new AgentToolAvailability(
            new AgentToolPermissionCatalog($this->registry),
            $this->provider,
            $this->request,
            $this->correlationId
        );

        return new McpToolInvoker($this->registry, $this->availability, failures: AgentProfileToolFailures::create());
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T12:00:00+00:00');
    }
}
