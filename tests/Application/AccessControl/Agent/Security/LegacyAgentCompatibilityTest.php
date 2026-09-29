<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentByIdHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListAgentsHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentPublicationWarning;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\UncertainAgentUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameter;
use Throwable;

#[CoversClass(Agent::class)]
#[CoversClass(AgentView::class)]
#[CoversClass(AgentCredentialRotationService::class)]
#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(CurrentAgentPrincipalProvider::class)]
#[CoversClass(GetAgentByIdHandler::class)]
#[CoversClass(ListAgentsHandler::class)]
#[CoversClass(GetAgentOperationHandler::class)]
final class LegacyAgentCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function booleans(): iterable
    {
        yield 'false' => [false];
        yield 'true' => [true];
    }

    #[DataProvider('booleans')]
    public function test_upgrade_reads_and_authentication_preserve_active_and_revoked_history(bool $revoked): void
    {
        $env = new ProvisioningEnvironment();
        $agent = $this->loadLegacy($env, $revoked ? AgentState::REVOKED : AgentState::ACTIVE);
        $before = serialize($agent);
        $view = new GetAgentByIdHandler($env->agents, $this->permissions())->handle(
            QueryMessage::create(new GetAgentById($agent->getId()))
        );
        self::assertNotNull($view);
        self::assertFalse($view->hasRecoverableCredentialOperation());
        self::assertFalse($view->toArray()['recoverable_credential_operation']);
        self::assertSame(7, $view->getCredentialRevision());
        self::assertSame(12, $view->getPermissionAssignmentRevision());
        self::assertSame($agent->getState(), $view->getState());
        self::assertSame($agent->getCredentialId(), $view->getCredentialId());
        $list = new ListAgentsHandler($env->agents, $this->permissions())->handle(
            QueryMessage::create(new ListAgents(new Pagination()))
        );
        self::assertSame($view->toArray(), $list->records()->get(0)->toArray());
        // An invented lookup key must stay indeterminate, never infer provision from a name or audit fact.
        $status = $this->operationStatus($env);
        self::assertFalse($status->isConfirmed());
        self::assertNull($status->getIssuance());
        self::assertNull($status->getDeliveryDisposition());
        self::assertSame(0, $env->transaction->transactions);
        self::assertSame([], $env->events->events());
        $request = $this->request($agent->getCredentialId(), 'legacy-nonce');
        $transaction = new InMemoryUnitOfWork();
        $nonces = new InMemoryAgentRequestNonceConsumer($env->agents, $transaction);
        try {
            $principal = $this->provider($env, $request, 'legacy-test-secret', $nonces, $transaction)
                ->resolve($request, 'legacy-upgrade');
            self::assertFalse($revoked, 'Revoked historical authority must never authenticate.');
            self::assertSame($agent->getId()->toString(), $principal->toArray()['agent_id']);
            self::assertSame($agent->getCredentialId()->toString(), $principal->toArray()['credential_id']);
            self::assertSame(7, $principal->toArray()['credential_revision']);
            self::assertSame(12, $principal->toArray()['permission_assignment_revision']);
            self::assertSame([
                ['permission_id' => $this->permissionId()->toString(), 'name' => 'VIEW_AGENTS']
            ], $principal->toArray()['permissions']);
            $this->assertSafe($principal);
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            self::assertTrue($revoked);
            $this->assertSafe($currentAgentPrincipalResolutionRejectedException);
        }

        self::assertSame($revoked ? 0 : 1, $nonces->consumptionCalls());
        self::assertSame($before, serialize($env->agents->all()[0]));
        self::assertSame('auth-envelope:legacy-test-secret', $agent->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame([], $env->operations->operations);
        self::assertSame([], $env->operations->versions);
        self::assertSame([], $env->audit->all());
        self::assertSame([], $env->events->events());
        self::assertSame(0, $env->generations);
        self::assertSame(0, $env->operations->deliveryWrites);
        $this->assertSafe([$view, $status, $list->records()->get(0)]);
    }

    public function test_explicit_rotation_creates_only_new_recovery_and_retries_original_request_after_restart(): void
    {
        $env = new ProvisioningEnvironment();
        $legacy = $this->loadLegacy($env);
        $request = $this->rotationRequest($env, $legacy);
        $env->events = new InMemoryEventDispatcher(static function () use ($env, $legacy): void {
            self::assertFalse($env->transaction->transactionActive);
            self::assertCount(1, $env->operations->operations);
            self::assertCount(1, $env->audit->all());
            self::assertNull($env->agents->getByCredentialId($legacy->getCredentialId()));
        });
        $result = $this->rotation($env)->rotate($env->key, $request);
        self::assertTrue($result->isConfirmed());
        self::assertNull($result->getWarning());
        $issuance = $result->getIssuance();
        self::assertNotNull($issuance);
        self::assertSame(8, $issuance->getCredentialRevision());
        self::assertSame(1, $issuance->getDestinationWriteVersion());
        $successor = $env->agents->all()[0];
        self::assertTrue($successor->hasRecoverableCredentialOperation());
        self::assertSame($legacy->getId(), $successor->getId());
        self::assertSame($legacy->getName(), $successor->getName());
        self::assertSame($legacy->getCreatedAt(), $successor->getCreatedAt());
        self::assertSame($legacy->getPermissionIds(), $successor->getPermissionIds());
        self::assertSame(12, $successor->getPermissionAssignmentRevision());
        self::assertSame('auth-envelope:successor-test-secret', $successor->getEncryptedHmacSharedSecretEnvelope());
        self::assertFalse($legacy->hasRecoverableCredentialOperation());
        $stored = $env->operations->operations[$env->key->toString()];
        self::assertSame($request->canonicalize(1), $stored->getCanonicalRequest());
        $material = $stored->getMaterial();
        self::assertNotNull($material);
        self::assertSame('successor-test-secret', new BoundAgentDeliveryCipher()->inspect($material, $issuance));
        self::assertStringNotContainsString('legacy-test-secret', $material->getCiphertext()->reveal());
        self::assertSame($issuance, $this->rotation($env)->rotate($env->key, $request)->getIssuance());
        self::assertCount(1, $env->agents->all());
        self::assertCount(1, $env->operations->operations);
        self::assertCount(1, $env->audit->all());
        self::assertSame('agent.credential_rotated', $env->audit->all()[0]->action());
        self::assertCount(1, $env->events->events());
        self::assertInstanceOf(AgentCredentialRotated::class, $env->events->events()[0]);
        self::assertSame(1, $env->generations);
        self::assertSame(AgentDeliveryDisposition::PENDING, $this->operationStatus($env)->getDeliveryDisposition());
        $view = AgentView::fromAgent($successor, []);
        self::assertTrue($view->hasRecoverableCredentialOperation());
        self::assertTrue($view->toArray()['recoverable_credential_operation']);
        $this->assertSafe([$result, $view, $this->operationStatus($env), $env->audit->all(), $env->events->events()]);
        $this->reject(
            fn(): AgentCredentialRotationResult => $this->rotation($env)->rotate(
                new AgentOperationKey($env->key->getScope(), AgentOperationId::generate()),
                $request
            ),
            AgentOperationFailure::CONFLICT
        );
        self::assertSame(1, $env->generations);
        // A subsequent current-authority denial cannot disclose even this original retained result.
        $env->authorization->deniedTargets[] = $legacy->getId()->toString();
        $this->reject(
            fn(): AgentCredentialRotationResult => $this->rotation($env)->rotate($env->key, $request),
            AgentOperationFailure::UNAUTHORIZED
        );
        $this->reject(fn(): AgentOperationView => $this->operationStatus($env), AgentOperationFailure::UNAUTHORIZED);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedRotations(): iterable
    {
        foreach (
            ['scope', 'target', 'destination', 'delegation', 'credential', 'revision', 'revoked', 'missing'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('rejectedRotations')]
    public function test_denied_stale_and_revoked_legacy_rotation_never_mutates_or_generates(string $case): void
    {
        $env = new ProvisioningEnvironment();
        $legacy = $this->loadLegacy($env, $case === 'revoked' ? AgentState::REVOKED : AgentState::ACTIVE);
        if ($case === 'scope') {
            $env->authorization->scopes = [];
        }

        if ($case === 'target') {
            $env->authorization->deniedTargets[] = $legacy->getId()->toString();
        }

        if ($case === 'destination') {
            $env->authorization->destinations = [];
        }

        $env->authorization->delegationExpired = $case === 'delegation';
        $request = new AgentRotationRequest(
            $case === 'missing' ? AgentId::generate() : $legacy->getId(),
            $case === 'credential' ? AgentCredentialId::generate() : $legacy->getCredentialId(),
            $case === 'revision' ? 6 : 7,
            $env->request->getDestination()
        );
        $reason = AgentOperationFailure::CONFLICT;
        if (in_array($case, ['scope', 'target', 'destination', 'delegation'], true)) {
            $reason = AgentOperationFailure::UNAUTHORIZED;
        }

        $this->reject(
            fn(): AgentCredentialRotationResult => $this->rotation($env)->rotate($env->key, $request),
            $reason
        );
        self::assertSame($legacy, $env->agents->all()[0]);
        self::assertSame([], $env->operations->operations);
        self::assertSame([], $env->operations->versions);
        self::assertSame([], $env->audit->all());
        self::assertSame(0, $env->generations);
        $this->assertSafe($env->events->events());
    }

    #[DataProvider('booleans')]
    public function test_failed_operation_persistence_rolls_back_the_legacy_transition(bool $afterAdd): void
    {
        $env = new ProvisioningEnvironment();
        $legacy = $this->loadLegacy($env);
        $fail = static function (): void {
            throw new RuntimeException('unsafe-provider /keys/private legacy-test-secret');
        };
        if ($afterAdd) {
            $env->operations->afterAdd = $fail;
        } else {
            $env->operations->beforeAdd = $fail;
        }

        $this->reject(
            fn(): AgentCredentialRotationResult => $this->rotation($env)->rotate(
                $env->key,
                $this->rotationRequest($env, $legacy)
            ),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertSame($legacy, $env->agents->all()[0]);
        self::assertFalse($env->agents->all()[0]->hasRecoverableCredentialOperation());
        self::assertSame([], $env->operations->operations);
        self::assertSame([], $env->operations->versions);
        self::assertSame([], $env->audit->all());
        self::assertSame(1, $env->generations);
        $this->assertSafe($env->events->events());
    }

    #[DataProvider('booleans')]
    public function test_uncertain_commit_resolves_only_the_original_legacy_rotation(bool $persist): void
    {
        $env = new ProvisioningEnvironment();
        $legacy = $this->loadLegacy($env);
        $request = $this->rotationRequest($env, $legacy);
        $result = $this->rotation($env, new UncertainAgentUnitOfWork($env->transaction, $persist))
            ->rotate($env->key, $request);
        self::assertFalse($result->isConfirmed());
        self::assertNull($result->getIssuance());
        self::assertSame($persist, $env->agents->all()[0]->hasRecoverableCredentialOperation());
        self::assertCount((int) $persist, $env->operations->operations);
        $resolved = $this->rotation($env)->rotate($env->key, $request);
        self::assertTrue($resolved->isConfirmed());
        self::assertSame(8, $env->agents->all()[0]->getCredentialRevision());
        self::assertCount(1, $env->operations->operations);
        self::assertCount(1, $env->audit->all());
        self::assertSame($persist ? 1 : 2, $env->generations);
        $this->assertSafe([$result, $resolved]);
    }

    public function test_both_publishers_can_fail_without_disguising_the_committed_legacy_transition(): void
    {
        $env = new ProvisioningEnvironment();
        $legacy = $this->loadLegacy($env);
        $env->events = new InMemoryEventDispatcher(static function (): void {
            throw new RuntimeException('unsafe-provider /keys/private successor-test-secret');
        });
        $request = $this->rotationRequest($env, $legacy);
        $result = $this->rotation($env)->rotate($env->key, $request);
        self::assertTrue($result->isConfirmed());
        self::assertSame(AgentPublicationWarning::PUBLICATION_FAILED, $result->getWarning());
        self::assertSame($result->getIssuance(), $this->rotation($env)->rotate($env->key, $request)->getIssuance());
        self::assertSame(1, $env->generations);
        self::assertCount(1, $env->audit->all());
        self::assertCount(1, $env->operations->operations);
        self::assertSame(AgentDeliveryDisposition::PENDING, $this->operationStatus($env)->getDeliveryDisposition());
        $this->assertSafe($result);
    }

    #[DataProvider('booleans')]
    public function test_legacy_rotation_fences_authentication_and_nonce_replay(bool $after): void
    {
        $env = new ProvisioningEnvironment();
        $legacy = $this->loadLegacy($env);
        $transaction = new InMemoryUnitOfWork();
        $rotate = function () use ($env, $legacy): void {
            $result = $this->rotation($env)->rotate($env->key, $this->rotationRequest($env, $legacy));
            self::assertTrue($result->isConfirmed());
        };
        $nonces = new InMemoryAgentRequestNonceConsumer(
            $env->agents,
            $transaction,
            beforeConsume: $after ? null : $rotate,
            afterConsume: $after ? $rotate : null
        );
        $original = $this->request($legacy->getCredentialId(), 'inflight-nonce');
        try {
            $this->provider($env, $original, 'legacy-test-secret', $nonces, $transaction)
                ->resolve($original, 'inflight');
            self::fail('The old credential cannot authenticate after its explicit replacement.');
        } catch (CurrentAgentPrincipalResolutionRejectedException) {
            self::assertSame(1, $nonces->consumptionCalls());
        }

        $successor = $this->request($env->agents->all()[0]->getCredentialId(), 'successor-nonce');
        $nonces = new InMemoryAgentRequestNonceConsumer($env->agents, $transaction);
        $principal = $this->provider($env, $successor, 'successor-test-secret', $nonces, $transaction)
            ->resolve($successor, 'successor');
        self::assertSame(8, $principal->toArray()['credential_revision']);
        self::assertSame(12, $principal->toArray()['permission_assignment_revision']);
        foreach ([$original, $successor] as $request) {
            try {
                $this->provider($env, $request, 'successor-test-secret', $nonces, $transaction)
                    ->resolve($request, 'denied');
                self::fail('Retired credentials and replayed successor nonces must reject.');
            } catch (CurrentAgentPrincipalResolutionRejectedException) {
                self::assertSame(8, $env->agents->all()[0]->getCredentialRevision());
            }
        }
    }

    public function test_legacy_raw_service_rotation_remains_rejected_without_implicit_bindings(): void
    {
        $env = new ProvisioningEnvironment();
        $legacy = $this->loadLegacy($env);
        $service = new AgentCredentialLifecycleService(
            $env->agents,
            $env->audit,
            new FixedClock($this->now()),
            $env->transaction,
            $env->events
        );
        $this->reject(
            fn() => $service->rotate('maintainer-42', $legacy->getId(), $legacy->getCredentialId()),
            AgentOperationFailure::INVALID_REQUEST
        );
        self::assertSame(0, $env->transaction->transactions);
        self::assertSame($legacy, $env->agents->all()[0]);
        self::assertSame([], $env->operations->operations);
        self::assertSame([], $env->events->events());
        self::assertSame([], $env->audit->all());
    }

    private function loadLegacy(ProvisioningEnvironment $env, AgentState $state = AgentState::ACTIVE): Agent
    {
        // Represents a stored pre-replacement row, not provision() or a fabricated operation history.
        $agent = Agent::reconstitute(
            AgentId::fromString('018f0000-0000-7000-8000-000000005501'),
            AgentName::fromString('Existing deployment'),
            $state,
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000005502'),
            7,
            'auth-envelope:legacy-test-secret',
            [$this->permissionId()],
            12,
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
            false
        );
        $env->agents->add($agent);

        return $agent;
    }

    private function rotationRequest(ProvisioningEnvironment $env, Agent $agent): AgentRotationRequest
    {
        return new AgentRotationRequest(
            $agent->getId(),
            $agent->getCredentialId(),
            $agent->getCredentialRevision(),
            $env->request->getDestination()
        );
    }

    private function rotation(
        ProvisioningEnvironment $env,
        ?TransactionalUnitOfWork $transaction = null
    ): AgentCredentialRotationService {
        return new AgentCredentialRotationService(
            $env->agents,
            $env->operations,
            $env->audit,
            $env->authorization,
            new readonly class ($env) implements HmacSharedSecretGenerator {
                public function __construct(private ProvisioningEnvironment $env)
                {
                }

                public function generate(): string
                {
                    ++$this->env->generations;

                    return 'successor-test-secret';
                }
            },
            new FixedHmacSharedSecretCipher('auth-envelope:'),
            new BoundAgentDeliveryCipher(),
            new FixedClock($this->now()),
            $transaction ?? $env->transaction,
            $env->events
        );
    }

    private function operationStatus(#[SensitiveParameter] ProvisioningEnvironment $env): AgentOperationView
    {
        return new GetAgentOperationHandler($env->operations, $env->authorization)->handle(
            QueryMessage::create(new GetAgentOperation($env->key, $env->request->getDestination()))
        );
    }

    private function request(AgentCredentialId $credential, string $nonce): SignedAgentRequest
    {
        return new SignedAgentRequest(
            'POST',
            'api.fight.example',
            '/agents',
            '',
            $this->now(),
            $nonce,
            $credential,
            'HMAC-SHA256',
            'valid-signature',
            null,
            ''
        );
    }

    private function provider(
        ProvisioningEnvironment $env,
        SignedAgentRequest $request,
        string $secret,
        InMemoryAgentRequestNonceConsumer $nonces,
        InMemoryUnitOfWork $transaction
    ): CurrentAgentPrincipalProvider {
        return new CurrentAgentPrincipalProvider(
            $env->agents,
            $this->permissions(),
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($request, $secret),
            new FixedClock($this->now()),
            $nonces,
            $transaction
        );
    }

    private function permissions(): InMemoryPermissionRepository
    {
        $permissions = new InMemoryPermissionRepository();
        $permissions->add(Permission::define(
            $this->permissionId(),
            PermissionName::fromString('VIEW_AGENTS'),
            $this->now()
        ));

        return $permissions;
    }

    private function permissionId(): PermissionId
    {
        return PermissionId::fromString('018f0000-0000-7000-8000-000000005503');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-27T12:00:00Z');
    }

    private function reject(#[SensitiveParameter] Closure $action, AgentOperationFailure $reason): void
    {
        try {
            $action();
            self::fail('Expected a safe rejected operation.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            $this->assertSafe($agentOperationRejectedException);
        }
    }

    private function assertSafe(mixed $value): void
    {
        $surface = '';
        if ($value instanceof Throwable) {
            $surface = (string) $value;
            $trace = [];
            foreach ($value->getTrace() as $frame) {
                // Inspect the failing call chain, not PHPUnit's runner object graph.
                if (str_starts_with($frame['function'], 'test_')) {
                    break;
                }

                $trace[] = $frame;
            }

            $value = ['message' => $value->getMessage(), 'trace' => $trace];
        } else {
            $surface = serialize($value).json_encode($value, JSON_THROW_ON_ERROR);
        }

        $surface .= print_r($value, true);
        $forbidden = [
            'legacy-test-secret', 'successor-test-secret', 'auth-envelope:', '/keys/private', 'unsafe-provider'
        ];
        foreach ($forbidden as $secret) {
            self::assertStringNotContainsString($secret, $surface);
        }
    }
}
