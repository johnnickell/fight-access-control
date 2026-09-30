<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\AgentCanonicalFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\DeliveryConformanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;

/** Consumer-bindable M3 proof of one canonical contract through public services and persisted outcomes */
abstract class AgentCanonicalConformance extends DeliveryConformance
{
    /** @return iterable<string, array{bool, string}> */
    public static function retainedStates(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach (['pending', 'delivered', 'revoked', 'superseded', 'expired', 'cleaned'] as $state) {
                yield ($rotation ? 'rotation' : 'provision').' '.$state => [$rotation, $state];
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function operations(): iterable
    {
        yield 'provision' => [false];
        yield 'rotation' => [true];
    }

    #[DataProvider('retainedStates')]
    public function test_restart_preserves_original_keys_in_every_retained_state(bool $rotation, string $state): void
    {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation);
        if ($state === 'delivered' || $state === 'cleaned') {
            self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $issuance));
        }

        if ($state === 'revoked' || $state === 'cleaned') {
            $this->revoke($fixture, $issuance);
        } elseif ($state === 'superseded') {
            $this->rotate($fixture, $issuance);
        }

        if ($state === 'expired' || $state === 'cleaned') {
            $fixture->advance(172801);
            $service = $this->maintenance($fixture);
            if ($state === 'expired') {
                self::assertSame(AgentMaintenanceResult::EXPIRED, $service->expire(
                    $key,
                    $issuance->getDestination(),
                    $issuance->getDeliveryId()
                ));
            } else {
                self::assertSame(AgentMaintenanceResult::CLEANED, $service->cleanup(
                    $key,
                    $issuance->getDestination(),
                    $issuance->getDeliveryId()
                ));
            }
        }

        $stored = $fixture->stored($issuance);
        if ($state !== 'pending') {
            self::assertNull($stored->getMaterial());
        }

        $fixture->restart();
        $before = $fixture->counts();
        $agent = $fixture->ports()->agents->getById($issuance->getAgentId());
        $order = $fixture->highWater($issuance->getDestination());
        $retry = $request;
        if ($request instanceof AgentProvisioningRequest) {
            $retry = new AgentProvisioningRequest(' Runner ', $request->getDestination());
        }

