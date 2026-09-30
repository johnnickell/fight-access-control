<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentOperationKey::class)]
#[CoversClass(AgentCredentialDestination::class)]
#[CoversClass(AgentIssuance::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(GetAgentOperation::class)]
#[CoversClass(AgentOperationView::class)]
final class AgentOperationViewTest extends TestCase
{
    public function test_query_and_results_round_trip_with_separate_original_and_current_state(): void
    {
        $issuance = $this->issuance();
        $query = new GetAgentOperation($issuance->getKey(), $issuance->getDestination());
        self::assertEquals($query, GetAgentOperation::fromArray($query->toArray()));
        self::assertSame($issuance->getKey(), $query->getKey());
        self::assertSame($issuance->getDestination(), $query->getDestination());
        self::assertEquals($issuance, AgentIssuance::fromArray($issuance->toArray()));
        foreach (AgentDeliveryDisposition::cases() as $delivery) {
            foreach (AgentCredentialDisposition::cases() as $credential) {
                $view = AgentOperationView::confirmed(2, $issuance, $delivery, $credential);
                self::assertTrue($view->isConfirmed());
                self::assertSame($issuance, $view->getIssuance());
                self::assertSame($issuance->getKey(), $view->getKey());
                self::assertSame(2, $view->getCanonicalVersion());
                self::assertSame($delivery, $view->getDeliveryDisposition());
                self::assertSame($credential, $view->getCredentialDisposition());
                self::assertSame([
                    'key'                    => $issuance->getKey()->toArray(),
                    'issuance_outcome'       => 'confirmed',
                    'canonical_version'      => 2,
                    'issuance'               => $issuance->toArray(),
                    'delivery_disposition'   => $delivery->value,
                    'credential_disposition' => $credential->value
                ], $view->toArray());
                self::assertEquals($view, AgentOperationView::fromArray($view->toArray()));
                $view->assertReadable($query->getKey(), $query->getDestination());
            }
        }

        $unknown = AgentOperationView::indeterminate($issuance->getKey());
        self::assertFalse($unknown->isConfirmed());
        self::assertNull($unknown->getIssuance());
        self::assertNull($unknown->getCanonicalVersion());
        self::assertNull($unknown->getDeliveryDisposition());
        self::assertNull($unknown->getCredentialDisposition());
        self::assertEquals($unknown, AgentOperationView::fromArray($unknown->toArray()));
        $unknown->assertReadable($query->getKey(), $query->getDestination());
    }

    public function test_serialized_contracts_reject_every_missing_field_and_null_required_value(): void
    {
        $issuance = $this->issuance();
        $view = AgentOperationView::confirmed(
            2,
            $issuance,
            AgentDeliveryDisposition::PENDING,
            AgentCredentialDisposition::CURRENT
        );
        $values = [
            $issuance->getKey(),
            $issuance->getDestination(),
            $issuance,
            new GetAgentOperation($issuance->getKey(), $issuance->getDestination()),
            $view
        ];
        foreach ($values as $value) {
            foreach (array_keys($value->toArray()) as $field) {
                foreach (['missing', 'null'] as $case) {
                    $data = $value->toArray();
                    if ($case === 'missing') {
                        unset($data[$field]);
                    } else {
                        $data[$field] = null;
                    }

                    try {
                        $value::fromArray($data);
                        self::fail($value::class.' accepted '.$case.' '.$field);
                    } catch (AgentOperationRejectedException $exception) {
                        self::assertSame(AgentOperationFailure::INVALID_REQUEST, $exception->getReason());
                        self::assertNull($exception->getPrevious());
                    }
                }
            }
        }
    }

