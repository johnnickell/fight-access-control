<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\RotateAgentCredentialHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\GetAgentOperationHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Meta;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\RotationEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ProvisionAgentHandler::class)]
#[CoversClass(RotateAgentCredentialHandler::class)]
#[CoversClass(GetAgentOperationHandler::class)]
#[CoversClass(ProvisionAgent::class)]
#[CoversClass(RotateAgentCredential::class)]
#[CoversClass(GetAgentOperation::class)]
#[CoversClass(AgentOperationView::class)]
final class AgentIssuanceDispatchContractTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function invocations(): iterable
    {
        foreach ([false, true] as $rotation) {
            foreach ([false, true] as $dispatch) {
                foreach ([false, true] as $publicationFailure) {
                    $name = ($rotation ? 'rotate' : 'provision').($dispatch ? '-dispatch' : '-execute');
                    $name .= $publicationFailure ? '-publishers-fail' : '-published';

                    yield $name => [$rotation, $dispatch, $publicationFailure];
                }
            }
        }
    }

    public function test_expected_registrations_and_native_returns_are_exact(): void
    {
        self::assertSame(ProvisionAgent::class, ProvisionAgentHandler::commandRegistration());
        self::assertSame(RotateAgentCredential::class, RotateAgentCredentialHandler::commandRegistration());
        self::assertSame(GetAgentOperation::class, GetAgentOperationHandler::queryRegistration());
        foreach ([ProvisionAgentHandler::class, RotateAgentCredentialHandler::class] as $handler) {
            self::assertSame('void', (string) new ReflectionMethod($handler, 'handle')->getReturnType());
        }

        self::assertSame(
            AgentOperationView::class,
            (string) new ReflectionMethod(GetAgentOperationHandler::class, 'handle')->getReturnType()
        );
    }

    #[DataProvider('invocations')]
    public function test_concrete_messages_and_optional_outcomes_through_real_registered_buses(
        bool $rotation,
        bool $dispatch,
        bool $publicationFailure
    ): void {
        $env = new ProvisioningEnvironment();
        $command = new ProvisionAgent($env->key, $env->request);
        $fixture = $env->bus();
        if ($rotation) {
            $rotationEnvironment = new RotationEnvironment();
            $env = $rotationEnvironment->provisioning;
            $command = new RotateAgentCredential($rotationEnvironment->key, $rotationEnvironment->request);
            $fixture = $rotationEnvironment->bus();
        }

        if ($publicationFailure) {
            $env->eventDispatcher = new InMemoryEventDispatcher(static function (): void {
                throw new RuntimeException('Controlled publication failure.');
            });
            $fixture = isset($rotationEnvironment) ? $rotationEnvironment->bus() : $env->bus();
        }

        $query = new GetAgentOperation($command->getKey(), $command->getRequest()->getDestination());
        $queryOriginal = QueryMessage::create($query);
        $queryMessage = $queryOriginal->withMeta(Meta::create(['correlation' => 'safe', 'replace' => 1]))
            ->mergeMeta(Meta::create(['replace' => 2, 'purpose' => 'observation']));
        $querySnapshot = $queryMessage->toArray();
        self::assertSame([], $queryOriginal->meta()->toArray());
        self::assertSame($queryOriginal->id(), $queryMessage->id());
        self::assertSame($queryOriginal->timestamp(), $queryMessage->timestamp());
        self::assertSame($query, $queryMessage->payload());
        self::assertSame(
            ['correlation' => 'safe', 'replace' => 2, 'purpose' => 'observation'],
            $queryMessage->meta()->toArray()
        );
        $queryMessage = QueryMessage::arrayDeserialize($querySnapshot);
        self::assertSame($querySnapshot, $queryMessage->toArray());
        /** @var AgentOperationView $before Known production GetAgentOperation binding */
        $before = $fixture->queryBus->dispatch($queryMessage);
        self::assertFalse($before->isConfirmed());
        self::assertNull($before->getIssuance());
        self::assertNull($before->getDeliveryDisposition());
        self::assertNull($before->getCredentialDisposition());
        self::assertSame('indeterminate', $before->toArray()['issuance_outcome']);

        $original = CommandMessage::create($command);
        $metadata = Meta::create(['correlation' => 'safe', 'replace' => 1]);
        $message = $original->withMeta($metadata)->mergeMeta(Meta::create(['replace' => 2]));
        $snapshot = $message->toArray();
        $metadata->set('correlation', 'changed');
        $message->meta()->set('correlation', 'changed-copy');
        self::assertSame([], $original->meta()->toArray());
        self::assertSame($original->id(), $message->id());
        self::assertSame($original->timestamp(), $message->timestamp());
        self::assertSame($command, $message->payload());
        self::assertSame(['correlation' => 'safe', 'replace' => 2], $message->meta()->toArray());
        self::assertSame($snapshot, $message->toArray());
        $restored = CommandMessage::arrayDeserialize($snapshot);
        self::assertSame($snapshot, $restored->toArray());
        self::assertInstanceOf($command::class, $restored->payload());
        self::assertSame($command->toArray(), $restored->payload()->toArray());
        if ($dispatch) {
            $fixture->commandBus->dispatch($restored);
        } else {
            $fixture->commandBus->execute($command);
        }

        // Void submission/execution is not an outcome. Read authoritative storage independently.
        /** @var AgentOperationView $fetched Known production GetAgentOperation binding */
        $fetched = $fixture->queryBus->fetch($query);
        $transactions = $env->transaction->transactions;
        $generations = $env->generations;
        $audit = $env->auditEvidenceRepository->all();
        $events = $env->eventDispatcher->events();
        /** @var AgentOperationView $observed Known production GetAgentOperation binding */
        $observed = $fixture->queryBus->dispatch($queryMessage);
        self::assertEquals($fetched, $observed);
        self::assertTrue($observed->isConfirmed());
        self::assertInstanceOf(AgentIssuance::class, $observed->getIssuance());
        self::assertSame($command->getKey()->toArray(), $observed->getIssuance()->getKey()->toArray());
        self::assertSame(AgentDeliveryDisposition::PENDING, $observed->getDeliveryDisposition());
        self::assertSame(AgentCredentialDisposition::CURRENT, $observed->getCredentialDisposition());
        self::assertArrayNotHasKey('warning', $observed->toArray());
        self::assertSame($transactions, $env->transaction->transactions);
        self::assertSame($generations, $env->generations);
        self::assertSame($audit, $env->auditEvidenceRepository->all());
        self::assertSame($events, $env->eventDispatcher->events());
        self::assertSame($querySnapshot, $queryMessage->toArray());
        self::assertSame($snapshot, $restored->toArray());
        foreach (['original-test-secret', 'successor-test-secret', 'auth-envelope:'] as $secret) {
            self::assertStringNotContainsString($secret, serialize([$snapshot, $querySnapshot, $observed]));
        }
    }
}
