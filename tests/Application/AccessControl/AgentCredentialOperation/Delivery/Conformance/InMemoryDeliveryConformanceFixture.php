<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\Conformance;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentCredentialCleanup;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryRewrapper;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance\AgentCanonicalFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance\AgentCohortFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance\AgentRestorationFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance\InMemoryAgentRestoration;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\Conformance\IssuanceRecoveryPorts;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\FixedHmacSharedSecretCipher;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\ReceiptLookupSink;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\MaintenanceEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Models persisted state and deterministic interleavings, not a database, process crash or real key service */
final class InMemoryDeliveryConformanceFixture extends DeliveryConformanceFixture implements
    AgentCanonicalFixture,
    AgentCohortFixture,
    AgentRestorationFixture
{
    private readonly DeliveryEnvironment $delivery;

    private readonly MaintenanceEnvironment $maintenance;

    private readonly InMemoryAgentRestoration $restoration;

    /** @var list<string> */
    private array $ciphertexts = [];

    private ?Throwable $publicationFailure = null;

    /** @var list<Event> */
    private array $events = [];

    public function __construct(private readonly bool $receiptLookup = true)
    {
        $this->delivery = new DeliveryEnvironment();
        $this->maintenance = new MaintenanceEnvironment($this->delivery);
        $this->restoration = new InMemoryAgentRestoration($this->delivery);
        $this->delivery->deliveryAuthorization->expiresAt = $this->delivery->clock->now()->modify('+30 days');
    }

    public function breakCohort(string $failure): void
    {
        $state = $this->delivery->provisioning->operationRepository->contract;
        if ($failure === 'missing storage') {
            $state->current = null;

            return;
        }

        if ($failure === 'storage outage') {
            $state->unavailable = true;

            return;
        }

        $state->switchTo(new AgentOperationContract(
            $failure === 'storage version' ? 2 : 1,
            match ($failure) {
                'obsolete canonical marker' => 1,
                'unknown canonical marker' => 99,
                default => 2
            },
            $failure === 'destination version' ? 2 : 1,
            2,
            array_values(array_diff(AgentOperationContract::REQUIRED_CAPABILITIES, [$failure])),
            match ($failure) {
                'missing reconciliation' => null,
                'restored generation' => 3,
                default => 2
            }
        ));
    }

    public function switchCohort(int $generation): void
    {
        $this->delivery->provisioning->operationRepository->contract->switchTo(new AgentOperationContract(
            1,
            2,
            1,
            $generation,
            AgentOperationContract::REQUIRED_CAPABILITIES,
            $generation
        ));
        $state = $this->delivery->provisioning->operationRepository->contract;
        $state->generation = $generation;
        $state->reconciledGeneration = $generation;
    }

    public function savePackageState(string $checkpoint): void
    {
        $this->restoration->savePackageState($checkpoint);
    }

    public function restorePackageState(string $checkpoint): void
    {
        $this->restoration->restorePackageState($checkpoint);
    }

    public function reconcilePackageState(string $checkpoint): bool
    {
        return $this->restoration->reconcilePackageState($checkpoint);
    }

    public function packageStateFingerprint(): string
    {
        return $this->restoration->packageStateFingerprint();
    }

    public function breakRestorationEvidence(string $failure): void
    {
        $this->restoration->breakRestorationEvidence($failure);
    }

    public function expireOperationDelegation(): void
    {
        $operationAuthorization = $this->delivery->provisioning->operationAuthorization;
        $operationAuthorization->changeAuthority(static function () use ($operationAuthorization): void {
            $operationAuthorization->delegationExpired = true;
        });
    }

    public function corruptBinding(AgentIssuance $issuance, int $version, ?string $binding = null): void
    {
        $stored = $this->stored($issuance);
        $operationRepository = $this->delivery->provisioning->operationRepository;
        $operationRepository->operations[$issuance->getKey()->toString()] = new AgentCredentialOperation(
            $version,
            $binding ?? $stored->getCanonicalRequest(),
            $issuance,
            $stored->getMaterial(),
            $stored->getDeliveryDisposition(),
            $stored->getCredentialDisposition(),
            $stored->getStateRevision(),
            $stored->getAttempt(),
            $stored->getDeliveryPolicy(),
            $stored->getRetryAt(),
            $stored->getReceipt(),
            $stored->getDeliveryFailure(),
            $stored->isSinkCleaned()
        );
    }

    public function original(): AgentIssuance
    {
        return $this->delivery->issuance;
    }

    public function ports(): IssuanceRecoveryPorts
    {
        $env = $this->delivery;

        return new IssuanceRecoveryPorts(
            $env->provisioning->agentRepository,
            $env->provisioning->operationRepository,
            $env->provisioning->operationRepository,
            $env->provisioning->auditEvidenceRepository,
            $env->provisioning->operationAuthorization,
            new readonly class ($env) implements HmacSharedSecretGenerator {
                public function __construct(private DeliveryEnvironment $environment)
                {
                }

                public function generate(): string
                {
                    return 'delivery-conformance-secret-'.++$this->environment->provisioning->generations;
                }
            },
            new FixedHmacSharedSecretCipher('auth-envelope:'),
            new BoundAgentDeliveryCipher(),
            $env->decipher,
            $env->deliveryAuthorization,
            $this->receiptLookup ? new ReceiptLookupSink($env) : $env->sink,
            $env->clock,
            $env->transaction,
            new InMemoryEventDispatcher(function (Event $event): void {
                if ($this->delivery->provisioning->transaction->transactionActive) {
                    throw new LogicException('Publication must follow commit.');
                }

                $this->events[] = $event;
                if ($this->publicationFailure !== null) {
                    throw $this->publicationFailure;
                }
            })
        );
    }

    public function maintenanceAuthorization(): AgentMaintenanceAuthorization
    {
        return $this->maintenance;
    }

    public function rewrapper(): AgentDeliveryRewrapper
    {
        return $this->maintenance;
    }

    public function cleanupSink(): AgentCredentialCleanup
    {
        return $this->delivery->sink;
    }

    public function allow(AgentOperationScope $scope, AgentCredentialDestination $destination): void
    {
        $operationAuthorization = $this->delivery->provisioning->operationAuthorization;
        $operationAuthorization->changeAuthority(
            static function () use ($operationAuthorization, $scope, $destination): void {
                $operationAuthorization->scopes[$scope->toString()] = true;
                $operationAuthorization->destinations[$destination->getId()->toString()] = $destination->getRevision();
                $operationAuthorization->actor = $scope->getCallerId();
            }
        );
    }

    public function restart(): void
    {
        // These objects stand for durable adapters. Each suite call reconstructs Application services via ports().
        $env = $this->delivery;
        $env->transaction->afterCommit = null;
        $env->transaction->uncertainAt = null;
        $env->provisioning->operationAuthorization->afterAuthorization = null;
        $env->provisioning->operationRepository->afterDeliveryWrite = null;
        $env->provisioning->operationRepository->afterDueRead = null;
        $env->provisioning->operationRepository->afterStatusRead = null;
        $env->decipher->afterMaterialize = null;
        $env->decipher->failure = null;
        $env->sink->beforeStage = null;
        $env->sink->afterStage = null;
        $env->sink->beforeVerify = null;

        $this->maintenance->failure = null;
        $this->publicationFailure = null;
    }

    public function advance(int $seconds): void
    {
        $this->delivery->clock->advance($seconds);
    }

    public function pause(string $boundary, Closure $action): void
    {
        $env = $this->delivery;
        $fired = false;
        $once = static function () use (&$fired, $action): void {
            if (!$fired) {
                $fired = true;
                $action();
            }
        };
        if ($boundary === 'claim' || $boundary === 'admission') {
            $at = $env->transaction->commits + ($boundary === 'claim' ? 1 : 2);
            $env->transaction->afterCommit = static function (int $commit) use ($at, $once): void {
                if ($commit === $at) {
                    $once();
                }
            };
        } elseif ($boundary === 'materialized') {
            $env->decipher->afterMaterialize = $once;
        } elseif ($boundary === 'invoking') {
            $env->sink->beforeStage = $once;
        } elseif ($boundary === 'accepted') {
            $env->sink->afterStage = $once;
        } elseif ($boundary === 'verifying') {
            $env->sink->beforeVerify = $once;
        } else {
            $env->provisioning->operationAuthorization->afterAuthorization = $once;
        }
    }

    public function changeAuthority(string $change, AgentIssuance $issuance): void
    {
        $env = $this->delivery;
        $operationAuthorization = $env->provisioning->operationAuthorization;
        $operationAuthorization->changeAuthority(
            static function () use ($env, $operationAuthorization, $change, $issuance): void {
                match ($change) {
                    'caller' => $operationAuthorization->scopes[$issuance->getKey()->getScope()->toString()] = false,
                    'permission', 'aba' => $env->deliveryAuthorization->permitted = false,
                    'delegation' => $env->deliveryAuthorization->expiresAt = $env->clock->now(),
                    'destination' => $operationAuthorization->destinations[
                        $issuance->getDestination()->getId()->toString()
                    ] = 2
                };
            }
        );
        if ($change === 'aba') {
            $operationAuthorization->changeAuthority(static function () use ($env): void {
                $env->deliveryAuthorization->permitted = true;
            });
        }
    }

    public function expireAuthorityAfter(int $seconds): void
    {
        $this->delivery->deliveryAuthorization->expiresAt = $this->delivery->clock->now()->modify(
            '+'.$seconds.' seconds'
        );
    }

    public function loseCommit(int $stage, bool $persist): void
    {
        $this->delivery->transaction->uncertainAt = $this->delivery->transaction->commits + $stage;
        $this->delivery->transaction->persistUncertain = $persist;
    }

    public function failWrite(int $stage): void
    {
        $operationRepository = $this->delivery->provisioning->operationRepository;
        $at = $operationRepository->deliveryWrites + $stage;
        $operationRepository->afterDeliveryWrite = static function () use ($operationRepository, $at): void {
            if ($operationRepository->deliveryWrites === $at) {
                throw new RuntimeException('unsafe-delivery-provider /private/delivery-key');
            }
        };
    }

    public function storageUnavailable(): void
    {
        $fail = static function (): void {
            throw new RuntimeException('unsafe-delivery-provider /private/delivery-key');
        };
        $this->delivery->provisioning->operationRepository->afterDueRead = $fail;
        $this->delivery->provisioning->operationRepository->afterStatusRead = $fail;
    }

    public function keyFailure(?AgentDeliveryFailure $failure): void
    {
        $this->delivery->decipher->failure = $failure;
        $this->maintenance->failure = $failure;
    }

    public function corruptMaterial(AgentIssuance $issuance, ?AgentIssuance $source = null): void
    {
        $original = $this->stored($issuance);
        $material = new AgentDeliveryMaterial(
            EncryptedCredentialMaterial::fromString('corrupted-delivery-copy'),
            $original->getMaterial()?->getKeyVersion() ?? 'test-key-v1'
        );
        if ($source !== null) {
            $material = $this->stored($source)->getMaterial() ?? throw new LogicException('Missing source material.');
        }

        $operationRepository = $this->delivery->provisioning->operationRepository;
        $operationRepository->operations[$issuance->getKey()->toString()] = new AgentCredentialOperation(
            $original->getCanonicalVersion(),
            $original->getCanonicalRequest(),
            $original->getIssuance(),
            $material,
            $original->getDeliveryDisposition(),
            $original->getCredentialDisposition(),
            $original->getStateRevision(),
            $original->getAttempt(),
            $original->getDeliveryPolicy(),
            $original->getRetryAt(),
            $original->getReceipt(),
            $original->getDeliveryFailure(),
            $original->isSinkCleaned()
        );
    }

    public function loseSinkResponse(): void
    {
        $this->pause('accepted', static function (): void {
            throw new RuntimeException('unsafe-delivery-provider /private/delivery-key');
        });
    }

    public function forgetSinkMaterial(AgentIssuance $issuance): void
    {
        $this->delivery->sink->forgetMaterial($issuance);
    }

    public function failPublishers(): Throwable
    {
        $this->publicationFailure = new RuntimeException('unsafe-delivery-provider /private/delivery-key');

        return $this->publicationFailure;
    }

    public function stored(AgentIssuance $issuance): AgentCredentialOperation
    {
        $operation = $this->delivery->provisioning->operationRepository->operations[
            $issuance->getKey()->toString()
        ];
        if ($operation->getMaterial() !== null) {
            $this->ciphertexts[] = $operation->getMaterial()->getCiphertext()->reveal();
        }

        return $operation;
    }

    public function preparedBytes(AgentIssuance $issuance): string
    {
        $material = $this->stored($issuance)->getMaterial() ?? throw new LogicException('Missing test oracle.');

        return new BoundAgentDeliveryCipher()->inspect($material, $issuance);
    }

    public function authenticate(
        AgentIssuance $issuance,
        #[SensitiveParameter] string $secret
    ): AuthenticatedAgentPrincipal {
        $env = $this->delivery;
        $request = new SignedAgentRequest(
            'POST',
            'conformance.example',
            '/use',
            '',
            $env->clock->now(),
            bin2hex(random_bytes(16)),
            $issuance->getCredentialId(),
            'HMAC-SHA256',
            'fixture-signature',
            null,
            ''
        );

        return new CurrentAgentPrincipalProvider(
            $env->provisioning->agentRepository,
            new InMemoryPermissionRepository(),
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($request, $secret),
            $env->clock,
            new InMemoryAgentRequestNonceConsumer($env->provisioning->agentRepository, $env->provisioning->transaction),
            $env->provisioning->transaction
        )->resolve($request, 'delivery-conformance-use');
    }

    public function stagedBytes(AgentIssuance $issuance): ?string
    {
        return $this->delivery->sink->stagedBytes($issuance);
    }

    public function receipt(AgentIssuance $issuance): ?AgentDeliveryReceipt
    {
        return $this->delivery->sink->receiptFor($issuance);
    }

    public function highWater(AgentCredentialDestination $destination): int
    {
        return $this->delivery->sink->highWater[$destination->getId()->toString()] ?? 0;
    }

    public function counts(): array
    {
        $env = $this->delivery;

        return [
            'decryptions'  => $env->decipher->calls,
            'sink'         => $env->sink->calls,
            'transactions' => $env->provisioning->transaction->transactions,
            'generations'  => $env->provisioning->generations,
            'audit'        => count($env->provisioning->auditEvidenceRepository->all()),
            'events'       => count($this->events)
        ];
    }

    public function safeEvidence(): array
    {
        return [...$this->events, ...$this->delivery->provisioning->auditEvidenceRepository->all()];
    }

    public function forbiddenValues(): array
    {
        $values = ['original-test-secret', 'auth-envelope:', 'unsafe-delivery-provider', '/private/delivery-key'];
        for ($number = 2; $number <= $this->delivery->provisioning->generations; ++$number) {
            $values[] = 'delivery-conformance-secret-'.$number;
        }

        return [...$values, ...array_unique($this->ciphertexts)];
    }
}
