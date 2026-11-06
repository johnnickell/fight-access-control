<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Repository;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryClaimId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\DeliveryEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentCredentialOperation::class)]
final class AgentOperationReplacementTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function inconsistentSuccessors(): iterable
    {
        yield 'request' => ['request'];
        yield 'marker' => ['marker'];
        yield 'delivery material swap' => ['material'];
        yield 'maintenance claim reset' => ['claim'];
    }

    #[DataProvider('inconsistentSuccessors')]
    public function test_exact_writers_reject_rewritten_binding_or_lost_history(string $case): void
    {
        $env = new DeliveryEnvironment();
        $ports = $env->provisioning;
        $original = $env->operation();
        if ($case === 'claim') {
            $original = $original->claimDelivery(
                AgentDeliveryClaimId::generate(),
                new AgentDeliveryPolicy(),
                $env->clock->now()
            );
            $ports->operationRepository->operations[$ports->key->toString()] = $original;
        }

        $material = $original->getMaterial();
        if ($case === 'material') {
            $material = new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('other-copy'), 'test-key-v2');
        }

        $replacement = new AgentCredentialOperation(
            $case === 'marker' ? 99 : $original->getCanonicalVersion(),
            $case === 'request' ? 'different-request' : $original->getCanonicalRequest(),
            $original->getIssuance(),
            $material,
            stateRevision: $original->getStateRevision() + 1
        );
        try {
            $ports->transaction->commitTransactional(function () use ($ports, $original, $replacement, $case): void {
                $ports->operationAuthorization->authorize($ports->key->getScope(), $ports->request->getDestination());
                if ($case === 'claim') {
                    $ports->operationRepository->replaceMaintenance($original, $replacement);
                } else {
                    $ports->operationRepository->replaceDelivery(
                        $original,
                        $replacement,
                        $ports->agentRepository->all()[0]
                    );
                }
            });
            self::fail('An inconsistent successor was persisted.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CONFLICT, $agentOperationRejectedException->getReason());
            self::assertSame($original, $env->operation());
            self::assertSame(0, $ports->operationRepository->deliveryWrites);
            self::assertSame(0, $ports->operationRepository->maintenanceWrites);
        }
    }
}
