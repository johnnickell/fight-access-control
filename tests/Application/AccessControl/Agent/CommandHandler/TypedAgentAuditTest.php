<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\CommandHandler;

use Fight\AccessControl\Application\AccessControl\Agent\CommandHandler\ProvisionAgentHandler;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentIssuanceFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProvisionAgentHandler::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditAgentState::class)]
final class TypedAgentAuditTest extends TestCase
{
    public function test_delegated_invoker_preserves_explicit_origin_without_parsing_caller_labels(): void
    {
        $environment = new ProvisioningEnvironment();
        $origin = UserId::generate();
        $environment->operationAuthorization->originatingActorId = $origin;
        $environment->operationAuthorization->actor = 'authenticated-worker-not-a-uuid';
        $environment->operationAuthorization->readDelegations[
            'authenticated-worker-not-a-uuid:'.$environment->key->getScope()->toString()
        ] = true;
        $command = new ProvisionAgent($environment->key, $environment->request);
        $environment->bus()->commandBus->execute($command);
        $evidence = $environment->auditEvidenceRepository->all()[0];
        self::assertSame($origin, $evidence->actorId());
        self::assertSame(AuditAction::AGENT_PROVISIONED, $evidence->action());
        self::assertSame($environment->key->toString(), $evidence->getCorrelation()?->getOperationKey()?->toString());
        self::assertNotNull($evidence->getCorrelation()->getMessageId());
        $facts = $evidence->getFacts();
        self::assertInstanceOf(AuditAgentOutcome::class, $facts);
        self::assertNull($facts->getBefore());
        $operation = $environment->operationRepository->operations[$environment->key->toString()] ?? null;
        self::assertNotNull($operation);
        self::assertSame($operation->getIssuance()->getDeliveryId(), $facts->getDeliveryId());
        self::assertEquals($operation->getIssuance()->getIssuedAt(), $evidence->getOccurredAt());
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
    }

    public function test_missing_origin_fails_before_any_new_issuance_but_not_original_key_resolution(): void
    {
        $environment = new ProvisioningEnvironment();
        $command = new ProvisionAgent($environment->key, $environment->request);
        $bus = $environment->bus();
        $environment->operationAuthorization->originatingActorId = null;
        try {
            $bus->commandBus->execute($command);
            self::fail('Missing provenance admitted a new audited mutation.');
        } catch (AgentIssuanceFailed) {
            self::assertSame([], $environment->operationRepository->operations);
            self::assertSame([], $environment->agentRepository->all());
            self::assertSame([], $environment->auditEvidenceRepository->all());
            self::assertSame(0, $environment->generations);
        }

        $environment->operationAuthorization->originatingActorId = UserId::generate();
        $bus->commandBus->execute($command);
        /** @var list<AuditEvidence> $records The intervening bus call commits new fixture state */
        $records = $environment->auditEvidenceRepository->all();
        $evidence = $records[0];
        $environment->operationAuthorization->originatingActorId = null;
        $bus->commandBus->execute($command);
        self::assertCount(1, $environment->agentRepository->all());
        self::assertSame([$evidence], $environment->auditEvidenceRepository->all());
        self::assertSame(1, $environment->generations);
    }
}
