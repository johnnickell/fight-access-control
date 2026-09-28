<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
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
        $this->original = $delivery->issuance ?? $this->provisioning->service()->provision(
            $this->provisioning->key,
            $this->provisioning->request
        )->getIssuance() ?? throw new LogicException('Missing provision fixture.');
        $this->key = new AgentOperationKey($this->original->getKey()->getScope(), AgentOperationId::generate());
        $this->request = new AgentRotationRequest(
            $this->original->getAgentId(),
            $this->original->getCredentialId(),
            $this->original->getCredentialRevision(),
            $this->original->getDestination()
        );
    }

    public function service(
        ?TransactionalUnitOfWork $unitOfWork = null,
        ?AgentOperationLimits $limits = null,
        ?HmacSharedSecretGenerator $generator = null,
        ?HmacSharedSecretCipher $cipher = null,
        ?AgentDeliveryCipher $deliveryCipher = null,
        ?AuditEvidenceRepository $audit = null,
        ?AgentRepository $agents = null,
        ?ProvisioningEnvironment $environment = null
    ): AgentCredentialRotationService {
        $environment ??= $this->provisioning;

        return new AgentCredentialRotationService(
            $agents ?? $environment->agents,
            $environment->operations,
            $audit ?? $environment->audit,
            $environment->authorization,
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
            new FixedClock(new DateTimeImmutable('2026-09-27T12:00:00+00:00')),
            $unitOfWork ?? $environment->transaction,
            $environment->events,
            $limits ?? new AgentOperationLimits()
        );
    }
}
