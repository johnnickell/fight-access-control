<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent\Command;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentProvisioningRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProvisionAgent::class)]
#[CoversClass(RotateAgentCredential::class)]
final class AgentIssuanceCommandTest extends TestCase
{
    public function test_original_intent_round_trips_without_normalizing_or_retargeting_the_request(): void
    {
        foreach ($this->commands() as $command) {
            $copy = $command::fromArray($command->toArray());
            self::assertSame($command->toArray(), $copy->toArray());
            self::assertSame($command->getKey()->toString(), $copy->getKey()->toString());
            self::assertSame($command->getRequest()->canonicalize(), $copy->getRequest()->canonicalize());
            self::assertSame(
                $command->getRequest()->getDestination()->toArray(),
                $copy->getRequest()->getDestination()->toArray()
            );
            self::assertSame($command->toArray(), unserialize(serialize($command))->toArray());
            if ($command instanceof ProvisionAgent) {
                self::assertSame("\0\u{00A0}Original Agent\u{3000} ", $copy->getRequest()->getName());
            }
        }
    }

    public function test_every_required_field_and_malformed_intent_is_rejected_without_diagnostic_chains(): void
    {
        foreach ($this->commands() as $command) {
            $valid = $command->toArray();
            foreach (array_keys($valid) as $field) {
                $missing = $valid;
                unset($missing[$field]);
                $this->reject($command::class, $missing);
                $this->reject($command::class, array_replace($valid, [$field => null]));
                $this->reject($command::class, array_replace($valid, [$field => []]));
            }

            if ($command instanceof ProvisionAgent) {
                foreach ([str_repeat('a', 4097), "\xFF", 1] as $name) {
                    $this->reject($command::class, array_replace($valid, ['name' => $name]));
                }
            } else {
                foreach ([-1, PHP_INT_MAX, '1', 1.0] as $revision) {
                    $this->reject(
                        $command::class,
                        array_replace($valid, ['expected_credential_revision' => $revision])
                    );
                }

                foreach (['agent_id', 'expected_credential_id'] as $field) {
                    $this->reject($command::class, array_replace($valid, [$field => 'not-an-identity']));
                }
            }
        }
    }

    /** @return list<ProvisionAgent|RotateAgentCredential> */
    private function commands(): array
    {
        $key = new AgentOperationKey(
            new AgentOperationScope('consumer', 'user', 'maintainer'),
            AgentOperationId::generate()
        );
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 3);

        return [
            new ProvisionAgent($key, new AgentProvisioningRequest("\0\u{00A0}Original Agent\u{3000} ", $destination)),
            new RotateAgentCredential(
                $key,
                new AgentRotationRequest(AgentId::generate(), AgentCredentialId::generate(), 7, $destination)
            )
        ];
    }

    /** @param class-string<ProvisionAgent|RotateAgentCredential> $class
     *  @param array<string, mixed> $data
     */
    private function reject(string $class, array $data): void
    {
        try {
            $class::fromArray($data);
            self::fail('Malformed intent must reject.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }
    }
}
