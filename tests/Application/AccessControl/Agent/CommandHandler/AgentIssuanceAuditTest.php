<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationUncertainException;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\FixedHmacSharedSecretCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\RotationEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\UncertainAgentUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ProvisionAgentHandler::class)]
#[CoversClass(RotateAgentCredentialHandler::class)]
#[CoversClass(AgentCredentialOperation::class)]
#[CoversClass(AuditAgentOutcome::class)]
final class AgentIssuanceAuditTest extends TestCase
{
    /** @return iterable<string, array{bool, ?string, bool}> */
    public static function compositions(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach (['user', 'agent', 'system', 'anonymous', null] as $kind) {
                foreach ([false, true] as $enabled) {
                    yield (int) $rotation.' '.($kind ?? 'absent').' '.(int) $enabled => [$rotation, $kind, $enabled];
                }
            }
        }
    }

    #[DataProvider('compositions')]
    public function test_optional_audit_retains_explicit_origin_without_changing_public_issuance(
        bool $rotation,
        ?string $kind,
        bool $enabled
    ): void {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate?->provisioning ?? new ProvisioningEnvironment();
        $origin = match ($kind) {
            'user' => UserId::generate(),
            'agent' => AgentId::generate(),
            'system' => SystemId::generate(),
            'anonymous' => AnonymousId::generate(),
            null => null
        };
        $env->operationAuthorization->originatingActorId = $origin;
        $command = $this->command($env, $rotate);
        $bus = $rotate?->bus(auditEnabled: $enabled) ?? $env->bus(auditEnabled: $enabled);
        $before = [$env->agentRepository->all(), $env->operationRepository->operations,
            $env->operationRepository->versions, $env->auditEvidenceRepository->all(), $env->generations];
        if ($enabled && $origin === null) {
            try {
                $bus->commandBus->execute($command);
                self::fail('Enabled new work needs explicit origin before reservation or mutation.');
            } catch (Throwable $failure) {
                self::assertSame('Agent issuance failed.', $failure->getMessage());
            }

            self::assertSame($before, [$env->agentRepository->all(), $env->operationRepository->operations,
                $env->operationRepository->versions, $env->auditEvidenceRepository->all(), $env->generations]);

            return;
        }

        $message = CommandMessage::create($command);
        $bus->commandBus->dispatch($message);
        $stored = $env->operationRepository->operations[$command->getKey()->toString()];
        self::assertSame($origin, $stored->getOriginatingActorId());
        self::assertCount(count($before[3]) + (int) $enabled, $env->auditEvidenceRepository->all());
        $view = $bus->dispatchAndRead($command);
        self::assertTrue($view->isConfirmed());
        self::assertArrayNotHasKey('originating_actor_id', $view->toArray());
        self::assertArrayNotHasKey('originating_actor_id', $stored->getIssuance()->toArray());
        if ($enabled) {
            $evidence = array_last($env->auditEvidenceRepository->all());
            self::assertSame($origin, $evidence->actorId());
            self::assertSame($message->id(), $evidence->getCorrelation()->getMessageId());
            self::assertSame($stored->getStateRevision(), $evidence->getFacts()->getOperationRevision());
            self::assertSame($stored->getIssuance()->getIssuedAt(), $evidence->getOccurredAt());
        }

        // Model persisted scalar kind/ID and issuance hydration, not an in-memory actor object surviving restart.
        $row = json_decode(json_encode([
            'kind'     => $origin === null ? null : AuditActorKind::forId($origin)->value,
            'id'       => $origin?->toString(),
            'issuance' => $stored->getIssuance()->toArray()
        ], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $restoredOrigin = $row['kind'] === null ? null : AuditActorKind::from($row['kind'])->idFromString($row['id']);
        $restored = new AgentCredentialOperation(
            $stored->getCanonicalVersion(),
            $stored->getCanonicalRequest(),
            AgentIssuance::fromArray($row['issuance']),
            $stored->getMaterial(),
            $restoredOrigin
        );
        $env->operationRepository->operations[$command->getKey()->toString()] = $restored;
        $env->operationAuthorization->actor = 'delegated-worker';
        $delegation = 'delegated-worker:'.$command->getKey()->getScope()->toString();
        $env->operationAuthorization->readDelegations[$delegation] = true;
        $env->operationAuthorization->originatingActorId = AgentId::generate();
        $audits = $env->auditEvidenceRepository->all();
        $generations = $env->generations;
        // Later opt-in and a different worker must neither backfill nor replace retained origin.
        $retry = $rotate?->bus() ?? $env->bus();
        $retry->commandBus->execute($command);
        self::assertEquals($origin, $restored->getOriginatingActorId());
        self::assertSame($audits, $env->auditEvidenceRepository->all());
        self::assertSame($generations, $env->generations);
        self::assertEquals($stored->getIssuance(), $retry->dispatchAndRead($command)->getIssuance());
    }

    public function test_omitted_last_repository_disables_both_handlers_without_audit_only_origin(): void
    {
        $env = new ProvisioningEnvironment();
        $env->operationAuthorization->originatingActorId = null;

        $generator = $this->createStub(HmacSharedSecretGenerator::class);
        $generator->method('generate')->willReturn('test-secret');
        $arguments = [
            $env->agentRepository,
            $env->operationRepository,
            $env->operationAuthorization,
            $generator,
            new FixedHmacSharedSecretCipher('test-envelope:'),
            new BoundAgentDeliveryCipher(),
            new FixedClock(new DateTimeImmutable('2026-11-14T12:00:00Z')),
            $env->transaction,
            $env->eventDispatcher
        ];
        new ProvisionAgentHandler(...$arguments)->handle(CommandMessage::create(new ProvisionAgent(
            $env->key,
            $env->request
        )));
        $original = $env->operationRepository->operations[$env->key->toString()]->getIssuance();
        $key = new AgentOperationKey(
            $env->key->getScope(),
            AgentOperationId::generate()
        );
        new RotateAgentCredentialHandler(...$arguments)->handle(CommandMessage::create(new RotateAgentCredential(
            $key,
            new AgentRotationRequest(
                $original->getAgentId(),
                $original->getCredentialId(),
                $original->getCredentialRevision(),
                $original->getDestination()
            )
        )));
        self::assertCount(2, $env->operationRepository->operations);
        self::assertSame([], $env->auditEvidenceRepository->all());
    }

    public function test_operation_revision_is_typed_complete_and_round_trips_without_diagnostics(): void
    {
        $env = new ProvisioningEnvironment();
        $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        $facts = $env->auditEvidenceRepository->all()[0]->getFacts();
        self::assertSame(0, $facts->getOperationRevision());
        self::assertEquals($facts, AuditAgentOutcome::fromArray($facts->toArray()));
        foreach (['missing', 'string', 'negative', 'without delivery'] as $case) {
            $data = $facts->toArray();
            if ($case === 'missing') {
                unset($data['operation_revision']);
            } elseif ($case === 'string') {
                $data['operation_revision'] = '0';
            } elseif ($case === 'negative') {
                $data['operation_revision'] = -1;
            } else {
                $data['delivery_id'] = null;
            }

            try {
                AuditAgentOutcome::fromArray($data);
                self::fail('Incomplete or invalid operation revision was admitted.');
            } catch (DomainException $failure) {
                self::assertNull($failure->getPrevious());
            }
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function uncertainOutcomes(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach ([false, true] as $committed) {
                yield (int) $rotation.' '.(int) $committed => [$rotation, $committed];
            }
        }
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_uncertain_commit_keeps_actual_state_and_evidence_together(
        bool $rotation,
        bool $committed
    ): void {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate?->provisioning ?? new ProvisioningEnvironment();
        $command = $this->command($env, $rotate);
        $initialEvidence = $env->auditEvidenceRepository->all();
        $initialOperations = $env->operationRepository->operations;
        $transaction = new UncertainAgentUnitOfWork($env->transaction, $committed);
        $bus = $rotate?->bus(unitOfWork: $transaction) ?? $env->bus(unitOfWork: $transaction);
        try {
            $bus->commandBus->execute($command);
            self::fail('Acknowledgement loss is not success or rollback proof.');
        } catch (AgentOperationUncertainException $agentOperationUncertainException) {
            self::assertNull($agentOperationUncertainException->getPrevious());
        }

        self::assertCount(count($initialEvidence) + (int) $committed, $env->auditEvidenceRepository->all());
        self::assertCount(count($initialOperations) + (int) $committed, $env->operationRepository->operations);
        if ($committed) {
            $stored = $env->operationRepository->operations[$command->getKey()->toString()];
            $evidence = array_last($env->auditEvidenceRepository->all());
            self::assertSame($stored->getOriginatingActorId(), $evidence->actorId());
            $generations = $env->generations;
            ($rotate?->bus() ?? $env->bus())->commandBus->execute($command);
            self::assertSame($generations, $env->generations);
        } else {
            self::assertSame($initialOperations, $env->operationRepository->operations);
            self::assertSame($initialEvidence, $env->auditEvidenceRepository->all());
        }
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_publication_failure_preserves_committed_origin_and_audit(
        bool $rotation,
        bool $bothPublishersFail
    ): void {
        $rotate = $rotation ? new RotationEnvironment() : null;
        $env = $rotate?->provisioning ?? new ProvisioningEnvironment();
        $command = $this->command($env, $rotate);
        $calls = 0;
        $env->eventDispatcher = new InMemoryEventDispatcher(
            static function () use (&$calls, $bothPublishersFail): void {
                if (++$calls === 1 || $bothPublishersFail) {
                    throw new RuntimeException('Synthetic private publication diagnostic.');
                }
            }
        );
        $bus = $rotate?->bus() ?? $env->bus();
        $bus->commandBus->execute($command);

        $stored = $env->operationRepository->operations[$command->getKey()->toString()];
        $evidence = array_last($env->auditEvidenceRepository->all());
        self::assertSame($stored->getOriginatingActorId(), $evidence->actorId());
        self::assertTrue($bus->dispatchAndRead($command)->isConfirmed());
        self::assertSame(2, $calls);
        self::assertStringNotContainsString('Synthetic private', serialize($env->eventDispatcher->events()));
    }

    private function command(
        ProvisioningEnvironment $env,
        ?RotationEnvironment $rotation
    ): ProvisionAgent|RotateAgentCredential {
        if ($rotation !== null) {
            return new RotateAgentCredential($rotation->key, $rotation->request);
        }

        return new ProvisionAgent($env->key, $env->request);
    }
}
