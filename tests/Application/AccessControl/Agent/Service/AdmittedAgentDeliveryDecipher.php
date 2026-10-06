<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryDecipher;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use LogicException;
use SensitiveParameter;
use Throwable;

final class AdmittedAgentDeliveryDecipher implements AgentDeliveryDecipher
{
    public int $calls = 0;

    public ?AgentDeliveryFailure $failure = null;

    public ?Closure $afterMaterialize = null;

    public ?AgentIssuance $wrongBinding = null;

    public function __construct(private readonly DeliveryEnvironment $environment)
    {
    }

    public function materialize(
        #[SensitiveParameter] AgentDeliveryMaterial $material,
        AgentIssuance $issuance
    ): AgentCredentialInvocation {
        if ($this->environment->provisioning->transaction->transactionActive) {
            throw new LogicException('Materialization cannot share a transaction.');
        }

        $this->environment->provisioning->operations->operations[$issuance->getKey()->toString()]
            ->getAttempt()?->assertAdmittedAt($this->environment->clock->now());
        ++$this->calls;
        if ($this->failure !== null) {
            throw new AgentDeliveryFailedException($this->failure);
        }

        try {
            $secret = new BoundAgentDeliveryCipher()->inspect($material, $issuance);
        } catch (Throwable) {
            throw new AgentDeliveryFailedException(AgentDeliveryFailure::CORRUPT_MATERIAL);
        }

        $this->afterMaterialize?->__invoke();

        return new AgentCredentialInvocation($this->wrongBinding ?? $issuance, $secret);
    }
}
