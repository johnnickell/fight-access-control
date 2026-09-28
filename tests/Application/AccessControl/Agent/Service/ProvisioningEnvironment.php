<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentOperationRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class ProvisioningEnvironment
{
    public readonly InMemoryUnitOfWork $transaction;

    public readonly InMemoryAgentOperationAuthorization $authorization;

    public readonly InMemoryAgentOperationRepository $operations;

    public readonly InMemoryAgentRepository $agents;

    public readonly InMemoryAuditEvidenceRepository $audit;

    public readonly AgentOperationKey $key;

    public readonly AgentProvisioningRequest $request;

    public InMemoryEventDispatcher $events;

    public int $generations = 0;

    public function __construct()
    {
        $this->transaction = new InMemoryUnitOfWork();
        $this->authorization = new InMemoryAgentOperationAuthorization($this->transaction);
        $this->operations = new InMemoryAgentOperationRepository($this->transaction, $this->authorization);
        $this->agents = new InMemoryAgentRepository($this->transaction, operations: $this->operations);
        $this->audit = new InMemoryAuditEvidenceRepository($this->transaction);
        $this->events = new InMemoryEventDispatcher();
        $scope = new AgentOperationScope('consumer-a', 'user', 'maintainer-42');
        $this->key = new AgentOperationKey($scope, AgentOperationId::generate());
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $this->request = new AgentProvisioningRequest('  Production deployment  ', $destination);
        $this->authorization->scopes[$scope->toString()] = true;
        $this->authorization->destinations[$destination->getId()->toString()] = 1;
    }

    public function service(
        ?TransactionalUnitOfWork $unitOfWork = null,
        ?AgentOperationLimits $limits = null,
        ?HmacSharedSecretGenerator $generator = null,
        ?HmacSharedSecretCipher $cipher = null,
        ?AgentDeliveryCipher $deliveryCipher = null,
        ?AuditEvidenceRepository $audit = null,
        ?AgentRepository $agents = null
    ): AgentProvisioningService {
        return new AgentProvisioningService(
            $agents ?? $this->agents,
            $this->operations,
            $audit ?? $this->audit,
            $this->authorization,
            $generator ?? new readonly class ($this) implements HmacSharedSecretGenerator {
                public function __construct(private ProvisioningEnvironment $environment)
                {
                }

                public function generate(): string
                {
                    ++$this->environment->generations;

                    return 'original-test-secret';
                }
            },
            $cipher ?? new FixedHmacSharedSecretCipher('auth-envelope:'),
            $deliveryCipher ?? new BoundAgentDeliveryCipher(),
            new FixedClock(new DateTimeImmutable('2026-09-27T12:00:00+00:00')),
            $unitOfWork ?? $this->transaction,
            $this->events,
            $limits ?? new AgentOperationLimits()
        );
    }
}
