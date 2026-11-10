<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\AgentCredentialOperation;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRevoked;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioned;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioningFailed;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryAttempt;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentProvisioned::class)]
#[CoversClass(AgentCredentialRotated::class)]
#[CoversClass(AgentCredentialRevoked::class)]
#[CoversClass(AgentProvisioningFailed::class)]
#[CoversClass(AgentCredentialLifecycleFailed::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentOperationLimits::class)]
#[CoversClass(AgentOperationContract::class)]
final class AgentOperationTypeContractTest extends TestCase
{
    /** @return iterable<string, array{Event}> */
    public static function events(): iterable
    {
        $agent = AgentId::generate();
        $credential = AgentCredentialId::generate();
        $now = new DateTimeImmutable('2026-11-06T12:34:56+00:00');
        yield 'provisioned' => [new AgentProvisioned($agent, $credential, 0, $now)];
        yield 'rotated' => [new AgentCredentialRotated($agent, $credential, 1, $now)];
        yield 'revoked' => [new AgentCredentialRevoked($agent, $now)];
        yield 'provision failure' => [new AgentProvisioningFailed('worker', 'Agent operation failed.')];
        yield 'lifecycle failure' => [new AgentCredentialLifecycleFailed($agent, 'Agent operation failed.')];
    }

    #[DataProvider('events')]
    public function test_events_reject_coercion_for_every_field(Event $event): void
    {
        self::assertEquals($event, $event::fromArray($event->toArray()));
        foreach ($event->toArray() as $field => $valid) {
            $invalidValues = [null, false, [], 1.5];
            if (is_int($valid)) {
                $invalidValues[] = (string) $valid;
            } else {
                $invalidValues[] = 42;
            }

            foreach ($invalidValues as $invalid) {
                try {
                    $event::fromArray(array_replace($event->toArray(), [$field => $invalid]));
                    self::fail($event::class.' coerced '.$field);
                } catch (DomainException $failure) {
                    self::assertNull($failure->getPrevious());
                }
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInstants(): iterable
    {
        yield 'relative' => ['tomorrow'];
        yield 'empty' => [''];
        yield 'invalid calendar' => ['2026-02-30T12:00:00+00:00'];
        yield 'missing offset' => ['2026-11-06T12:00:00'];
    }

    #[DataProvider('invalidInstants')]
    public function test_event_instants_are_explicit_and_valid(string $instant): void
    {
        foreach (self::events() as [$event]) {
            foreach (array_keys($event->toArray()) as $field) {
                if (!str_ends_with($field, '_at')) {
                    continue;
                }

                try {
                    $event::fromArray(array_replace($event->toArray(), [$field => $instant]));
                    self::fail('Invalid event instant accepted.');
                } catch (DomainException $failure) {
                    self::assertNull($failure->getPrevious());
                }
            }
        }
    }

    public function test_negative_event_revisions_are_not_facts(): void
    {
        foreach ([AgentProvisioned::class, AgentCredentialRotated::class] as $class) {
            try {
                new $class(AgentId::generate(), AgentCredentialId::generate(), -1, new DateTimeImmutable());
                self::fail('Negative credential revision accepted.');
            } catch (DomainException $failure) {
                self::assertNull($failure->getPrevious());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function mismatchedIssuance(): iterable
    {
        foreach (
            ['provision destination', 'provision revision', 'rotation destination', 'rotation agent',
            'rotation revision', 'rotation predecessor'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('mismatchedIssuance')]
    public function test_matching_request_bytes_cannot_resolve_an_unrelated_issuance(string $case): void
    {
        $issuance = $this->issuance();
        $destination = $issuance->getDestination();
        if (str_ends_with($case, 'destination')) {
            $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        }

        $request = new AgentProvisioningRequest('Agent', $destination);
        if (str_starts_with($case, 'rotation')) {
            $request = new AgentRotationRequest(
                $case === 'rotation agent' ? AgentId::generate() : $issuance->getAgentId(),
                $case === 'rotation predecessor' ? $issuance->getCredentialId() : AgentCredentialId::generate(),
                $case === 'rotation revision' ? 1 : 0,
                $destination
            );
        }

        $data = $issuance->toArray();
        $data['credential_revision'] = $case === 'provision revision' || str_starts_with($case, 'rotation') ? 1 : 0;
        $operation = new AgentCredentialOperation(
            2,
            $request->canonicalize(),
            AgentIssuance::fromArray($data),
            null,
            AgentDeliveryDisposition::RETIRED
        );
        $this->expectException(AgentOperationRejectedException::class);
        $operation->resolve($request);
    }

    public function test_exhausted_state_revision_rejects_instead_of_overflowing_to_float(): void
    {
        $issuance = $this->issuance();
        $operation = new AgentCredentialOperation(
            2,
            new AgentProvisioningRequest('Agent', $issuance->getDestination())->canonicalize(),
            $issuance,
            new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('test-copy'), 'test-v1'),
            stateRevision: PHP_INT_MAX
        );
        $this->expectException(AgentOperationRejectedException::class);
        $operation->retireMaterial();
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidCounts(): iterable
    {
        yield 'negative scope' => [-1, 0];
        yield 'negative global' => [0, -1];
        yield 'scope exceeds global' => [2, 1];
    }

    #[DataProvider('invalidCounts')]
    public function test_inconsistent_capacity_evidence_cannot_admit_new_work(int $scope, int $total): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentOperationLimits()->validateCapacity($scope, $total);
    }

    public function test_nonpositive_attempt_numbers_are_not_permitted(): void
    {
        self::assertFalse(new AgentDeliveryPolicy()->permitsAttempt(0));
        self::assertFalse(new AgentDeliveryPolicy()->permitsAttempt(-1));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function malformedCapabilities(): iterable
    {
        $valid = AgentOperationContract::REQUIRED_CAPABILITIES;
        yield 'non-list' => [array_combine($valid, $valid)];
        yield 'non-string' => [[...$valid, 7]];
        yield 'nested array' => [[...$valid, []]];
    }

    /** @param array<array-key, mixed> $capabilities */
    #[DataProvider('malformedCapabilities')]
    public function test_malformed_cohort_evidence_rejects_safely(array $capabilities): void
    {
        try {
            new AgentOperationContract(1, 2, 1, 1, $capabilities, 1)->assertCompatible();
            self::fail('Malformed capability evidence accepted.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }
    }

    public function test_exhausted_claim_fence_never_wraps_or_overflows(): void
    {
        $issuance = $this->issuance();
        $operation = new AgentCredentialOperation(
            2,
            'retained-request',
            $issuance,
            new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('test-copy'), 'test-v1'),
            stateRevision: PHP_INT_MAX,
            attempt: new AgentDeliveryAttempt(PHP_INT_MAX, AgentDeliveryClaimId::generate(), $issuance->getIssuedAt()),
            deliveryPolicy: new AgentDeliveryPolicy()
        );
        $this->expectException(AgentOperationRejectedException::class);
        $operation->claimDelivery(
            AgentDeliveryClaimId::generate(),
            new AgentDeliveryPolicy(),
            $issuance->getIssuedAt()
        );
    }

    private function issuance(): AgentIssuance
    {
        return new AgentIssuance(
            new AgentOperationKey(new AgentOperationScope('test', 'user', 'owner'), AgentOperationId::generate()),
            AgentDeliveryId::generate(),
            AgentId::generate(),
            AgentCredentialId::generate(),
            0,
            new AgentCredentialDestination(AgentDestinationId::generate(), 1),
            1,
            new DateTimeImmutable('2026-11-06T12:00:00Z')
        );
    }
}
