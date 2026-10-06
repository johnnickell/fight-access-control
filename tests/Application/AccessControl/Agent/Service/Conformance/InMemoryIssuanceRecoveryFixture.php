<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Service\AgentDeliveryDecipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
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
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryClock;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentCredentialSink;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentDeliveryAuthorization;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\MaintenanceEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;
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
        $audit = $env->audit;
        if ($this->failure === 'audit') {
            $audit = new readonly class ($audit) implements AuditEvidenceRepository {
                public function __construct(private AuditEvidenceRepository $inner)
                {
                }

                public function add(AuditEvidence $evidence): void
                {
                    $this->inner->add($evidence);
                    throw new RuntimeException('unsafe-conformance-provider /private/conformance-key');
                }
            };
        }

        return new IssuanceRecoveryPorts(
            $env->agents,
            $env->operations,
            $audit,
            $env->authorization,
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
        $this->environment->authorization->scopes[$scope->toString()] = true;
        $id = $destination->getId()->toString();
        $this->environment->authorization->destinations[$id] = $destination->getRevision();
        $this->environment->authorization->actor = $scope->getCallerId();
    }

    public function restart(): void
    {
        // Repositories represent durable storage here. No request/result/event is passed to the restarted worker.
        $this->transaction = new DeliveryUnitOfWork($this->environment->transaction);
        $this->deliveryAuthorization = new InMemoryAgentDeliveryAuthorization($this->environment);
        $this->deliveryAuthorization->expiresAt = $this->clock->now()->modify('+30 days');

        $this->environment->authorization->afterAuthorization = null;
        $this->environment->operations->afterAdd = null;

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
            $this->environment->authorization->afterAuthorization = $fail;
        } elseif ($stage === 'persistence') {
            $this->environment->operations->afterAdd = $fail;
        }
    }

    public function revokeAuthority(AgentOperationScope $scope): void
    {
        $this->environment->authorization->changeAuthority(function () use ($scope): void {
            $this->environment->authorization->scopes[$scope->toString()] = false;
        });
    }

    public function contendWithAuthorityRevocation(AgentOperationScope $scope): void
    {
        $this->environment->authorization->afterAuthorization = function () use ($scope): void {
            $this->environment->authorization->afterAuthorization = null;
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
        $this->environment->operations->operations[$key->toString()] = new AgentCredentialOperation(
            $version,
            $operation->getCanonicalRequest(),
            $operation->getIssuance(),
            $operation->getMaterial()
        );
    }

    public function stored(AgentOperationKey $key): ?AgentCredentialOperation
    {
        return $this->environment->operations->operations[$key->toString()] ?? null;
    }

    public function state(): array
    {
        $credentials = [];
        foreach ($this->environment->agents->all() as $agent) {
            $credentials[$agent->getId()->toString()] = hash('sha256', serialize($agent));
        }

        return [
            'agents'         => count($this->environment->agents->all()),
            'operations'     => count($this->environment->operations->operations),
            'audit'          => count($this->environment->audit->all()),
            'participant'    => $this->participant,
            'reservations'   => $this->environment->operations->versions,
            'credentials'    => $credentials,
            'audit_facts'    => array_map(
                static fn (AuditEvidence $fact): string => hash('sha256', serialize($fact)),
                $this->environment->audit->all()
            ),
            'issuance_facts' => array_map(
                static fn (AgentCredentialOperation $operation): string => hash('sha256', serialize([
                    $operation->getCanonicalVersion(),
                    $operation->getCanonicalRequest(),
                    $operation->getIssuance()->toArray(),
                    $operation->getStatus()->getCredentialDisposition()
                ])),
                $this->environment->operations->operations
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
            $this->environment->agents,
            new InMemoryPermissionRepository(),
            new FixedHmacSharedSecretDecipher('conformance-auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($request, $secret),
            $this->clock,
            new InMemoryAgentRequestNonceConsumer($this->environment->agents, $this->environment->transaction),
            $this->environment->transaction
        )->resolve($request, 'conformance-current-use');
    }

    public function forbiddenValues(): array
    {
        $values = ['conformance-auth-envelope:', 'unsafe-conformance-provider', '/private/conformance-key'];
        for ($number = 1; $number <= $this->generated; ++$number) {
            $values[] = 'conformance-secret-'.$number;
        }

        foreach ($this->environment->operations->operations as $operation) {
            if ($operation->getMaterial() !== null) {
                $values[] = $operation->getMaterial()->getCiphertext()->reveal();
            }
        }

        return $values;
    }

    public function safeEvidence(): array
    {
        return [...$this->attempted, ...$this->environment->audit->all()];
    }

    public function maintenance(): AgentDeliveryMaintenanceService
    {
        $operation = array_first($this->environment->operations->operations);
        $issuance = $operation?->getIssuance() ?? throw new LogicException('Missing maintenance fixture.');
        $delivery = new DeliveryEnvironment($this->environment, $issuance);
        $delivery->clock->time = $this->clock->now();

        $maintenance = new MaintenanceEnvironment($delivery);

        return new AgentDeliveryMaintenanceService(
            $this->environment->operations,
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
