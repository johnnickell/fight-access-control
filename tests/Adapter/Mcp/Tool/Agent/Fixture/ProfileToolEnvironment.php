<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Mcp\Tool\Agent\Fixture;

use DateTimeImmutable;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentProfileToolFailures;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\GetAgentProfileTool;
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\UpdateAgentProfileTool;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\UpdateAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentProfileHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
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
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;

final class ProfileToolEnvironment
{
    public readonly ProvisioningEnvironment $storage;

    public readonly InMemoryPermissionRepository $permissionRepository;

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
        $this->storage->bus()->commandBus->execute(new ProvisionAgent($this->storage->key, $this->storage->request));
        $this->permissionRepository = new InMemoryPermissionRepository($this->storage->transaction);
        $agent = $this->storage->agentRepository->all()[0];
        foreach ([AgentProfilePermissions::READ, AgentProfilePermissions::UPDATE] as $name) {
            $id = PermissionId::generate();
            $this->permissionRepository->add(Permission::define($id, PermissionName::fromString($name), $this->now()));
            $agent = $agent->grantPermission($id, $this->now());
        }

        // Modeled committed authority for the existing correlated Agent; no database qualification.
        $this->storage->agentRepository->restoreSnapshot([$agent]);
        $this->original = $agent;
        $this->storage->eventDispatcher = new InMemoryEventDispatcher();
        $this->nonces = new InMemoryAgentRequestNonceConsumer(
            $this->storage->agentRepository,
            $this->storage->transaction
        );
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
            $this->storage->agentRepository,
            $this->permissionRepository,
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($this->request, 'original-test-secret'),
            new FixedClock($this->now()),
            $this->nonces,
            $this->storage->transaction
        );
    }

    public function read(QueryBus $queryBus): GetAgentProfileTool
    {
        return new GetAgentProfileTool($this->provider, $this->request, $this->correlationId, queryBus: $queryBus);
    }

    public function update(SynchronousCommandBus $synchronousCommandBus): UpdateAgentProfileTool
    {
        return new UpdateAgentProfileTool(
            $this->provider,
            $this->request,
            $this->correlationId,
            synchronousCommandBus: $synchronousCommandBus
        );
    }

    public function invoker(
        ?QueryBus $queryBus = null,
        ?SynchronousCommandBus $synchronousCommandBus = null,
        bool $audit = false
    ): McpToolInvoker {
        $queryRouter = new InMemoryQueryRouter();
        $queryRouter->registerHandler(
            GetAgentProfile::class,
            new GetAgentProfileHandler($this->storage->agentRepository)
        );

        $commandRouter = new InMemoryCommandRouter();
        $commandRouter->registerHandler(UpdateAgent::class, new UpdateAgentHandler(
            $this->storage->agentRepository,
            new FixedClock($this->now()),
            $this->storage->transaction,
            $this->storage->eventDispatcher,
            $audit ? $this->storage->auditEvidenceRepository : null
        ));
        $this->registry = new McpToolRegistry([
            $this->read($queryBus ?? new RoutingQueryBus($queryRouter)),
            $this->update($synchronousCommandBus ?? new RoutingCommandBus($commandRouter))
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
