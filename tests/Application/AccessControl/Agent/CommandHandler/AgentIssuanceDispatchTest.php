<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\MessageId;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\FixedHmacSharedSecretCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\IssuanceBusFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\RotationEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\BoundAgentDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProvisionAgentHandler::class)]
#[CoversClass(RotateAgentCredentialHandler::class)]
#[CoversClass(ProvisionAgent::class)]
#[CoversClass(RotateAgentCredential::class)]
#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(AgentOperationView::class)]
final class AgentIssuanceDispatchTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function kinds(): iterable
    {
        yield 'provision' => [false];
        yield 'rotation' => [true];
    }

    #[DataProvider('kinds')]
    public function test_serialized_delay_restart_and_redelivery_use_current_worker_authority_and_independent_queries(
        bool $rotation
    ): void {
        [$env, $command, $bus] = $this->scenario($rotation);
        $query = new GetAgentOperation($command->getKey(), $command->getRequest()->getDestination());
        self::assertFalse($bus->queryBus->fetch($query)->isConfirmed());
        $metadata = Meta::create(['correlation_id' => 'original-correlation', 'origin' => 'authorized-submitter']);
        $message = new CommandMessage(
            MessageId::generate(),
            new DateTimeImmutable('2026-11-04T18:00:00Z'),
            $command,
            $metadata
        );
        $snapshot = $message->toArray();
        $metadata->set('origin', 'mutated-external-object');
        $message->meta()->set('origin', 'mutated-returned-copy');
        self::assertSame($snapshot, $message->toArray());
        $retained = json_encode($snapshot, JSON_THROW_ON_ERROR);
        unset($message, $metadata, $bus);

        // No submitting request/transport survives. The worker authenticates independently of payload and metadata.
        $env->operationAuthorization->actor = 'worker';
        $before = $this->effects($env);
        $restored = CommandMessage::arrayDeserialize(json_decode($retained, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($snapshot, $restored->toArray());
        $bus = $this->bus($env, $rotation);
        $this->denied(fn () => $bus->commandBus->dispatch($restored));
        $this->denied(fn (): mixed => $bus->queryBus->fetch($query));
        self::assertSame(array_slice($before, 0, -1), array_slice($this->effects($env), 0, -1));

        $env->operationAuthorization->readDelegations['worker:'.$command->getKey()->getScope()->toString()] = true;
        self::assertFalse($bus->queryBus->fetch($query)->isConfirmed());
        $bus->commandBus->dispatch($restored);
        self::assertSame($snapshot, $restored->toArray());
        $confirmed = $bus->queryBus->fetch($query);
        self::assertTrue($confirmed->isConfirmed());
        self::assertSame(AgentDeliveryDisposition::PENDING, $confirmed->getDeliveryDisposition());
        self::assertSame('worker', array_last($env->auditEvidenceRepository->all())->actorId());
        self::assertSame($command->getKey()->toArray(), $confirmed->getIssuance()->getKey()->toArray());
        self::assertArrayNotHasKey('warning', $confirmed->toArray());
        $committed = $this->effects($env);
        unset($bus, $restored);

        $bus = $this->bus($env, $rotation);
        $bus->commandBus->dispatch(
            CommandMessage::arrayDeserialize(json_decode($retained, true, flags: JSON_THROW_ON_ERROR))
        );
        $bus->commandBus->dispatch(CommandMessage::create($command));
        self::assertSame($committed, $this->effects($env));
        self::assertEquals($confirmed, $bus->queryBus->fetch($query));
        $safe = serialize(
            [$command, $confirmed, $env->eventDispatcher->events(), $env->auditEvidenceRepository->all()]
        );
        foreach (['original-test-secret', 'successor-test-secret', 'auth-envelope:'] as $secret) {
            self::assertStringNotContainsString($secret, $safe);
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function authorityChanges(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach (['scope', 'delegation', 'destination', 'target'] as $change) {
                if (!$rotation && $change === 'target') {
                    continue;
                }

                yield ($rotation ? 'rotation ' : 'provision ').$change => [$rotation, $change];
            }
        }
    }

    #[DataProvider('authorityChanges')]
    public function test_enqueue_time_authority_cannot_authorize_delayed_execution(bool $rotation, string $change): void
    {
        [$env, $command, $bus] = $this->scenario($rotation);
        $retained = CommandMessage::create($command)->toArray();
        $env->operationAuthorization->actor = 'worker';
        $delegation = 'worker:'.$command->getKey()->getScope()->toString();
        $env->operationAuthorization->readDelegations[$delegation] = true;
        $query = new GetAgentOperation($command->getKey(), $command->getRequest()->getDestination());
        self::assertFalse($bus->queryBus->fetch($query)->isConfirmed());
        if ($change === 'scope') {
            $env->operationAuthorization->scopes = [];
        } elseif ($change === 'delegation') {
            $env->operationAuthorization->delegationExpired = true;
        } elseif ($change === 'destination') {
            $env->operationAuthorization->destinations = [];
        } else {
            self::assertInstanceOf(RotateAgentCredential::class, $command);
            $env->operationAuthorization->deniedTargets[] = $command->getRequest()->getAgentId()->toString();
        }

        $before = $this->effects($env);
        $reads = $env->operationRepository->reads;
        $this->denied(
            fn () => $this->bus($env, $rotation)->commandBus->dispatch(CommandMessage::arrayDeserialize($retained))
        );
        self::assertSame($reads, $env->operationRepository->reads);
        // Failure evidence is allowed; business state, generation, reservations and audit are unchanged.
        self::assertSame(array_slice($before, 0, -1), array_slice($this->effects($env), 0, -1));
        self::assertArrayNotHasKey($command->getKey()->toString(), $env->operationRepository->operations);
    }

    public function test_out_of_order_competing_rotations_cannot_retarget_the_original_predecessor(): void
    {
        $rotation = new RotationEnvironment();
        $env = $rotation->provisioning;
        $older = new RotateAgentCredential($rotation->key, $rotation->request);
        $newerKey = new AgentOperationKey($rotation->key->getScope(), AgentOperationId::generate());
        $newer = new RotateAgentCredential($newerKey, $rotation->request);
        $retained = CommandMessage::create($older)->toArray();
        $bus = $rotation->bus();
        $bus->commandBus->execute($newer);

        $winner = $bus->queryBus->fetch(new GetAgentOperation($newerKey, $rotation->request->getDestination()));
        $before = $this->effects($env);
        $this->denied(
            fn () => $rotation->bus()->commandBus->dispatch(CommandMessage::arrayDeserialize($retained)),
            AgentOperationFailure::CONFLICT
        );
        $missing = $bus->queryBus->fetch(new GetAgentOperation($rotation->key, $rotation->request->getDestination()));
        self::assertFalse($missing->isConfirmed());
        self::assertSame('indeterminate', $missing->toArray()['issuance_outcome']);
        self::assertSame(array_slice($before, 0, -1), array_slice($this->effects($env), 0, -1));
        self::assertSame($winner->getIssuance()->getCredentialId(), $env->agentRepository->all()[0]->getCredentialId());
        $bus->commandBus->execute($newer);
        self::assertSame(2, $env->generations);
        self::assertCount(2, $env->auditEvidenceRepository->all());
    }

    /** @return array{ProvisioningEnvironment, ProvisionAgent|RotateAgentCredential, IssuanceBusFixture} */
    private function scenario(bool $rotation): array
    {
        if ($rotation) {
            $fixture = new RotationEnvironment();

            return [$fixture->provisioning, new RotateAgentCredential(
                $fixture->key,
                $fixture->request
            ), $fixture->bus()];
        }

        $env = new ProvisioningEnvironment();

        return [$env, new ProvisionAgent($env->key, $env->request), $env->bus()];
    }

    private function bus(ProvisioningEnvironment $env, bool $rotation): IssuanceBusFixture
    {
        if ($rotation) {
            // RotationEnvironment normally creates its predecessor. Reuse the already persisted original here.
            $handler = new RotateAgentCredentialHandler(
                $env->agentRepository,
                $env->operationRepository,
                $env->auditEvidenceRepository,
                $env->operationAuthorization,
                new readonly class ($env) implements HmacSharedSecretGenerator {
                    public function __construct(private ProvisioningEnvironment $environment)
                    {
                    }

                    public function generate(): string
                    {
                        ++$this->environment->generations;

                        return 'successor-test-secret';
                    }
                },
                new FixedHmacSharedSecretCipher('auth-envelope:'),
                new BoundAgentDeliveryCipher(),
                new FixedClock(new DateTimeImmutable('2026-11-04T18:00:00Z')),
                $env->transaction,
                $env->eventDispatcher
            );

            return new IssuanceBusFixture($handler, $env->operationRepository, $env->operationAuthorization);
        }

        return $env->bus();
    }

    /** @return array<int, mixed> */
    private function effects(ProvisioningEnvironment $env): array
    {
        return [$env->agentRepository->all(), $env->operationRepository->operations,
            $env->operationRepository->versions, $env->auditEvidenceRepository->all(),
            $env->generations, $env->eventDispatcher->events()];
    }

    private function denied(callable $action, AgentOperationFailure $reason = AgentOperationFailure::UNAUTHORIZED): void
    {
        try {
            $action();
            self::fail('Current authority or expected state must reject.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame($reason, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }
    }
}
