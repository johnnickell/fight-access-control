<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentOperationScope::class)]
#[CoversClass(AgentOperationKey::class)]
#[CoversClass(AgentCredentialDestination::class)]
#[CoversClass(AgentProvisioningRequest::class)]
#[CoversClass(AgentOperationLimits::class)]
#[CoversClass(AgentIssuance::class)]
#[CoversClass(AgentDeliveryMaterial::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AgentOperationRejectedException::class)]
final class AgentOperationTest extends TestCase
{
    public function test_scoped_keys_are_unambiguous_and_destinations_are_registered_revisions(): void
    {
        $scope = new AgentOperationScope('consumer-a', 'user', 'maintainer-42');
        self::assertSame('consumer-a', $scope->getNamespace());
        self::assertSame('user', $scope->getCallerType());
        self::assertSame('maintainer-42', $scope->getCallerId());
        $id = AgentOperationId::generate();
        $key = new AgentOperationKey($scope, $id);
        self::assertSame($scope, $key->getScope());
        self::assertSame($id, $key->getId());
        self::assertNotSame(
            new AgentOperationScope('a:b', 'c', 'd')->toString(),
            new AgentOperationScope('a', 'b:c', 'd')->toString()
        );
        self::assertSame($scope->toString().':'.$id->toString(), $key->toString());
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 7);
        $request = new AgentProvisioningRequest(' Agent ', $destination);
        self::assertSame(' Agent ', $request->getName());
        self::assertSame($destination, $request->getDestination());
        self::assertSame(7, $destination->getRevision());
        self::assertSame(
            json_encode(['provision', 'Agent', $destination->getId()->toString(), 7], JSON_THROW_ON_ERROR),
            $request->canonicalize(1)
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidScopes(): iterable
    {
        yield 'empty namespace' => ['', 'user', 'caller'];
        yield 'namespace too long' => [str_repeat('a', 65), 'user', 'caller'];
        yield 'type too long' => ['namespace', str_repeat('a', 33), 'caller'];
        yield 'id too long' => ['namespace', 'user', str_repeat('a', 129)];
        yield 'path' => ['namespace', 'user', '/unsafe'];
        yield 'newline' => ["namespace\n", 'user', 'caller'];
        yield 'duplicate input cannot hide invalid field' => [str_repeat('a', 65), 'user', str_repeat('a', 65)];
    }

    #[DataProvider('invalidScopes')]
    public function test_invalid_scope_rejects_without_echoing_input(string $namespace, string $type, string $id): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentOperationScope($namespace, $type, $id);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidLimits(): iterable
    {
        yield 'name lower' => [127, 100, 10000];
        yield 'name upper' => [4097, 100, 10000];
        yield 'scope lower' => [512, 0, 10000];
        yield 'scope upper' => [512, 10001, 100000];
        yield 'total lower' => [512, 100, 99];
        yield 'total upper' => [512, 100, 1000001];
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_overrides_cannot_disable_bounds(int $name, int $scope, int $total): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentOperationLimits($name, $scope, $total);
    }

    public function test_defaults_and_valid_overrides_bound_new_work_and_capacity_is_retryable(): void
    {
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $defaults = new AgentOperationLimits();
        $defaults->validateNewRequest(new AgentProvisioningRequest(str_repeat(' ', 511).'A', $destination));
        $defaults->validateCapacity(99, 9999);
        new AgentOperationLimits(4096, 10000, 1000000)->validateCapacity(9999, 999999);
        new AgentOperationLimits(128, 1, 1)->validateCapacity(0, 0);
        try {
            $defaults->validateNewRequest(new AgentProvisioningRequest(str_repeat(' ', 512).'A', $destination));
            self::fail('Default new-request byte limit must be enforced.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
            self::assertFalse($agentOperationRejectedException->isRetryable());
        }

        foreach ([[100, 100], [0, 10000]] as [$scope, $total]) {
            try {
                $defaults->validateCapacity($scope, $total);
                self::fail('Either capacity limit must reject new work.');
            } catch (AgentOperationRejectedException $exception) {
                self::assertSame(AgentOperationFailure::CAPACITY, $exception->getReason());
                self::assertTrue($exception->isRetryable());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'absolute ingress size' => [str_repeat('a', 4097)];
        yield 'malformed utf8' => ["\xff"];
    }

    #[DataProvider('invalidNames')]
    public function test_request_rejects_unsafe_ingress_before_canonicalization(string $name): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentProvisioningRequest($name, new AgentCredentialDestination(AgentDestinationId::generate(), 1));
    }

    public function test_unknown_persisted_version_rejects_before_reinterpreting_input(): void
    {
        $request = new AgentProvisioningRequest(' ', new AgentCredentialDestination(AgentDestinationId::generate(), 1));
        try {
            $request->canonicalize(99);
            self::fail('Unknown versions cannot fall through to a current canonicalizer.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNSUPPORTED_VERSION, $agentOperationRejectedException->getReason());
        }
    }

    public function test_destination_revision_cannot_be_zero(): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentCredentialDestination(AgentDestinationId::generate(), 0);
    }

    public function test_original_issuance_and_correlation_survive_material_retirement_without_claiming_delivery(): void
    {
        $issuance = $this->issuance();
        $material = new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('private-ciphertext'), 'key-v1');
        $request = new AgentProvisioningRequest(' Agent ', $issuance->getDestination());
        $operation = new AgentCredentialOperation(1, $request->canonicalize(1), $issuance, $material);
        self::assertSame(1, $operation->getCanonicalVersion());
        self::assertSame($request->canonicalize(1), $operation->getCanonicalRequest());
        self::assertSame($issuance, $operation->getIssuance());
        self::assertSame($material, $operation->getMaterial());
        $retired = $operation->retireMaterial();
        self::assertSame($issuance, $retired->resolve(new AgentProvisioningRequest(
            'Agent',
            $issuance->getDestination()
        )));
        self::assertNull($retired->getMaterial());
        self::assertSame($material, $operation->getMaterial());
        self::assertSame($issuance->getKey()->getId()->toString(), $issuance->toArray()['operation_id']);
        self::assertSame($issuance->getDeliveryId()->toString(), $issuance->toArray()['delivery_id']);
        self::assertSame($issuance->getAgentId()->toString(), $issuance->toArray()['agent_id']);
        self::assertSame($issuance->getCredentialId()->toString(), $issuance->toArray()['credential_id']);
        self::assertSame(0, $issuance->getCredentialRevision());
        self::assertSame(1, $issuance->getDestinationWriteVersion());
        self::assertSame('2026-09-27T12:00:00.000000+00:00', $issuance->toArray()['issued_at']);
        self::assertEquals(new DateTimeImmutable('2026-09-27T12:00:00+00:00'), $issuance->getIssuedAt());
        $this->expectException(AgentOperationRejectedException::class);
        $retired->resolve(new AgentProvisioningRequest('Different', $issuance->getDestination()));
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidIssuance(): iterable
    {
        yield 'negative revision' => [-1, 1];
        yield 'zero order' => [0, 0];
    }

    #[DataProvider('invalidIssuance')]
    public function test_invalid_issuance_tuple_is_rejected(int $revision, int $order): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        $this->issuance($revision, $order);
    }

    public function test_prepared_material_is_redacted_and_never_serializable(): void
    {
        $material = new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('private-ciphertext'), 'key-v1');
        self::assertSame('private-ciphertext', $material->getCiphertext()->reveal());
        self::assertSame('key-v1', $material->getKeyVersion());
        ob_start();
        var_dump($material);
        $debug = ob_get_clean();
        self::assertStringNotContainsString('private-ciphertext', $debug);
        self::assertStringNotContainsString('key-v1', $debug);
        $this->expectException(LogicException::class);
        serialize($material);
    }

    public function test_key_version_cannot_be_a_provider_path(): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('private-ciphertext'), '/unsafe/key/path');
    }

    private function issuance(int $revision = 0, int $order = 1): AgentIssuance
    {
        return new AgentIssuance(
            new AgentOperationKey(
                new AgentOperationScope('consumer-a', 'user', 'maintainer-42'),
                AgentOperationId::generate()
            ),
            AgentDeliveryId::generate(),
            AgentId::generate(),
            AgentCredentialId::generate(),
            $revision,
            new AgentCredentialDestination(AgentDestinationId::generate(), 1),
            $order,
            new DateTimeImmutable('2026-09-27T12:00:00+00:00')
        );
    }
}
