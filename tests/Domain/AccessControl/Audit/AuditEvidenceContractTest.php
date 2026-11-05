<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\SessionRevocationReason;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AuditEvidence::class)]
final class AuditEvidenceContractTest extends TestCase
{
    /** @phpstan-return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidContexts(): iterable
    {
        yield 'numeric field' => [['unnamed']];
        yield 'integer value' => [['count' => 42]];
        yield 'boolean value' => [['flag' => false]];
        yield 'array value' => [['nested' => []]];
        yield 'null value' => [['optional' => null]];
        yield 'identity object' => [['user' => UserId::generate()]];
    }

    /** @phpstan-param array<array-key, mixed> $context */
    #[DataProvider('invalidContexts')]
    public function test_persisted_audit_context_is_a_named_string_map(array $context): void
    {
        $class = new ReflectionClass(AuditEvidence::class);
        $evidence = $class->newInstanceWithoutConstructor();
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $this->expectException(DomainException::class);
        $constructor->invoke($evidence, 'actor', 'example.action', UserId::generate(), $context);
    }

    public function test_current_factories_retain_exact_typed_subjects_and_safe_context(): void
    {
        $actor = UserId::generate();
        $user = UserId::generate();
        $agent = AgentId::generate();
        $session = RefreshSessionId::generate();
        $reason = SessionRevocationReason::fromString('Owner requested revocation');
        foreach (
            [
            AuditEvidence::record($actor->toString(), 'example.action', $user),
            AuditEvidence::agentProvisioned($actor->toString(), $agent),
            AuditEvidence::agentCredentialRotated($actor->toString(), $agent),
            AuditEvidence::agentCredentialRevoked($actor->toString(), $agent)
            ] as $index => $evidence
        ) {
            self::assertSame($actor->toString(), $evidence->actorId());
            self::assertSame($index === 0 ? $user : $agent, $evidence->subjectId());
            self::assertSame([], $evidence->context());
        }

        $evidence = AuditEvidence::administrativeSessionRevocation($actor, $user, $session, $reason);
        self::assertSame($user, $evidence->subjectId());
        self::assertSame('refresh_session.administratively_revoked', $evidence->action());
        self::assertSame([
            'refresh_session_id' => $session->toString(),
            'reason'             => $reason->toString()
        ], $evidence->context());
        $context = $evidence->context();
        $context['reason'] = 'changed outside';
        self::assertNotSame($context, $evidence->context());
        self::assertSame($reason->toString(), $evidence->context()['reason']);
    }
}
