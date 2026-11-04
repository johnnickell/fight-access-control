<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Delivery\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\FixedHmacSharedSecretCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\DeliveryClock;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\DeliveryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\InMemoryAgentCredentialSink;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Delivery\InMemoryAgentDeliveryAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Maintenance\MaintenanceEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Deterministic storage/fence model, not database concurrency or production cryptography */
final class InMemoryIssuanceRecoveryFixture extends IssuanceRecoveryFixture implements
    AgentDeliveryDecipher,
    HmacSharedSecretGenerator
{
    private readonly ProvisioningEnvironment $environment;

    private readonly DeliveryClock $clock;

    private readonly InMemoryAgentCredentialSink $sink;

    private InMemoryAgentDeliveryAuthorization $deliveryAuthorization;

    private DeliveryUnitOfWork $transaction;

    private bool $failPublication;

    private ?string $failure;

    private int $participant = 0;

    private int $generated = 0;

    /** @var list<Event> */
    private array $attempted = [];

    public function __construct()
    {
        $this->environment = new ProvisioningEnvironment();
        $this->clock = new DeliveryClock();
        $this->sink = new InMemoryAgentCredentialSink($this->environment->transaction);
        $this->restart();
    }

    public function ports(): IssuanceRecoveryPorts
    {
        $env = $this->environment;
        $audit = $env->auditEvidenceRepository;
        if ($this->failure === 'audit') {
            $audit = new readonly class ($audit) implements AuditEvidenceRepository {
                public function __construct(private AuditEvidenceRepository $innerAuditEvidenceRepository)
                {
                }

                public function add(AuditEvidence $evidence): void
                {
                    $this->innerAuditEvidenceRepository->add($evidence);
                    throw new RuntimeException('unsafe-conformance-provider /private/conformance-key');
                }
            };
        }

        return new IssuanceRecoveryPorts(
            $env->agentRepository,
            $env->agentOperationRepository,
            $audit,
            $env->operationAuthorization,
            $this,
            new FixedHmacSharedSecretCipher('conformance-auth-envelope:'),
            new BoundAgentDeliveryCipher(),
            $this,
            $this->deliveryAuthorization,
            $this->sink,
            $this->clock,
            $this->transaction,
            new InMemoryEventDispatcher(function (Event $event): void {
                if ($this->environment->transaction->transactionActive) {
                    throw new LogicException('Publication preceded transaction completion.');
                }

                $this->attempted[] = $event;
                if ($this->failPublication) {
                    throw new RuntimeException('unsafe-conformance-provider /private/conformance-key');
                }
            })
        );
    }

    public function allow(AgentOperationScope $scope, AgentCredentialDestination $destination): void
    {
        $this->environment->operationAuthorization->scopes[$scope->toString()] = true;
        $id = $destination->getId()->toString();
        $this->environment->operationAuthorization->destinations[$id] = $destination->getRevision();
        $this->environment->operationAuthorization->actor = $scope->getCallerId();
    }

    public function restart(): void
    {
        // Repositories represent durable storage here. No request/result/event is passed to the restarted worker.
        $this->transaction = new DeliveryUnitOfWork($this->environment->transaction);
        $this->deliveryAuthorization = new InMemoryAgentDeliveryAuthorization($this->environment);
        $this->deliveryAuthorization->expiresAt = $this->clock->now()->modify('+30 days');

        $this->environment->operationAuthorization->afterAuthorization = null;
        $this->environment->agentOperationRepository->afterAdd = null;

        $this->attempted = [];
        $this->sink->afterStage = null;
        $this->sink->validReceipt = true;

        $this->failure = null;
        $this->failPublication = false;
    }

    public function advance(int $seconds): void
    {
        $this->clock->advance($seconds);
    }

    public function failPublishers(): void
    {
        $this->failPublication = true;
    }

    public function publications(): array
    {
        return $this->attempted;
    }

    public function loseCommit(bool $committed): void
    {
        $this->transaction->uncertainAt = $this->transaction->commits + 1;
        $this->transaction->persistUncertain = $committed;
    }

    public function failBeforeCommit(string $stage): void
    {
        $this->failure = $stage;
        $fail = function (): void {
            ++$this->participant;
            $this->environment->transaction->onRollback(function (): void {
                --$this->participant;
            });
            throw new RuntimeException('unsafe-conformance-provider /private/conformance-key');
        };
        if ($stage === 'authorization') {
            $this->environment->operationAuthorization->afterAuthorization = $fail;
        } elseif ($stage === 'persistence') {
            $this->environment->agentOperationRepository->afterAdd = $fail;
        }
    }

    public function revokeAuthority(AgentOperationScope $scope): void
    {
        $this->environment->operationAuthorization->changeAuthority(function () use ($scope): void {
            $this->environment->operationAuthorization->scopes[$scope->toString()] = false;
        });
    }

    public function contendWithAuthorityRevocation(AgentOperationScope $scope): void
    {
        $this->environment->operationAuthorization->afterAuthorization = function () use ($scope): void {
            $this->environment->operationAuthorization->afterAuthorization = null;
            $this->revokeAuthority($scope);
        };
    }

    public function delegateWorker(bool $allowed): void
    {
        $this->deliveryAuthorization->worker = 'conformance-worker';
        $this->deliveryAuthorization->delegated = $allowed;
    }

    public function revokeWorkerAfterDiscovery(): void
    {
        $this->deliveryAuthorization->afterDiscovery = function (?AgentIssuance $issuance): void {
            if ($issuance !== null) {
                $this->delegateWorker(false);
            }
        };
    }

    public function setCanonicalVersion(AgentOperationKey $key, int $version): void
    {
        $operation = $this->stored($key) ?? throw new LogicException('Missing operation fixture.');
        $this->environment->agentOperationRepository->operations[$key->toString()] = new AgentCredentialOperation(
            $version,
            $operation->getCanonicalRequest(),
            $operation->getIssuance(),
            $operation->getMaterial()
        );
    }

    public function stored(AgentOperationKey $key): ?AgentCredentialOperation
    {
        return $this->environment->agentOperationRepository->operations[$key->toString()] ?? null;
    }

    public function state(): array
    {
        $credentials = [];
        foreach ($this->environment->agentRepository->all() as $agent) {
            $credentials[$agent->getId()->toString()] = hash('sha256', serialize($agent));
        }

        return [
            'agents'         => count($this->environment->agentRepository->all()),
            'operations'     => count($this->environment->agentOperationRepository->operations),
            'audit'          => count($this->environment->auditEvidenceRepository->all()),
            'participant'    => $this->participant,
            'reservations'   => $this->environment->agentOperationRepository->versions,
            'credentials'    => $credentials,
            'audit_facts'    => array_map(
                static fn (AuditEvidence $fact): string => hash('sha256', serialize($fact)),
                $this->environment->auditEvidenceRepository->all()
            ),
            'issuance_facts' => array_map(
                static fn (AgentCredentialOperation $operation): string => hash('sha256', serialize([
                    $operation->getCanonicalVersion(),
                    $operation->getCanonicalRequest(),
                    $operation->getIssuance()->toArray(),
                    $operation->getStatus()->getCredentialDisposition()
                ])),
                $this->environment->agentOperationRepository->operations
            )
        ];
    }

    public function generations(): int
    {
        return $this->generated;
    }

    public function transactions(): int
    {
        return $this->environment->transaction->transactions;
    }

    public function sinkCalls(): int
    {
        return $this->sink->calls;
    }

    public function stagedBytes(AgentIssuance $issuance): ?string
    {
        return $this->sink->stagedBytes($issuance);
    }

    public function preparedBytes(AgentIssuance $issuance): string
    {
        $operation = $this->stored($issuance->getKey());
        $material = $operation?->getMaterial() ?? throw new LogicException('Missing prepared material.');

        return new BoundAgentDeliveryCipher()->inspect($material, $issuance);
    }

    public function loseSinkResponse(): void
    {
        $this->sink->afterStage = static function (): void {
            throw new RuntimeException('unsafe-conformance-provider /private/conformance-key');
        };
    }

    public function invalidateReceipt(): void
    {
        $this->sink->validReceipt = false;
    }

    public function authenticateDelivered(AgentIssuance $issuance): AuthenticatedAgentPrincipal
    {
        $secret = $this->stagedBytes($issuance) ?? throw new LogicException('Missing delivered test material.');
        $request = new SignedAgentRequest(
            'POST',
            'conformance.example',
            '/use',
            '',
            $this->clock->now(),
            bin2hex(random_bytes(16)),
            $issuance->getCredentialId(),
            'HMAC-SHA256',
            'conformance-signature',
            null,
            ''
        );

        return new CurrentAgentPrincipalProvider(
            $this->environment->agentRepository,
            new InMemoryPermissionRepository(),
            new FixedHmacSharedSecretDecipher('conformance-auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($request, $secret),
            $this->clock,
            new InMemoryAgentRequestNonceConsumer($this->environment->agentRepository, $this->environment->transaction),
            $this->environment->transaction
        )->resolve($request, 'conformance-current-use');
    }

    public function forbiddenValues(): array
    {
        $values = ['conformance-auth-envelope:', 'unsafe-conformance-provider', '/private/conformance-key'];
        for ($number = 1; $number <= $this->generated; ++$number) {
            $values[] = 'conformance-secret-'.$number;
        }

        foreach ($this->environment->agentOperationRepository->operations as $operation) {
            if ($operation->getMaterial() !== null) {
                $values[] = $operation->getMaterial()->getCiphertext()->reveal();
            }
        }

        return $values;
    }

    public function safeEvidence(): array
    {
        return [...$this->attempted, ...$this->environment->auditEvidenceRepository->all()];
    }

    public function maintenance(): AgentDeliveryMaintenanceService
    {
        $operation = array_first($this->environment->agentOperationRepository->operations);
        $issuance = $operation?->getIssuance() ?? throw new LogicException('Missing maintenance fixture.');
        $delivery = new DeliveryEnvironment($this->environment, $issuance);
        $delivery->clock->time = $this->clock->now();

        $maintenance = new MaintenanceEnvironment($delivery);

        return new AgentDeliveryMaintenanceService(
            $this->environment->agentOperationRepository,
            $maintenance,
            $maintenance,
            $this->sink,
            $this->clock,
            $this->transaction
        );
    }

    public function contend(array $requests): array
    {
        $results = [];
        foreach ($requests as $request) {
            try {
                $results[] = $request();
            } catch (Throwable $throwable) {
                $results[] = $throwable;
            }
        }

        return $results;
    }

    public function generate(): string
    {
        return 'conformance-secret-'.++$this->generated;
    }

    public function materialize(
        #[SensitiveParameter] AgentDeliveryMaterial $material,
        AgentIssuance $issuance
    ): AgentCredentialInvocation {
        if ($this->environment->transaction->transactionActive) {
            throw new LogicException('Materialization cannot share a transaction.');
        }

        $operation = $this->stored($issuance->getKey()) ?? throw new LogicException('Missing admitted operation.');
        $operation->requireAttempt()->assertAdmittedAt($this->clock->now());

        return new AgentCredentialInvocation($issuance, new BoundAgentDeliveryCipher()->inspect($material, $issuance));
    }
}
