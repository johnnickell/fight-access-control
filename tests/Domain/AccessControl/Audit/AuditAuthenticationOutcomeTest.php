<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAuthenticationOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditAuthenticationOutcome::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditUserRevisions::class)]
final class AuditAuthenticationOutcomeTest extends TestCase
{
    public static function invalidRevisions(): iterable
    {
        yield 'creation must begin at zero' => [null, 1];
        yield 'negative predecessor' => [-1, 0];
        yield 'overflow' => [PHP_INT_MAX, PHP_INT_MAX];
        yield 'skipped revision' => [0, 2];
    }

    #[DataProvider('invalidRevisions')]
    public function test_session_revisions_describe_only_creation_or_one_rotation(?int $before, int $after): void
    {
        $this->expectException(DomainException::class);
        new AuditAuthenticationOutcome($this->login(), RefreshSessionId::generate(), $before, $after);
    }

    public static function malformedPayloads(): iterable
    {
        yield 'missing fields' => [[]];
        yield 'unknown field' => [[
            'user'           => [],
            'session_id'     => 'id',
            'session_before' => null,
            'session_after'  => 0,
            'raw_token'      => 'excluded'
        ]];
        yield 'coercive revision' => [[
            'user' => [], 'session_id' => 'id', 'session_before' => '0', 'session_after' => 1
        ]];
        yield 'missing nullable revision' => [[
            'user' => [], 'session_id' => 'id', 'session_after' => 0, 'other' => null
        ]];
    }

    #[DataProvider('malformedPayloads')]
    public function test_hydration_rejects_unadmitted_fields_and_types(array $payload): void
    {
        $this->expectException(DomainException::class);
        AuditAuthenticationOutcome::fromArray($payload);
    }

    public static function unrelatedActions(): iterable
    {
        yield 'rotation needs a predecessor' => [AuditAction::SESSION_REFRESHED];
        yield 'unrelated action' => [AuditAction::PASSWORD_CHANGED];
    }

    #[DataProvider('unrelatedActions')]
    public function test_login_cannot_claim_rotation_or_an_unrelated_action(AuditAction $action): void
    {
        $facts = new AuditAuthenticationOutcome($this->login(), RefreshSessionId::generate(), null, 0);

        $this->expectException(DomainException::class);
        $facts->assertOutcome($action);
    }

    public function test_authentication_cannot_invent_a_missing_user_predecessor(): void
    {
        $facts = new AuditAuthenticationOutcome(
            new AuditUserOutcome(null, new AuditUserRevisions(UserState::ACTIVE, 1, 1, 0, 0, 0)),
            RefreshSessionId::generate(),
            null,
            0
        );

        $this->expectException(DomainException::class);
        $facts->assertOutcome(AuditAction::USER_LOGGED_IN);
    }

    public function test_activation_requires_its_actual_grant(): void
    {
        $facts = new AuditAuthenticationOutcome($this->login(), RefreshSessionId::generate(), null, 0);

        $this->expectException(DomainException::class);
        $facts->assertOutcome(AuditAction::USER_ACTIVATED);
    }

    public function test_login_cannot_claim_a_grant_change(): void
    {
        $grantId = ActivationGrantId::generate();
        $deliveryId = ActivationDeliveryId::generate();
        $facts = new AuditAuthenticationOutcome(
            new AuditUserOutcome(
                $this->login()->getBefore(),
                $this->login()->getAfter(),
                new AuditGrantState($grantId, 0, $deliveryId),
                new AuditGrantState($grantId, 1, $deliveryId)
            ),
            RefreshSessionId::generate(),
            null,
            0
        );

        $this->expectException(DomainException::class);
        $facts->assertOutcome(AuditAction::USER_LOGGED_IN);
    }

    public function test_the_evidence_envelope_rejects_authentication_facts_for_another_subject_kind(): void
    {
        $facts = new AuditAuthenticationOutcome($this->login(), RefreshSessionId::generate(), null, 0);
        $evidence = AuditEvidence::record(
            AnonymousId::generate(),
            new AuditSubject(UserId::generate()),
            AuditAction::USER_LOGGED_IN,
            new DateTimeImmutable('2030-01-01T12:00:00Z'),
            $facts,
            null
        );
        $payload = $evidence->toArray();
        $payload['subject']['kind'] = 'agent';

        $this->expectException(DomainException::class);
        AuditEvidence::fromArray($payload);
    }

    private function login(): AuditUserOutcome
    {
        return new AuditUserOutcome(
            new AuditUserRevisions(UserState::ACTIVE, 1, 1, 0, 0, 0),
            new AuditUserRevisions(UserState::ACTIVE, 1, 2, 0, 0, 0)
        );
    }
}
