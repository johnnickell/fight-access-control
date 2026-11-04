<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
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
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentOperationRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;

final class ProvisioningEnvironment
{
    public readonly InMemoryUnitOfWork $transaction;

    public readonly InMemoryAgentOperationAuthorization $operationAuthorization;

    public readonly InMemoryAgentOperationRepository $agentOperationRepository;

    public readonly InMemoryAgentRepository $agentRepository;

    public readonly InMemoryAuditEvidenceRepository $auditEvidenceRepository;

    public readonly AgentOperationKey $key;

    public readonly AgentProvisioningRequest $request;

    public InMemoryEventDispatcher $eventDispatcher;

    public int $generations = 0;

    public function __construct()
    {
        $this->transaction = new InMemoryUnitOfWork();
        $this->operationAuthorization = new InMemoryAgentOperationAuthorization($this->transaction);
        $this->agentOperationRepository = new InMemoryAgentOperationRepository(
            $this->transaction,
            $this->operationAuthorization
        );
        $this->agentRepository = new InMemoryAgentRepository(
            $this->transaction,
            agentOperationRepository: $this->agentOperationRepository
        );
        $this->agentOperationRepository->agentRepository = $this->agentRepository;
        $this->auditEvidenceRepository = new InMemoryAuditEvidenceRepository($this->transaction);
        $this->eventDispatcher = new InMemoryEventDispatcher();
        $scope = new AgentOperationScope('consumer-a', 'user', 'maintainer-42');
        $this->key = new AgentOperationKey($scope, AgentOperationId::generate());
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $this->request = new AgentProvisioningRequest('  Production deployment  ', $destination);
        $this->operationAuthorization->scopes[$scope->toString()] = true;
        $this->operationAuthorization->destinations[$destination->getId()->toString()] = 1;
    }

    public function bus(
        ?TransactionalUnitOfWork $unitOfWork = null,
        ?AgentOperationLimits $limits = null,
        ?HmacSharedSecretGenerator $generator = null,
        ?HmacSharedSecretCipher $cipher = null,
        ?AgentDeliveryCipher $deliveryCipher = null,
        ?AuditEvidenceRepository $auditEvidenceRepository = null,
        ?AgentRepository $agentRepository = null,
        ?Clock $clock = null
    ): IssuanceBusFixture {
        return new IssuanceBusFixture(new ProvisionAgentHandler(
            $agentRepository ?? $this->agentRepository,
            $this->agentOperationRepository,
            $auditEvidenceRepository ?? $this->auditEvidenceRepository,
            $this->operationAuthorization,
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
            $clock ?? new FixedClock(new DateTimeImmutable('2026-09-27T12:00:00+00:00')),
            $unitOfWork ?? $this->transaction,
            $this->eventDispatcher,
            $limits ?? new AgentOperationLimits()
        ), $this->agentOperationRepository, $this->operationAuthorization);
    }
}
