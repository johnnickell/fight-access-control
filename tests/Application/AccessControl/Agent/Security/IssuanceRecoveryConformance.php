<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentPublicationWarning;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\IssuanceRecoveryFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Consumer-bindable observable contract; every workflow below invokes the real package services */
abstract class IssuanceRecoveryConformance extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function kinds(): iterable
    {
        yield 'provision' => [false];
        yield 'rotation' => [true];
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function restartCases(): iterable
    {
        foreach (self::kinds() as $kind => [$rotation]) {
            yield $kind.' pending' => [$rotation, false];
            yield $kind.' abandoned claim' => [$rotation, true];
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function uncertainCases(): iterable
    {
        foreach (self::kinds() as $kind => [$rotation]) {
            yield $kind.' committed' => [$rotation, true];
            yield $kind.' rolled back' => [$rotation, false];
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function failures(): iterable
    {
        foreach (self::kinds() as $kind => [$rotation]) {
            foreach (['authorization', 'persistence', 'audit'] as $stage) {
                yield $kind.' '.$stage => [$rotation, $stage];
            }
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function changedRequests(): iterable
    {
        foreach (self::kinds() as $kind => [$rotation]) {
            $changes = ['kind', 'destination', 'binding', 'version'];
            $changes = [...$changes, ...($rotation ? ['target', 'credential', 'revision'] : ['name'])];
            foreach ($changes as $change) {
                yield $kind.' '.$change => [$rotation, $change];
            }
        }
    }

    #[DataProvider('restartCases')]
    public function test_both_publishers_and_caller_can_disappear_before_scheduler_only_recovery(
        bool $rotation,
        bool $abandonClaim
    ): void {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $before = $fixture->state();
        $fixture->failPublishers();
        $transactions = $fixture->transactions();
        $result = $this->issue($fixture, $key, $request);
        self::assertSame($transactions + 1, $fixture->transactions());
        self::assertTrue($result->isConfirmed());
        self::assertSame(AgentPublicationWarning::PUBLICATION_FAILED, $result->getWarning());
        self::assertCount(2, $fixture->publications());
        $issuance = $result->getIssuance();
        self::assertNotNull($issuance);
        self::assertSame($before['audit'] + 1, $fixture->state()['audit']);
        self::assertSame($before['agents'] + (int) !$rotation, $fixture->state()['agents']);
        self::assertSame($before['operations'] + 1, $fixture->state()['operations']);
        self::assertSame(
            AgentDeliveryDisposition::PENDING,
            $this->readOperation($fixture, $key, $request)->getDeliveryDisposition()
        );
        self::assertNull($fixture->stagedBytes($issuance));
        $this->assertSafe($fixture, [$result, $this->readOperation($fixture, $key, $request)->toArray()]);
        $committed = $fixture->state();
        $generations = $fixture->generations();
        $expectedBytes = $fixture->preparedBytes($issuance);
        // Retain scope/destination as scheduler configuration, not the caller result, request or key.
        $scope = $key->getScope();
        $destination = $request->getDestination();
        unset($result, $key, $request);
        if ($abandonClaim) {
            $fixture->loseCommit(true);
            self::assertSame(
                [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::INDETERMINATE],
                $this->recover($fixture, $scope, $destination)
            );
            self::assertSame(1, $fixture->stored($issuance->getKey())?->getStateRevision());
            self::assertSame(0, $fixture->sinkCalls());
        }

        $fixture->restart();
        $fixture->delegateWorker(true);
        $fixture->advance(61);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $scope, $destination)
        );
        self::assertSame([], $this->recover($fixture, $scope, $destination));
        $stored = $fixture->stored($issuance->getKey());
        self::assertNotNull($stored);
        self::assertEquals($issuance, $stored->getIssuance());
        self::assertNotNull($stored->getReceipt());
        self::assertNull($stored->getMaterial());
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $stored->getStatus()->getDeliveryDisposition());
        self::assertSame($committed, $fixture->state());
        self::assertSame($generations, $fixture->generations());
        self::assertSame(1, $fixture->sinkCalls());
        self::assertSame($expectedBytes, $fixture->stagedBytes($issuance));
        self::assertSame([], $fixture->publications());
        $this->assertSafe($fixture, [$stored->getStatus()->toArray()]);
    }

    #[DataProvider('uncertainCases')]
    public function test_indeterminate_commit_resolves_original_request_after_restart(
        bool $rotation,
        bool $persist
    ): void {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $before = $fixture->state();
        $fixture->loseCommit($persist);
        $result = $this->issue($fixture, $key, $request);
        self::assertFalse($result->isConfirmed());
        self::assertNull($result->getIssuance());
        self::assertNull($result->getWarning());
        $original = $fixture->stored($key)?->getIssuance();
        self::assertSame($persist, $original !== null);
        if (!$persist) {
            self::assertSame($before, $fixture->state());
        }

        $fixture->restart();
        self::assertSame($persist, $this->readOperation($fixture, $key, $request)->isConfirmed());
        $resolved = $this->issue($fixture, $key, $request);
        self::assertTrue($resolved->isConfirmed());
        if ($persist) {
            self::assertEquals($original, $resolved->getIssuance());
            self::assertSame([], $fixture->publications());
        }

        self::assertSame($before['audit'] + 1, $fixture->state()['audit']);
        self::assertSame($before['operations'] + 1, $fixture->state()['operations']);
        self::assertSame($before['agents'] + (int) !$rotation, $fixture->state()['agents']);
        $this->assertSafe($fixture, [$result, $resolved]);
    }

    #[DataProvider('failures')]
    public function test_precommit_failure_rolls_back_every_participant_and_reservation(
        bool $rotation,
        string $stage
    ): void {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $before = $fixture->state();
        $fixture->failBeforeCommit($stage);
        $fixture->failPublishers();
        $this->assertRejected(
            $fixture,
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertSame($before, $fixture->state());
        self::assertNull($fixture->stored($key));
        $fixture->restart();
        self::assertTrue($this->issue($fixture, $key, $request)->isConfirmed());
        self::assertSame($before['audit'] + 1, $fixture->state()['audit']);
    }

    #[DataProvider('changedRequests')]
    public function test_retained_binding_conflicts_and_unknown_versions_never_issue(
        bool $rotation,
        string $change
    ): void {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $original = $this->issue($fixture, $key, $request)->getIssuance();
        self::assertNotNull($original);
        $generations = $fixture->generations();
        $destination = $request->getDestination();
        if ($change === 'destination' || $change === 'binding') {
            $destination = new AgentCredentialDestination(
                $change === 'destination' ? AgentDestinationId::generate() : $destination->getId(),
                $change === 'binding' ? 2 : 1
            );
            $fixture->allow($key->getScope(), $destination);
        }

        $changed = new AgentProvisioningRequest($change === 'name' ? 'Another name' : 'Original Agent', $destination);
        if (($rotation && $change !== 'kind') || (!$rotation && $change === 'kind')) {
            $expected = $request instanceof AgentRotationRequest ? $request : $this->rotationRequest($original);
            $changed = new AgentRotationRequest(
                $change === 'target' ? AgentId::generate() : $original->getAgentId(),
                $change === 'credential' ? AgentCredentialId::generate() : $expected->getExpectedCredentialId(),
                $expected->getExpectedCredentialRevision() + (int) ($change === 'revision'),
                $destination
            );
        }

        if ($change === 'version') {
            $fixture->setCanonicalVersion($key, 999);
            $this->assertRejected(
                $fixture,
                fn (): AgentOperationView => $this->readOperation($fixture, $key, $request),
                AgentOperationFailure::UNSUPPORTED_VERSION
            );
        }

        $before = $fixture->state();
        $this->assertRejected(
            $fixture,
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $changed),
            $change === 'version' ? AgentOperationFailure::UNSUPPORTED_VERSION : AgentOperationFailure::CONFLICT
        );
        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
    }

    #[DataProvider('kinds')]
    public function test_same_key_retry_preserves_fact_identity_and_current_authority(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $original = $this->issue($fixture, $key, $request)->getIssuance();
        $facts = $fixture->publications();
        $before = $fixture->state();
        $generations = $fixture->generations();
        if ($request instanceof AgentProvisioningRequest) {
            $request = new AgentProvisioningRequest('  Original Agent  ', $request->getDestination());
        }

        self::assertEquals($original, $this->issue($fixture, $key, $request)->getIssuance());
        self::assertSame($facts, $fixture->publications());
        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
        $fixture->revokeAuthority($key->getScope());
        $this->assertRejected(
            $fixture,
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request)
        );
        $this->assertRejected($fixture, fn (): AgentOperationView => $this->readOperation($fixture, $key, $request));
        $this->assertRejected(
            $fixture,
            fn (): array => $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
    }

    #[DataProvider('kinds')]
    public function test_authority_writer_and_issuance_have_one_fenced_order(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $before = $fixture->state();
        $fixture->contendWithAuthorityRevocation($key->getScope());
        self::assertTrue($this->issue($fixture, $key, $request)->isConfirmed());
        self::assertSame($before['audit'] + 1, $fixture->state()['audit']);
        $this->assertRejected(
            $fixture,
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request)
        );
        $this->assertRejected($fixture, fn (): AgentOperationView => $this->readOperation($fixture, $key, $request));
    }

    #[DataProvider('kinds')]
    public function test_discovery_requires_delegation_and_cannot_cache_admission_authority(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $issuance = $this->issue($fixture, $key, $request)->getIssuance();
        self::assertNotNull($issuance);
        $fixture->restart();
        $fixture->delegateWorker(false);
        $this->assertRejected(
            $fixture,
            fn (): array => $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        $fixture->delegateWorker(true);
        $fixture->revokeWorkerAfterDiscovery();
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::REJECTED],
            $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        self::assertSame(0, $fixture->sinkCalls());
        self::assertSame(0, $fixture->stored($key)?->getStateRevision());
        $fixture->restart();
        $fixture->delegateWorker(true);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        self::assertEquals($key, $this->readOperation($fixture, $key, $request)->getKey());
    }

    #[DataProvider('kinds')]
    public function test_capacity_preserves_status_resolution_and_bounded_recovery(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $issuance = $this->issue($fixture, $key, $request, new AgentOperationLimits(256, 2, 2))->getIssuance();
        self::assertNotNull($issuance);
        $fillerDestination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $fixture->allow($key->getScope(), $fillerDestination);
        $this->issue(
            $fixture,
            new AgentOperationKey($key->getScope(), AgentOperationId::generate()),
            new AgentProvisioningRequest('Other pending work', $fillerDestination)
        );
        $limits = new AgentOperationLimits(128, 1, 1);
        $before = $fixture->state();
        $next = $rotation ? $this->rotationRequest($issuance) : $request;
        $this->assertRejected(
            $fixture,
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue(
                $fixture,
                new AgentOperationKey($key->getScope(), AgentOperationId::generate()),
                $next,
                $limits
            ),
            AgentOperationFailure::CAPACITY
        );
        self::assertSame($before, $fixture->state());
        self::assertEquals($issuance, $this->issue($fixture, $key, $request, $limits)->getIssuance());
        self::assertEquals($issuance, $this->readOperation($fixture, $key, $request)->getIssuance());
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $key->getScope(), $request->getDestination(), new AgentDeliverySchedule(1, 90))
        );
        self::assertSame($before, $fixture->state());
        self::assertSame(1, $fixture->sinkCalls());
        foreach ([[127, 1, 1], [512, 0, 1], [512, 2, 1], [4097, 1, 1]] as $invalid) {
            $this->assertRejected(
                $fixture,
                static fn (): AgentOperationLimits => new AgentOperationLimits(...$invalid),
                AgentOperationFailure::INVALID_REQUEST
            );
        }
    }

    #[DataProvider('kinds')]
    public function test_expiry_and_cleanup_never_make_original_key_fresh_issuance(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $issuance = $this->issue($fixture, $key, $request)->getIssuance();
        self::assertNotNull($issuance);
        $before = $fixture->state();
        $generations = $fixture->generations();
        $expectedBytes = $fixture->preparedBytes($issuance);
        $fixture->advance(86400);
        self::assertSame(AgentMaintenanceResult::EXPIRED, $fixture->maintenance()->expire(
            $key,
            $request->getDestination(),
            $issuance->getDeliveryId()
        ));
        $fixture->advance(86400);
        self::assertSame(AgentMaintenanceResult::CLEANED, $fixture->maintenance()->cleanup(
            $key,
            $request->getDestination(),
            $issuance->getDeliveryId()
        ));
        $fixture->restart();
        self::assertEquals($issuance, $this->issue($fixture, $key, $request)->getIssuance());
        $view = $this->readOperation($fixture, $key, $request);
        self::assertSame(AgentDeliveryDisposition::EXPIRED, $view->getDeliveryDisposition());
        self::assertEquals($issuance, $view->getIssuance());
        self::assertNull($fixture->stored($key)?->getMaterial());
        self::assertSame([], $this->recover($fixture, $key->getScope(), $request->getDestination()));
        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
        self::assertSame(0, $fixture->sinkCalls());
        self::assertSame([], $fixture->publications());
        try {
            $fixture->ports()->sink->stage(new AgentCredentialInvocation($issuance, $expectedBytes));
            self::fail('A delayed invocation cannot resurrect a cleaned delivery.');
        } catch (AgentDeliveryFailedException) {
            self::assertNull($fixture->stagedBytes($issuance));
            self::assertSame($before, $fixture->state());
        }
    }

    #[DataProvider('kinds')]
    public function test_actual_delivery_then_revocation_retains_original_status_without_authority(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $issuance = $this->issue($fixture, $key, $request)->getIssuance();
        self::assertNotNull($issuance);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        self::assertEquals($issuance->getAgentId(), $fixture->authenticateDelivered($issuance)->getAgentId());
        $ports = $fixture->ports();
        new AgentCredentialLifecycleService(
            $ports->agents,
            $ports->audit,
            $ports->clock,
            $ports->transaction,
            $ports->events
        )->revoke($key->getScope()->getCallerId(), $issuance->getAgentId());
        $before = $fixture->state();
        $generations = $fixture->generations();
        self::assertEquals($issuance, $this->issue($fixture, $key, $request)->getIssuance());
        $view = $this->readOperation($fixture, $key, $request);
        self::assertEquals($issuance, $view->getIssuance());
        self::assertSame(AgentCredentialDisposition::REVOKED, $view->getCredentialDisposition());
        try {
            $fixture->authenticateDelivered($issuance);
            self::fail('A receipt and retained bytes cannot authorize a fresh use after revocation.');
        } catch (CurrentAgentPrincipalResolutionRejectedException $currentAgentPrincipalResolutionRejectedException) {
            $this->assertSafe($fixture, [
                $currentAgentPrincipalResolutionRejectedException->getDiagnostic()->toArray()
            ]);
        }

        self::assertSame([], $this->recover($fixture, $key->getScope(), $request->getDestination()));
        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
        $this->assertSafe($fixture, [$view->toArray()]);
    }

    #[DataProvider('kinds')]
    public function test_contending_same_key_requests_resolve_one_issuance(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $before = $fixture->state();
        $results = $fixture->contend([
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request),
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request)
        ]);
        foreach ($results as $result) {
            self::assertTrue(
                $result instanceof AgentProvisioningResult || $result instanceof AgentCredentialRotationResult
            );
            self::assertTrue($result->isConfirmed());
            self::assertEquals($fixture->stored($key)?->getIssuance(), $result->getIssuance());
        }

        self::assertSame($before['audit'] + 1, $fixture->state()['audit']);
        self::assertSame($before['operations'] + 1, $fixture->state()['operations']);
        self::assertSame($before['agents'] + (int) !$rotation, $fixture->state()['agents']);
    }

    public function test_competing_rotation_keys_cannot_both_replace_one_predecessor(): void
    {
        [$fixture, $key, $request] = $this->scenario(true);
        $before = $fixture->state();
        $results = $fixture->contend([
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request),
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue(
                $fixture,
                new AgentOperationKey($key->getScope(), AgentOperationId::generate()),
                $request
            )
        ]);
        self::assertCount(1, array_filter($results, static fn ($result): bool =>
            $result instanceof AgentCredentialRotationResult && $result->isConfirmed()));
        self::assertCount(1, array_filter($results, static fn ($result): bool =>
            $result instanceof AgentOperationRejectedException
                && $result->getReason() === AgentOperationFailure::CONFLICT));
        self::assertSame($before['audit'] + 1, $fixture->state()['audit']);
        self::assertSame($before['operations'] + 1, $fixture->state()['operations']);
    }

    public function test_equal_ids_in_different_scopes_share_monotonic_slot_order_not_issuance(): void
    {
        [$fixture, $key, $request] = $this->scenario(false);
        $first = $this->issue($fixture, $key, $request)->getIssuance();
        self::assertNotNull($first);
        $other = new AgentOperationScope('consumer-b', 'agent', 'other-maintainer');
        $destination = new AgentCredentialDestination($request->getDestination()->getId(), 2);
        $fixture->allow($other, $destination);
        $secondKey = new AgentOperationKey($other, $key->getId());
        $secondRequest = new AgentProvisioningRequest('Other', $destination);
        $second = $this->issue($fixture, $secondKey, $secondRequest)->getIssuance();
        self::assertNotNull($second);
        self::assertFalse($first->getDeliveryId()->equals($second->getDeliveryId()));
        self::assertFalse($first->getAgentId()->equals($second->getAgentId()));
        self::assertGreaterThan($first->getDestinationWriteVersion(), $second->getDestinationWriteVersion());
        $this->assertRejected($fixture, fn (): AgentOperationView => $this->readOperation($fixture, $key, $request));
        self::assertSame(
            [$second->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $other, $destination)
        );
        self::assertNull($fixture->stagedBytes($first));
        self::assertNotNull($fixture->stagedBytes($second));
    }

    #[DataProvider('kinds')]
    public function test_lost_sink_response_recovers_original_bytes_and_id_not_issuance(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $issuance = $this->issue($fixture, $key, $request)->getIssuance();
        self::assertNotNull($issuance);
        $expectedBytes = $fixture->preparedBytes($issuance);
        $before = $fixture->state();
        $generations = $fixture->generations();
        $fixture->loseSinkResponse();
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::RETRYABLE],
            $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        self::assertSame($expectedBytes, $fixture->stagedBytes($issuance));
        $fixture->restart();
        $fixture->advance(61);
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        self::assertSame($expectedBytes, $fixture->stagedBytes($issuance));
        self::assertEquals($issuance, $fixture->stored($key)?->getIssuance());
        // Receipt-aware sinks may reconcile without repeating invocation; both paths retain the exact bytes/ID.
        self::assertContains($fixture->sinkCalls(), [1, 2]);
        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
        $this->assertSafe($fixture, [$this->readOperation($fixture, $key, $request)->toArray()]);
    }

    #[DataProvider('kinds')]
    public function test_terminal_delivery_retains_key_without_raw_or_envelope_fallback(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $issuance = $this->issue($fixture, $key, $request)->getIssuance();
        self::assertNotNull($issuance);
        $before = $fixture->state();
        $generations = $fixture->generations();
        $fixture->invalidateReceipt();
        self::assertSame(
            [$issuance->getDeliveryId()->toString() => AgentDeliveryResult::TERMINAL],
            $this->recover($fixture, $key->getScope(), $request->getDestination())
        );
        $fixture->restart();
        self::assertEquals($issuance, $this->issue($fixture, $key, $request)->getIssuance());
        self::assertSame(
            AgentDeliveryDisposition::TERMINAL,
            $this->readOperation($fixture, $key, $request)->getDeliveryDisposition()
        );
        self::assertNull($fixture->stored($key)?->getMaterial());
        self::assertSame([], $this->recover($fixture, $key->getScope(), $request->getDestination()));
        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
        self::assertSame(1, $fixture->sinkCalls());
    }

    #[DataProvider('kinds')]
    public function test_outer_transaction_cannot_wrap_an_issuance_service(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $before = $fixture->state();
        $this->assertRejected(
            $fixture,
            fn () => $fixture->ports()->transaction->commitTransactional(
                fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request)
            ),
            AgentOperationFailure::UNAVAILABLE
        );
        self::assertSame($before, $fixture->state());
    }

    #[DataProvider('kinds')]
    public function test_untrusted_scope_and_destination_cannot_read_or_resolve_an_existing_key(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $this->issue($fixture, $key, $request);
        $before = $fixture->state();
        $generations = $fixture->generations();
        foreach (
            [
            new AgentOperationScope('wrong-consumer', 'user', $key->getScope()->getCallerId()),
            new AgentOperationScope('conformance-consumer', 'user', 'wrong-caller'),
            new AgentOperationScope('conformance-consumer', 'agent', $key->getScope()->getCallerId())
            ] as $scope
        ) {
            $wrongKey = new AgentOperationKey($scope, $key->getId());
            $this->assertRejected(
                $fixture,
                fn (): AgentProvisioningResult|AgentCredentialRotationResult =>
                    $this->issue($fixture, $wrongKey, $request)
            );
            $this->assertRejected(
                $fixture,
                fn (): AgentOperationView => $this->readOperation($fixture, $wrongKey, $request)
            );
            $this->assertRejected(
                $fixture,
                fn (): array => $this->recover($fixture, $scope, $request->getDestination())
            );
        }

        foreach (
            [
            new AgentCredentialDestination(AgentDestinationId::generate(), 1),
            new AgentCredentialDestination($request->getDestination()->getId(), 2)
            ] as $destination
        ) {
            $wrongRequest = new AgentProvisioningRequest('Original Agent', $destination);
            $this->assertRejected(
                $fixture,
                fn (): AgentProvisioningResult|AgentCredentialRotationResult =>
                    $this->issue($fixture, $key, $wrongRequest)
            );
            $this->assertRejected(
                $fixture,
                fn (): AgentOperationView => $this->readOperation($fixture, $key, $wrongRequest)
            );
        }

        self::assertSame($before, $fixture->state());
        self::assertSame($generations, $fixture->generations());
    }

    #[DataProvider('kinds')]
    public function test_contending_different_bindings_leave_one_winner_and_one_conflict(bool $rotation): void
    {
        [$fixture, $key, $request] = $this->scenario($rotation);
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $fixture->allow($key->getScope(), $destination);
        $changed = new AgentProvisioningRequest('Different Agent', $destination);
        if ($request instanceof AgentRotationRequest) {
            $changed = new AgentRotationRequest(
                $request->getAgentId(),
                $request->getExpectedCredentialId(),
                $request->getExpectedCredentialRevision(),
                $destination
            );
        }

        $before = $fixture->state();
        $results = $fixture->contend([
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request),
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $changed)
        ]);
        $confirmed = array_filter($results, static fn ($result): bool =>
            ($result instanceof AgentProvisioningResult || $result instanceof AgentCredentialRotationResult)
                && $result->isConfirmed());
        self::assertCount(1, $confirmed);
        self::assertCount(1, array_filter($results, static fn ($result): bool =>
            $result instanceof AgentOperationRejectedException
                && $result->getReason() === AgentOperationFailure::CONFLICT));
        self::assertSame($before['audit'] + 1, $fixture->state()['audit']);
        self::assertSame($before['operations'] + 1, $fixture->state()['operations']);
        self::assertSame($before['agents'] + (int) !$rotation, $fixture->state()['agents']);
    }

    public function test_rotation_and_lifecycle_writer_never_leave_a_recoverable_revoked_credential(): void
    {
        [$fixture, $key, $request] = $this->scenario(true);
        self::assertInstanceOf(AgentRotationRequest::class, $request);
        $fixture->contend([
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue($fixture, $key, $request),
            static function () use ($fixture, $key, $request): void {
                $ports = $fixture->ports();
                new AgentCredentialLifecycleService(
                    $ports->agents,
                    $ports->audit,
                    $ports->clock,
                    $ports->transaction,
                    $ports->events
                )->revoke($key->getScope()->getCallerId(), $request->getAgentId());
            }
        ]);
        $operation = $fixture->stored($key);
        if ($operation !== null) {
            self::assertNull($operation->getMaterial());
            self::assertSame(AgentCredentialDisposition::REVOKED, $operation->getStatus()->getCredentialDisposition());
        }

        self::assertSame([], $this->recover($fixture, $key->getScope(), $request->getDestination()));
        $this->assertRejected(
            $fixture,
            fn (): AgentProvisioningResult|AgentCredentialRotationResult => $this->issue(
                $fixture,
                new AgentOperationKey($key->getScope(), AgentOperationId::generate()),
                $request
            ),
            AgentOperationFailure::CONFLICT
        );
    }

    abstract protected function newFixture(): IssuanceRecoveryFixture;

    /** @return array{IssuanceRecoveryFixture, AgentOperationKey, AgentProvisioningRequest|AgentRotationRequest} */
    private function scenario(bool $rotation): array
    {
        $fixture = $this->newFixture();
        $scope = new AgentOperationScope('conformance-consumer', 'user', 'conformance-maintainer');
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $fixture->allow($scope, $destination);
        $key = new AgentOperationKey($scope, AgentOperationId::generate());
        $request = new AgentProvisioningRequest('Original Agent', $destination);
        if ($rotation) {
            $original = $this->issue($fixture, $key, $request)->getIssuance();
            self::assertNotNull($original);
            $request = $this->rotationRequest($original);
            $key = new AgentOperationKey($scope, AgentOperationId::generate());
        }

        $fixture->restart();

        return [$fixture, $key, $request];
    }

    private function rotationRequest(AgentIssuance $issuance): AgentRotationRequest
    {
        return new AgentRotationRequest(
            $issuance->getAgentId(),
            $issuance->getCredentialId(),
            $issuance->getCredentialRevision(),
            $issuance->getDestination()
        );
    }

    private function issue(
        IssuanceRecoveryFixture $fixture,
        AgentOperationKey $key,
        AgentProvisioningRequest|AgentRotationRequest $request,
        ?AgentOperationLimits $limits = null
    ): AgentProvisioningResult|AgentCredentialRotationResult {
        $ports = $fixture->ports();
        $arguments = [
            $ports->agents, $ports->operations, $ports->audit, $ports->authorization, $ports->generator,
            $ports->cipher, $ports->deliveryCipher, $ports->clock, $ports->transaction, $ports->events
        ];
        if ($limits !== null) {
            $arguments[] = $limits;
        }

        if ($request instanceof AgentRotationRequest) {
            return new AgentCredentialRotationService(...$arguments)->rotate($key, $request);
        }

        return new AgentProvisioningService(...$arguments)->provision($key, $request);
    }

    private function readOperation(
        IssuanceRecoveryFixture $fixture,
        AgentOperationKey $key,
        AgentProvisioningRequest|AgentRotationRequest $request
    ): AgentOperationView {
        $ports = $fixture->ports();
        $before = $fixture->state();
        $events = $fixture->publications();
        $transactions = $fixture->transactions();
        $view = new GetAgentOperationHandler($ports->operations, $ports->authorization)->handle(
            QueryMessage::create(new GetAgentOperation($key, $request->getDestination()))
        );
        self::assertSame($before, $fixture->state());
        self::assertSame($events, $fixture->publications());
        self::assertSame($transactions, $fixture->transactions());

        return $view;
    }

    /** @return array<string, AgentDeliveryResult> */
    private function recover(
        IssuanceRecoveryFixture $fixture,
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentDeliverySchedule $schedule = null
    ): array {
        $ports = $fixture->ports();
        $delivery = new AgentCredentialDeliveryService(
            $ports->agents,
            $ports->operations,
            $ports->authorization,
            $ports->deliveryAuthorization,
            $ports->decipher,
            $ports->sink,
            $ports->clock,
            $ports->transaction,
            new AgentDeliveryPolicy()
        );
        $scheduler = new AgentDeliveryRecoveryService(
            new ListDueAgentDeliveriesHandler($ports->operations, $ports->deliveryAuthorization, $ports->clock),
            $delivery,
            $ports->clock,
            $schedule ?? new AgentDeliverySchedule()
        );
        self::assertEquals(
            $ports->clock->now()->modify('+'.($schedule === null ? 30 : 90).' seconds'),
            $scheduler->nextRunAt()
        );

        return $scheduler->recover($scope, $destination);
    }

    private function assertRejected(
        IssuanceRecoveryFixture $fixture,
        Closure $action,
        AgentOperationFailure $reason = AgentOperationFailure::UNAUTHORIZED
    ): void {
        try {
            $action();
            self::fail('Expected safe rejection.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            $this->assertSafe($fixture, [
                $agentOperationRejectedException->getMessage(),
                $agentOperationRejectedException->getTraceAsString()
            ]);
        }
    }

    /** @param list<mixed> $representations */
    private function assertSafe(IssuanceRecoveryFixture $fixture, array $representations): void
    {
        foreach ([...$representations, ...$fixture->safeEvidence()] as $value) {
            $surfaces = serialize($value).print_r($value, true).json_encode($value, JSON_THROW_ON_ERROR);
            foreach ($fixture->forbiddenValues() as $forbidden) {
                self::assertStringNotContainsString($forbidden, $surfaces);
            }
        }
    }
}
