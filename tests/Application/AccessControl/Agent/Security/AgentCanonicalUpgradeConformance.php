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
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\AgentCanonicalUpgradeFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\DeliveryConformanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;

/** Consumer-bindable M3 proof through public services with separately observed persisted state */
abstract class AgentCanonicalUpgradeConformance extends DeliveryConformance
{
    /** @return iterable<string, array{bool, int, string}> */
    public static function retainedStates(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach ([1, 2] as $version) {
                foreach (['pending', 'delivered', 'revoked', 'superseded', 'expired', 'cleaned'] as $state) {
                    $label = ($rotation ? 'rotation' : 'provision').' v'.$version.' '.$state;
                    yield $label => [$rotation, $version, $state];
                }
            }
        }
    }

    /** @return iterable<string, array{bool, int}> */
    public static function versions(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach ([1, 2] as $version) {
                yield ($rotation ? 'rotation' : 'provision').' v'.$version => [$rotation, $version];
            }
        }
    }

    #[DataProvider('retainedStates')]
    public function test_restart_and_creation_switch_preserve_original_keys_in_every_retained_state(
        bool $rotation,
        int $version,
        string $state
    ): void {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation, $version);
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

        $fixture->useCanonicalCohort($version === 1 ? 2 : 1, [1, 2], 3);
        $fixture->restart();

        $before = $fixture->counts();
        $agent = $fixture->ports()->agents->getById($issuance->getAgentId());
        $order = $fixture->highWater($issuance->getDestination());
        $retry = $request;
        if ($request instanceof AgentProvisioningRequest) {
            // v1 keeps its Unicode edges; v2 must retain their equivalence even under a v1 creation cohort.
            $name = $version === 1 ? " \t\u{00A0}Runner\u{3000}\r\n" : ' Runner ';
            $retry = new AgentProvisioningRequest($name, $request->getDestination());
        }

        self::assertEquals($issuance, $this->issue($fixture, $key, $retry));
        self::assertEquals($issuance, $this->issue($fixture, $key, $request));
        self::assertSame($version, $this->readOperation($fixture, $issuance)->getCanonicalVersion());
        self::assertEquals($stored, $fixture->stored($issuance));
        self::assertEquals($agent, $fixture->ports()->agents->getById($issuance->getAgentId()));
        self::assertSame($order, $fixture->highWater($issuance->getDestination()));
        $this->assertNoIssuanceEffects($fixture, $before, true);
    }

    #[DataProvider('versions')]
    public function test_historical_conflicts_cannot_become_equal_under_new_normalization(
        bool $rotation,
        int $version
    ): void {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation, $version);
        $fixture->useCanonicalCohort($version === 1 ? 2 : 1, [1, 2], 3);
        $fixture->restart();

        $destination = $request->getDestination();
        $other = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $changed = [];
        if ($request instanceof AgentRotationRequest) {
            $changed = [
                new AgentRotationRequest(AgentId::generate(), $request->getExpectedCredentialId(), 0, $destination),
                new AgentRotationRequest($request->getAgentId(), AgentCredentialId::generate(), 0, $destination),
                new AgentRotationRequest($request->getAgentId(), $request->getExpectedCredentialId(), 5, $destination),
                new AgentProvisioningRequest('Runner', $destination)
            ];
        } else {
            foreach (['runner', 'Run ner', $version === 1 ? 'Runner' : 'Other'] as $name) {
                $changed[] = new AgentProvisioningRequest($name, $destination);
            }

            $changed[] = new AgentRotationRequest(
                $issuance->getAgentId(),
                $issuance->getCredentialId(),
                0,
                $destination
            );
        }

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

    public function test_only_unseen_keys_use_new_creation_rules_and_normalized_agent_name(): void
    {
        [$fixture, $oldKey, $request, $old] = $this->scenario(false, 1);
        $fixture->useCanonicalCohort(2, [1, 2], 3);
        $fixture->restart();

        $newKey = new AgentOperationKey($oldKey->getScope(), AgentOperationId::generate());
        $new = $this->issue($fixture, $newKey, $request);
        self::assertSame(2, $fixture->stored($new)->getCanonicalVersion());
        self::assertSame('Runner', $fixture->ports()->agents->getById($new->getAgentId())->getName()->toString());
        self::assertSame(
            "\u{00A0}Runner\u{3000}",
            $fixture->ports()->agents->getById($old->getAgentId())->getName()->toString()
        );
        self::assertSame($old->getDestinationWriteVersion() + 1, $new->getDestinationWriteVersion());
        self::assertNotEquals($old->getCredentialId(), $new->getCredentialId());
        self::assertSame(2, $this->readOperation($fixture, $new)->getCanonicalVersion());
        $before = $fixture->counts();
        self::assertEquals($new, $this->issue(
            $fixture,
            $newKey,
            new AgentProvisioningRequest('Runner', $request->getDestination())
        ));
        $this->assertDenial($fixture, fn(): AgentIssuance => $this->issue(
            $fixture,
            $oldKey,
            new AgentProvisioningRequest('Runner', $request->getDestination())
        ), AgentOperationFailure::CONFLICT);
        $this->assertNoIssuanceEffects($fixture, $before);
    }

    #[DataProvider('versions')]
    public function test_corrupt_or_missing_historical_versions_never_fall_back_or_disclose(
        bool $rotation,
        int $version
    ): void {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation, $version);
        $fixture->useCanonicalCohort(2, [1, 2], 3);
        foreach ([0, -1, 99] as $unknown) {
            $fixture->corruptBinding($issuance, $unknown);
            $fixture->restart();
            $before = $fixture->counts();
            $stored = $fixture->stored($issuance);
            $this->assertRequestDenied($fixture, $key, $request, AgentOperationFailure::UNSUPPORTED_VERSION);
            $this->assertReadDenied($fixture, $issuance, AgentOperationFailure::UNSUPPORTED_VERSION);
            self::assertEquals($stored, $fixture->stored($issuance));
            $this->assertNoIssuanceEffects($fixture, $before);
        }

        $fixture->corruptBinding($issuance, $version, 'malformed retained binding');
        $this->assertRequestDenied($fixture, $key, $request, AgentOperationFailure::CONFLICT);
    }

    #[DataProvider('versions')]
    public function test_reader_support_does_not_admit_an_incompatible_restarted_creator(
        bool $rotation,
        int $version
    ): void {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation, $version);
        foreach ([[99, [1, 2]], [2, [1]], [2, [2]], [1, [1, 99]]] as [$creator, $readers]) {
            $fixture->useCanonicalCohort($creator, $readers, 3);
            $fixture->restart();
            $before = $fixture->counts();
            self::assertSame($version, $this->readOperation($fixture, $issuance)->getCanonicalVersion());
            foreach ([$key, new AgentOperationKey($key->getScope(), AgentOperationId::generate())] as $attempt) {
                $this->assertRequestDenied($fixture, $attempt, $request, AgentOperationFailure::UNAVAILABLE);
            }

            $this->assertNoIssuanceEffects($fixture, $before);
        }
    }

    #[DataProvider('versions')]
    public function test_current_authority_and_unavailable_status_remain_distinct_from_new_key_admission(
        bool $rotation,
        int $version
    ): void {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation, $version);
        $fixture->useCanonicalCohort(2, [1, 2], 3);
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

    #[DataProvider('versions')]
    public function test_wrong_scope_caller_and_expired_delegation_deny_cross_upgrade_lookup(
        bool $rotation,
        int $version
    ): void {
        [$fixture, $key, $request, $issuance] = $this->scenario($rotation, $version);
        $fixture->useCanonicalCohort(2, [1, 2], 3);
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

    abstract protected function newFixture(): DeliveryConformanceFixture&AgentCanonicalUpgradeFixture;

    /** @return array{DeliveryConformanceFixture&AgentCanonicalUpgradeFixture, AgentOperationKey, AgentProvisioningRequest|AgentRotationRequest, AgentIssuance} */
    private function scenario(bool $rotation, int $version): array
    {
        $fixture = $this->newFixture();
        $original = $fixture->original();
        $fixture->useCanonicalCohort($version, $version === 1 ? [1] : [1, 2], 2);
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
        self::assertSame($version, $fixture->stored($issuance)->getCanonicalVersion());

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
            self::fail('Historical request must reject without fallback issuance or disclosure.');
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
