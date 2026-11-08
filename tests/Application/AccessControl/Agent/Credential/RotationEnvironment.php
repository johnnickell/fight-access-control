<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperationRepository;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;

final readonly class RotationEnvironment
{
    public ProvisioningEnvironment $provisioning;

    public AgentIssuance $original;

    public AgentOperationKey $key;

    public AgentRotationRequest $request;

    public function __construct(?DeliveryEnvironment $delivery = null)
    {
        $this->provisioning = $delivery->provisioning ?? new ProvisioningEnvironment();
        $this->original = $delivery->issuance ?? $this->provisioning->bus()->dispatchAndRead(new ProvisionAgent(
            $this->provisioning->key,
            $this->provisioning->request
        ))->getIssuance() ?? throw new LogicException('Missing provision fixture.');
        $this->key = new AgentOperationKey($this->original->getKey()->getScope(), AgentOperationId::generate());
        $this->request = new AgentRotationRequest(
            $this->original->getAgentId(),
            $this->original->getCredentialId(),
            $this->original->getCredentialRevision(),
            $this->original->getDestination()
        );
    }

    public function bus(
        ?TransactionalUnitOfWork $unitOfWork = null,
        ?AgentOperationLimits $limits = null,
        ?HmacSharedSecretGenerator $generator = null,
        ?HmacSharedSecretCipher $cipher = null,
        ?AgentDeliveryCipher $deliveryCipher = null,
        ?AuditEvidenceRepository $auditEvidenceRepository = null,
        ?AgentRepository $agentRepository = null,
        ?ProvisioningEnvironment $environment = null,
        ?Clock $clock = null,
        ?AgentCredentialOperationRepository $operationRepository = null
    ): IssuanceBusFixture {
        $environment ??= $this->provisioning;

        return new IssuanceBusFixture(new RotateAgentCredentialHandler(
            $agentRepository ?? $environment->agentRepository,
            $operationRepository ?? $environment->operationRepository,
            $auditEvidenceRepository ?? $environment->auditEvidenceRepository,
            $environment->operationAuthorization,
            $generator ?? new readonly class ($environment) implements HmacSharedSecretGenerator {
                public function __construct(private ProvisioningEnvironment $environment)
                {
                }

                public function generate(): string
                {
                    ++$this->environment->generations;

                    return 'successor-test-secret';
                }
            },
            $cipher ?? new FixedHmacSharedSecretCipher('auth-envelope:'),
            $deliveryCipher ?? new BoundAgentDeliveryCipher(),
            $clock ?? new FixedClock(new DateTimeImmutable('2026-09-27T12:00:00+00:00')),
            $unitOfWork ?? $environment->transaction,
            $environment->eventDispatcher,
            $limits ?? new AgentOperationLimits()
        ), $environment->operationRepository, $environment->operationAuthorization);
    }
}