    public function test_invalid_types_identifiers_bounds_dates_and_enum_values_reject_safely(): void
    {
        $issuance = $this->issuance();
        $data = $issuance->toArray();
        $cases = [
            ['namespace', str_repeat('n', 65)],
            ['caller_type', str_repeat('t', 33)],
            ['caller_id', str_repeat('i', 129)],
            ['namespace', 42],
            ['caller_id', '/unsafe/provider/path'],
            ['operation_id', 'not-a-uuid'],
            ['delivery_id', 'not-a-uuid'],
            ['agent_id', 'not-a-uuid'],
            ['credential_id', 'not-a-uuid'],
            ['destination_id', 'not-a-uuid'],
            ['destination_id', 42],
            ['destination_revision', '1'],
            ['destination_revision', 0],
            ['destination_revision', -1],
            ['credential_revision', -1],
            ['credential_revision', '0'],
            ['destination_write_version', 0],
            ['destination_write_version', 1.5],
            ['issued_at', 'tomorrow'],
            ['issued_at', '2026-02-30T12:00:00.000000+00:00'],
            ['issued_at', 42]
        ];
        foreach ($cases as [$field, $value]) {
            $invalid = $data;
            $invalid[$field] = $value;
            try {
                AgentIssuance::fromArray($invalid);
                self::fail('Invalid '.$field.' was accepted.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame(AgentOperationFailure::INVALID_REQUEST, $exception->getReason());
                self::assertSame('Agent operation rejected: invalid_request.', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }

        $view = AgentOperationView::confirmed(
            2,
            $issuance,
            AgentDeliveryDisposition::PENDING,
            AgentCredentialDisposition::CURRENT
        )->toArray();
        $invalidViews = [
            ['issuance_outcome', 'rollback'],
            ['canonical_version', '1'],
            ['issuance', 'unsafe'],
            ['delivery_disposition', 'provider-private-error'],
            ['credential_disposition', 'unknown'],
            ['key', $this->issuance()->getKey()->toArray()]
        ];
        foreach ($invalidViews as [$field, $value]) {
            try {
                AgentOperationView::fromArray(array_replace($view, [$field => $value]));
                self::fail('Invalid view field '.$field.' was accepted.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame(AgentOperationFailure::INVALID_REQUEST, $exception->getReason());
            }
        }

        foreach (['canonical_version', 'issuance', 'delivery_disposition', 'credential_disposition'] as $field) {
            $unknown = AgentOperationView::indeterminate($issuance->getKey())->toArray();
            $unknown[$field] = $view[$field];
            try {
                AgentOperationView::fromArray($unknown);
                self::fail('Indeterminate outcome cannot retain '.$field);
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame(AgentOperationFailure::INVALID_REQUEST, $exception->getReason());
            }
        }
    }

    public function test_unknown_version_or_mismatched_binding_is_not_reinterpreted(): void
    {
        $issuance = $this->issuance();
        $view = AgentOperationView::confirmed(
            99,
            $issuance,
            AgentDeliveryDisposition::RETIRED,
            AgentCredentialDisposition::SUPERSEDED
        );
        $cases = [
            [$issuance->getKey(), $issuance->getDestination(), AgentOperationFailure::UNSUPPORTED_VERSION],
            [$this->issuance()->getKey(), $issuance->getDestination(), AgentOperationFailure::UNAUTHORIZED],
            [$issuance->getKey(), $this->issuance()->getDestination(), AgentOperationFailure::UNAUTHORIZED]
        ];
        foreach ($cases as [$key, $destination, $reason]) {
            try {
                $view->assertReadable($key, $destination);
                self::fail('Unmatched or unsupported historical binding was exposed.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame($reason, $exception->getReason());
                self::assertSame(99, $view->getCanonicalVersion());
                self::assertSame($issuance, $view->getIssuance());
            }
        }
    }

    public function test_retirement_preserves_recorded_outcomes_and_never_infers_delivery_from_missing_material(): void
    {
        $issuance = $this->issuance();
        foreach (AgentDeliveryDisposition::cases() as $delivery) {
            $operation = new AgentCredentialOperation(
                2,
                'retained-original-request',
                $issuance,
                null,
                $delivery,
                AgentCredentialDisposition::REVOKED
            );
            $retired = $operation->retireMaterial();
            $expected = $delivery;
            if (in_array($delivery, [AgentDeliveryDisposition::PENDING, AgentDeliveryDisposition::RETRYABLE], true)) {
                $expected = AgentDeliveryDisposition::RETIRED;
            }

            self::assertSame($expected, $retired->getStatus()->getDeliveryDisposition());
            self::assertSame(AgentCredentialDisposition::REVOKED, $retired->getStatus()->getCredentialDisposition());
            self::assertSame($issuance, $retired->getStatus()->getIssuance());
            self::assertSame('retained-original-request', $retired->getCanonicalRequest());
            self::assertSame($delivery, $operation->getStatus()->getDeliveryDisposition());
            self::assertNull($retired->getMaterial());
        }
    }

    private function issuance(): AgentIssuance
    {
        return new AgentIssuance(
            new AgentOperationKey(
                new AgentOperationScope('consumer-a', 'user', 'maintainer-42'),
                AgentOperationId::generate()
            ),
            AgentDeliveryId::generate(),
            AgentId::generate(),
            AgentCredentialId::generate(),
            0,
            new AgentCredentialDestination(AgentDestinationId::generate(), 1),
            1,
            new DateTimeImmutable('2026-09-28T12:00:00+00:00')
        );
    }
}