        self::assertEquals($issuance, $this->issue($fixture, $key, $retry));
        self::assertEquals($issuance, $this->issue($fixture, $key, $request));
        self::assertSame(2, $this->readOperation($fixture, $issuance)->getCanonicalVersion());
        self::assertEquals($stored, $fixture->stored($issuance));
        self::assertEquals($agent, $fixture->ports()->agents->getById($issuance->getAgentId()));
        self::assertSame($order, $fixture->highWater($issuance->getDestination()));
        $this->assertNoIssuanceEffects($fixture, $before, true);
    }

    #[DataProvider('operations')]
    public function test_changed_bindings_conflict_after_restart(bool $rotation): void
    {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation);
        $fixture->restart();
        $destination = $request->getDestination();
        if ($request instanceof AgentRotationRequest) {
            $changed = [
                new AgentRotationRequest(AgentId::generate(), $request->getExpectedCredentialId(), 0, $destination),
                new AgentRotationRequest($request->getAgentId(), AgentCredentialId::generate(), 0, $destination),
                new AgentRotationRequest($request->getAgentId(), $request->getExpectedCredentialId(), 5, $destination),
                new AgentProvisioningRequest('Runner', $destination)
            ];
        } else {
            $changed = [
                new AgentProvisioningRequest('runner', $destination),
                new AgentProvisioningRequest('Run ner', $destination),
                new AgentProvisioningRequest('Other', $destination),
                new AgentRotationRequest($issuance->getAgentId(), $issuance->getCredentialId(), 0, $destination)
            ];
        }

        $other = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        foreach ([$other, new AgentCredentialDestination($destination->getId(), 2)] as $binding) {
            if ($request instanceof AgentRotationRequest) {
                $changed[] = new AgentRotationRequest(
                    $request->getAgentId(),
                    $request->getExpectedCredentialId(),
                    0,
                    $binding
                );
            } else {
                $changed[] = new AgentProvisioningRequest($request->getName(), $binding);
            }
        }

        $stored = $fixture->stored($issuance);
        $before = $fixture->counts();
        foreach ($changed as $conflict) {
            $fixture->allow($key->getScope(), $conflict->getDestination());
            $this->assertRequestDenied($fixture, $key, $conflict, AgentOperationFailure::CONFLICT);
        }

        self::assertEquals($stored, $fixture->stored($issuance));
        $this->assertNoIssuanceEffects($fixture, $before);
    }

    public function test_new_keys_share_one_normalized_name_contract_but_not_issuance_or_slot_order(): void
    {
        [$fixture, $key, $request, $original] = $this->scenario(false);
        $fixture->restart();
        $newKey = new AgentOperationKey($key->getScope(), AgentOperationId::generate());
        $next = $this->issue($fixture, $newKey, $request);
        foreach ([$original, $next] as $issuance) {
            self::assertSame(2, $fixture->stored($issuance)->getCanonicalVersion());
            self::assertSame(
                'Runner',
                $fixture->ports()->agents->getById($issuance->getAgentId())->getName()->toString()
            );
        }

        self::assertSame($original->getDestinationWriteVersion() + 1, $next->getDestinationWriteVersion());
        self::assertNotEquals($original->getCredentialId(), $next->getCredentialId());
        $before = $fixture->counts();
        $equivalent = new AgentProvisioningRequest('Runner', $request->getDestination());
        self::assertEquals($original, $this->issue($fixture, $key, $equivalent));
        self::assertEquals($next, $this->issue($fixture, $newKey, $equivalent));
        $this->assertNoIssuanceEffects($fixture, $before, true);
    }

    #[DataProvider('operations')]
    public function test_unsupported_markers_and_corrupt_bindings_never_fall_back_or_disclose(bool $rotation): void
    {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation);
        foreach ([1, 0, -1, 99] as $unknown) {
            $fixture->corruptBinding($issuance, $unknown);
            $fixture->restart();
            $before = $fixture->counts();
            $stored = $fixture->stored($issuance);
            $this->assertRequestDenied($fixture, $key, $request, AgentOperationFailure::UNSUPPORTED_VERSION);
            $this->assertReadDenied($fixture, $issuance, AgentOperationFailure::UNSUPPORTED_VERSION);
            self::assertEquals($stored, $fixture->stored($issuance));
            $this->assertNoIssuanceEffects($fixture, $before);
        }

        $fixture->corruptBinding($issuance, 2, 'malformed retained binding');
        $before = $fixture->counts();
        $stored = $fixture->stored($issuance);
        $this->assertRequestDenied($fixture, $key, $request, AgentOperationFailure::CONFLICT);
        self::assertEquals($stored, $fixture->stored($issuance));
        $this->assertNoIssuanceEffects($fixture, $before);
    }

    #[DataProvider('operations')]
    public function test_current_authority_and_unavailable_status_remain_distinct_from_new_key_admission(
        bool $rotation
    ): void {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation);
        $fixture->restart();
        $before = $fixture->counts();
        $fixture->storageUnavailable();
        $this->assertReadDenied($fixture, $issuance, AgentOperationFailure::UNAVAILABLE);
        $fixture->restart();
        $fixture->changeAuthority('caller', $issuance);
        $this->assertRequestDenied($fixture, $key, $request, AgentOperationFailure::UNAUTHORIZED);
        $this->assertReadDenied($fixture, $issuance, AgentOperationFailure::UNAUTHORIZED);
        $this->assertNoIssuanceEffects($fixture, $before);
    }

    #[DataProvider('operations')]
    public function test_wrong_scope_caller_and_expired_delegation_deny_lookup(bool $rotation): void
    {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation);
        $fixture->restart();
        $before = $fixture->counts();
        foreach (
            [
            new AgentOperationScope('another-consumer', 'user', $key->getScope()->getCallerId()),
            new AgentOperationScope($key->getScope()->getNamespace(), 'user', 'another-caller')
            ] as $scope
        ) {
            $wrong = new AgentOperationKey($scope, $key->getId());
            $this->assertRequestDenied($fixture, $wrong, $request, AgentOperationFailure::UNAUTHORIZED);
            $ports = $fixture->ports();
            $handler = new GetAgentOperationHandler($ports->operations, $ports->authorization);
            $this->assertDenial($fixture, fn(): AgentOperationView => $handler->handle(
                QueryMessage::create(new GetAgentOperation($wrong, $request->getDestination()))
            ), AgentOperationFailure::UNAUTHORIZED);
        }

        $fixture->expireOperationDelegation();
        $this->assertRequestDenied($fixture, $key, $request, AgentOperationFailure::UNAUTHORIZED);
        $this->assertReadDenied($fixture, $issuance, AgentOperationFailure::UNAUTHORIZED);
        $this->assertNoIssuanceEffects($fixture, $before);
    }

    abstract protected function newFixture(): DeliveryConformanceFixture&AgentCanonicalFixture;

    /** @return array{DeliveryConformanceFixture&AgentCanonicalFixture, AgentOperationKey, AgentProvisioningRequest|AgentRotationRequest, AgentIssuance} */
    private function scenario(bool $rotation): array
    {
        $fixture = $this->newFixture();
        $original = $fixture->original();
        $key = new AgentOperationKey($original->getKey()->getScope(), AgentOperationId::generate());
        $request = new AgentProvisioningRequest("\u{00A0}Runner\u{3000}", $original->getDestination());
        if ($rotation) {
            $request = new AgentRotationRequest(
                $original->getAgentId(),
                $original->getCredentialId(),
                0,
                $original->getDestination()
            );
        }

        $issuance = $this->issue($fixture, $key, $request);
        self::assertSame(2, $fixture->stored($issuance)->getCanonicalVersion());

        return [$fixture, $key, $request, $issuance];
    }

    private function issue(
        DeliveryConformanceFixture $fixture,
        AgentOperationKey $key,
        AgentProvisioningRequest|AgentRotationRequest $request
    ): AgentIssuance {
        $ports = $fixture->ports();
        $arguments = [$ports->agents, $ports->operations, $ports->audit, $ports->authorization, $ports->generator,
            $ports->cipher, $ports->deliveryCipher, $ports->clock, $ports->transaction, $ports->events];
        if ($request instanceof AgentProvisioningRequest) {
            $result = new AgentProvisioningService(...$arguments)->provision($key, $request);
        } else {
            $result = new AgentCredentialRotationService(...$arguments)->rotate($key, $request);
        }

        self::assertTrue($result->isConfirmed());
        self::assertNull($result->getWarning());
        $issuance = $result->getIssuance();
        self::assertNotNull($issuance);
        $this->assertSafe($fixture, [$result, $issuance->toArray()]);

        return $issuance;
    }

    /** @param array{decryptions: int, sink: int, transactions: int, generations: int, audit: int, events: int} $before */
    private function assertNoIssuanceEffects(
        DeliveryConformanceFixture $fixture,
        array $before,
        bool $noEvents = false
    ): void {
        $after = $fixture->counts();
        foreach (['generations', 'audit', 'decryptions', 'sink'] as $counter) {
            self::assertSame($before[$counter], $after[$counter], $counter);
        }

        if ($noEvents) {
            self::assertSame($before['events'], $after['events']);
        }
    }

    private function assertRequestDenied(
        DeliveryConformanceFixture $fixture,
        AgentOperationKey $key,
        AgentProvisioningRequest|AgentRotationRequest $request,
        AgentOperationFailure $reason
    ): void {
        $this->assertDenial($fixture, fn(): AgentIssuance => $this->issue($fixture, $key, $request), $reason);
    }

    private function assertReadDenied(
        DeliveryConformanceFixture $fixture,
        AgentIssuance $issuance,
        AgentOperationFailure $reason
    ): void {
        $this->assertDenial($fixture, fn(): AgentOperationView => $this->readOperation($fixture, $issuance), $reason);
    }

    private function assertDenial(
        DeliveryConformanceFixture $fixture,
        Closure $attempt,
        AgentOperationFailure $reason
    ): void {
        try {
            $attempt();
            self::fail('Invalid request must reject without fallback issuance or disclosure.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            $this->assertSafe($fixture, [
                $agentOperationRejectedException->getMessage(),
                $agentOperationRejectedException->getTraceAsString()
            ]);
        }
    }
}
