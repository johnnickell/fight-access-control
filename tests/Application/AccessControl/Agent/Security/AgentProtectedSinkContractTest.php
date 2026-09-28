<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the consumer sink obligation with a behavioral fixture, not a qualified production integration
 */
#[CoversClass(AgentCredentialInvocation::class)]
#[CoversClass(AgentDeliveryReceipt::class)]
#[CoversClass(AgentDeliveryFailedException::class)]
final class AgentProtectedSinkContractTest extends TestCase
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

    #[DataProvider('bindingFields')]
    public function test_changed_tuple_or_bytes_cannot_reuse_a_delivery_receipt(string $field): void
    {
        $env = new DeliveryEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        $receipt = $env->sink->receiptFor($env->issuance);
        self::assertNotNull($receipt);
        $data = $env->issuance->toArray();
        if ($field !== 'secret') {
            $original = $data[$field];
            $data[$field] = match ($field) {
                'credential_revision', 'destination_revision', 'destination_write_version' => $original + 1,
                'issued_at' => '2026-09-27T12:00:01.000000+00:00',
                'namespace', 'caller_type', 'caller_id' => 'different',
                default => AgentDeliveryId::generate()->toString()
            };
        }

        $changed = AgentIssuance::fromArray($data);
        if ($field !== 'secret') {
            self::assertFalse($env->sink->verify($receipt, $changed));
        }

        try {
            $env->sink->stage(new AgentCredentialInvocation(
                $changed,
                $field === 'secret' ? 'changed-secret' : 'original-test-secret'
            ));
            self::fail('A changed immutable binding must not write.');
        } catch (AgentDeliveryFailedException) {
            self::assertSame('original-test-secret', $env->sink->stagedBytes($env->issuance));
            self::assertSame($receipt, $env->sink->receiptFor($env->issuance));
            self::assertSame(1, $env->sink->highWater[$env->issuance->getDestination()->getId()->toString()]);
        }
    }

    public function test_missing_swapped_and_lost_receipts_never_verify_and_cleanup_retains_replay_defenses(): void
    {
        $env = new DeliveryEnvironment();
        self::assertFalse($env->sink->verify(new AgentDeliveryReceipt(str_repeat('a', 32)), $env->issuance));
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        $other = new DeliveryEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $other->deliver());
        $otherReceipt = $other->sink->receiptFor($other->issuance);
        self::assertNotNull($otherReceipt);
        self::assertFalse($env->sink->verify($otherReceipt, $env->issuance));
        $receipt = $env->sink->receiptFor($env->issuance);
        self::assertNotNull($receipt);
        $env->sink->forgetMaterial($env->issuance);
        self::assertFalse($env->sink->verify($receipt, $env->issuance));
        self::assertSame(1, $env->sink->highWater[$env->issuance->getDestination()->getId()->toString()]);
        $this->expectException(AgentDeliveryFailedException::class);
        $env->sink->stage(new AgentCredentialInvocation($env->issuance, 'original-test-secret'));
    }

    /** @return iterable<string, array{bool}> */
    public static function arrivalOrders(): iterable
    {
        yield 'old arrives before new' => [true];
        yield 'old arrives after new' => [false];
    }

    #[DataProvider('arrivalOrders')]
    public function test_late_predecessors_never_lower_slot_order_or_replace_a_new_binding(bool $oldFirst): void
    {
        $env = new DeliveryEnvironment();
        $old = new AgentCredentialInvocation($env->issuance, 'old-secret');
        $receipt = null;
        if ($oldFirst) {
            $receipt = $env->sink->stage($old);
        }

        $newData = $env->issuance->toArray();
        $newData['delivery_id'] = AgentDeliveryId::generate()->toString();
        $newData['agent_id'] = AgentDeliveryId::generate()->toString();
        $newData['credential_id'] = AgentDeliveryId::generate()->toString();
        $newData['namespace'] = 'another-scope';
        $newData['destination_revision'] = 2;
        $newData['destination_write_version'] = 2;
        $new = AgentIssuance::fromArray($newData);
        $newReceipt = $env->sink->stage(new AgentCredentialInvocation($new, 'new-secret'));
        if ($oldFirst) {
            self::assertSame($receipt, $env->sink->stage($old));
        } else {
            try {
                $env->sink->stage($old);
                self::fail('A late lower-order first arrival cannot overwrite the new binding.');
            } catch (AgentDeliveryFailedException) {
                self::assertNull($env->sink->receiptFor($env->issuance));
            }
        }

        self::assertSame(2, $env->sink->highWater[$new->getDestination()->getId()->toString()]);
        self::assertTrue($env->sink->verify($newReceipt, $new));
        self::assertSame('new-secret', $env->sink->stagedBytes($new));
        if ($receipt !== null) {
            self::assertFalse($env->sink->verify($receipt, $new));
        }
    }

    public function test_equal_slot_order_with_a_different_delivery_binding_rejects(): void
    {
        $env = new DeliveryEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        $changed = $env->issuance->toArray();
        $changed['delivery_id'] = AgentDeliveryId::generate()->toString();
        $changed['destination_revision'] = 2;
        $this->expectException(AgentDeliveryFailedException::class);
        $env->sink->stage(new AgentCredentialInvocation(AgentIssuance::fromArray($changed), 'different'));
    }

    public function test_equal_operation_ids_across_scopes_have_distinct_delivery_ids_and_shared_slot_order(): void
    {
        $env = new DeliveryEnvironment();
        $scope = new AgentOperationScope('different-consumer', 'user', 'maintainer-42');
        $key = new AgentOperationKey($scope, $env->issuance->getKey()->getId());
        $env->provisioning->authorization->scopes[$scope->toString()] = true;
        $second = $env->provisioning->service()->provision($key, $env->provisioning->request)->getIssuance();
        self::assertNotNull($second);
        self::assertTrue($second->getKey()->getId()->equals($env->issuance->getKey()->getId()));
        self::assertFalse($second->getDeliveryId()->equals($env->issuance->getDeliveryId()));
        self::assertSame(2, $second->getDestinationWriteVersion());
        self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
        self::assertSame(0, $env->decipher->calls);
    }

    public function test_new_slot_authority_cannot_be_confirmed_by_an_old_slot_receipt_or_late_bytes(): void
    {
        $env = new DeliveryEnvironment();
        $newData = $env->issuance->toArray();
        $newData['operation_id'] = AgentOperationId::generate()->toString();
        $newData['delivery_id'] = AgentDeliveryId::generate()->toString();
        $newData['credential_id'] = AgentDeliveryId::generate()->toString();
        $newData['credential_revision'] = 1;
        $newData['destination_id'] = AgentDeliveryId::generate()->toString();
        $new = AgentIssuance::fromArray($newData);
        // Models exact consumer selection after a cross-slot authority change, not a package rotation workflow.
        $oldReceipt = $env->sink->stage(new AgentCredentialInvocation($env->issuance, 'late-old-secret'));
        self::assertFalse($env->sink->verify($oldReceipt, $new));
        self::assertNull($env->sink->stagedBytes($new));
        $newReceipt = $env->sink->stage(new AgentCredentialInvocation($new, 'new-secret'));
        self::assertTrue($env->sink->verify($newReceipt, $new));
        self::assertFalse($env->sink->verify($oldReceipt, $new));
        self::assertSame(
            $oldReceipt,
            $env->sink->stage(new AgentCredentialInvocation($env->issuance, 'late-old-secret'))
        );
        self::assertSame('new-secret', $env->sink->stagedBytes($new));
    }
}
