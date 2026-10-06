<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentCredentialSink;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryAuthorization;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentOperationAuthorization;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
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
        public AgentRepository $agents,
        public AgentOperationRepository $operations,
        public AuditEvidenceRepository $audit,
        public AgentOperationAuthorization $authorization,
        public HmacSharedSecretGenerator $generator,
        public HmacSharedSecretCipher $cipher,
        public AgentDeliveryCipher $deliveryCipher,
        public AgentDeliveryDecipher $decipher,
        public AgentDeliveryAuthorization $deliveryAuthorization,
        public AgentCredentialSink $sink,
        public Clock $clock,
        public TransactionalUnitOfWork $transaction,
        public EventDispatcher $events
    ) {
    }
}
