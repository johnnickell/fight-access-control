<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use PHPUnit\Framework\Attributes\DataProvider;

/** Binds the actual consumer sink to immutable receipt, idempotency, cleanup and cross-operation order contracts */
abstract class ProtectedSinkConformance extends DeliveryConformance
{
    /** @return iterable<string, array{string}> */
    public static function bindingFields(): iterable
    {
        foreach (
            [
            'namespace', 'caller_type', 'caller_id', 'operation_id', 'delivery_id', 'agent_id',
            'credential_id', 'credential_revision', 'destination_id', 'destination_revision',
            'destination_write_version', 'issued_at', 'secret'
            ] as $field
        ) {
            yield $field => [$field];
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function rotationOrders(): iterable
    {
        foreach ([false, true] as $crossSlot) {
            foreach ([false, true] as $successorFirst) {
                $slot = $crossSlot ? 'cross-slot ' : 'same-slot ';
                yield $slot.($successorFirst ? 'successor first' : 'old first') => [$crossSlot, $successorFirst];
            }
        }
    }

    #[DataProvider('bindingFields')]
    public function test_every_immutable_receipt_or_idempotency_field_and_bytes_reject_mismatch(string $field): void
    {
        $fixture = $this->newFixture();
        $issuance = $fixture->original();
        $bytes = $fixture->preparedBytes($issuance);
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $issuance));
        $receipt = $fixture->receipt($issuance);
        self::assertNotNull($receipt);
        $data = $issuance->toArray();
        if ($field !== 'secret') {
            $data[$field] = match ($field) {
                'credential_revision', 'destination_revision', 'destination_write_version' => $data[$field] + 1,
                'issued_at' => $issuance->getIssuedAt()->modify('+1 second')->format('Y-m-d\TH:i:s.uP'),
                'namespace', 'caller_type', 'caller_id' => 'different',
                default => AgentDeliveryId::generate()->toString()
            };
        }

        $changed = AgentIssuance::fromArray($data);
        $sink = $fixture->ports()->sink;
        if ($field !== 'secret') {
            self::assertFalse($sink->verify($receipt, $changed));
        }

        try {
            $sink->stage(new AgentCredentialInvocation($changed, $field === 'secret' ? $bytes.'-changed' : $bytes));
            self::fail('Changed immutable binding must reject before writing.');
        } catch (AgentDeliveryFailedException $agentDeliveryFailedException) {
            $this->assertSafe($fixture, [
                $agentDeliveryFailedException->getMessage(),
                $agentDeliveryFailedException->getTraceAsString()
            ]);
        }

        self::assertSame($bytes, $fixture->stagedBytes($issuance));
        self::assertEquals($receipt, $fixture->receipt($issuance));
        self::assertSame($issuance->getDestinationWriteVersion(), $fixture->highWater($issuance->getDestination()));
        self::assertEquals($receipt, $sink->stage(new AgentCredentialInvocation($issuance, $bytes)));
        self::assertSame($issuance->getDestinationWriteVersion(), $fixture->highWater($issuance->getDestination()));
        self::assertTrue($sink->verify($receipt, $issuance));
    }

    #[DataProvider('rotationOrders')]
    public function test_actual_rotation_orders_late_predecessors_across_slots_without_selecting_old_authority(
        bool $crossSlot,
        bool $successorFirst
    ): void {
        $fixture = $this->newFixture();
        $old = $fixture->original();
        $oldBytes = $fixture->preparedBytes($old);
        $destination = $old->getDestination();
        if ($crossSlot) {
            $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
            $fixture->allow($old->getKey()->getScope(), $destination);
        }

        $successor = null;
        $fixture->pause('invoking', function () use ($fixture, $old, $destination, $successorFirst, &$successor): void {
            $successor = $this->rotate($fixture, $old, $destination);
            self::assertNull($fixture->ports()->agents->getByCredentialId($old->getCredentialId()));
            // The sink has not learned the new package revision yet; only current package authority can select it.
            self::assertNull($fixture->stagedBytes($successor));
            if ($successorFirst) {
                self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $successor));
            }
        });
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $old));
        self::assertNotNull($successor);
        self::assertSame(
            AgentCredentialDisposition::SUPERSEDED,
            $this->readOperation($fixture, $old)->getCredentialDisposition()
        );
        self::assertNull($fixture->stored($old)->getReceipt());
        if (!$successorFirst) {
            self::assertSame($oldBytes, $fixture->stagedBytes($old));
            self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $successor));
        }

        $newReceipt = $fixture->receipt($successor);
        self::assertNotNull($newReceipt);
        $sink = $fixture->ports()->sink;
        self::assertTrue($sink->verify($newReceipt, $successor));
        self::assertFalse($sink->verify($newReceipt, $old));
        self::assertNotSame($oldBytes, $fixture->stagedBytes($successor));
        $oldReceipt = $fixture->receipt($old);
        if ($oldReceipt !== null) {
            self::assertFalse($sink->verify($oldReceipt, $successor));
            self::assertEquals($oldReceipt, $sink->stage(new AgentCredentialInvocation($old, $oldBytes)));
        }

        self::assertSame($successor->getDestinationWriteVersion(), $fixture->highWater($destination));
        self::assertSame(
            $successor->getCredentialId()->toString(),
            $fixture->ports()->agents->getById($old->getAgentId())?->getCredentialId()->toString()
        );
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $old));
        $this->readOperation($fixture, $successor);
    }

    public function test_equal_operation_ids_and_reassigned_slots_keep_global_order_and_exact_receipts(): void
    {
        $fixture = $this->newFixture();
        $old = $fixture->original();
        $oldBytes = $fixture->preparedBytes($old);
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $old));
        $oldReceipt = $fixture->receipt($old);
        self::assertNotNull($oldReceipt);
        $scope = new AgentOperationScope('another-consumer', 'agent', 'another-originator');
        $destination = new AgentCredentialDestination($old->getDestination()->getId(), 2);
        $fixture->allow($scope, $destination);
        $new = $this->provision($fixture, new AgentOperationKey($scope, $old->getKey()->getId()), $destination);
        self::assertFalse($old->getDeliveryId()->equals($new->getDeliveryId()));
        self::assertFalse($old->getAgentId()->equals($new->getAgentId()));
        self::assertGreaterThan($old->getDestinationWriteVersion(), $new->getDestinationWriteVersion());
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $old));
        self::assertSame(
            [$new->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $new)
        );
        $receipt = $fixture->receipt($new);
        self::assertNotNull($receipt);
        $sink = $fixture->ports()->sink;
        self::assertFalse($sink->verify($oldReceipt, $new));
        self::assertFalse($sink->verify($receipt, $old));
        self::assertFalse($sink->verify(new AgentDeliveryReceipt(str_repeat('a', 32)), $new));
        self::assertEquals($oldReceipt, $sink->stage(new AgentCredentialInvocation($old, $oldBytes)));
        self::assertSame($new->getDestinationWriteVersion(), $fixture->highWater($destination));
        self::assertTrue($sink->verify($receipt, $new));
        self::assertSame(1, $fixture->stored($old)->requireAttempt()->getFence());
        self::assertSame(1, $fixture->stored($new)->requireAttempt()->getFence());
        $this->readOperation($fixture, $new);
    }
}
