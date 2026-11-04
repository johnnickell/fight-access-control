<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;

/** Consumer-supplied capabilities, not an alternate implementation of the workflow */
final readonly class IssuanceRecoveryPorts
{
    public function __construct(
        public AgentRepository $agentRepository,
        public AgentOperationRepository $agentOperationRepository,
        public AuditEvidenceRepository $auditEvidenceRepository,
        public AgentOperationAuthorization $operationAuthorization,
        public HmacSharedSecretGenerator $generator,
        public HmacSharedSecretCipher $cipher,
        public AgentDeliveryCipher $deliveryCipher,
        public AgentDeliveryDecipher $decipher,
        public AgentDeliveryAuthorization $deliveryAuthorization,
        public AgentCredentialSink $sink,
        public Clock $clock,
        public TransactionalUnitOfWork $transaction,
        public EventDispatcher $eventDispatcher
    ) {
    }
}
