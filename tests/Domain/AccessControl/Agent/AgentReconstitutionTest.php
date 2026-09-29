<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Agent::class)]
final class AgentReconstitutionTest extends TestCase
{
    /** @return iterable<string, array{AgentState, bool}> */
    public static function states(): iterable
    {
        foreach (AgentState::cases() as $state) {
            foreach ([false, true] as $recoverable) {
                yield $state->value.'-'.(int) $recoverable => [$state, $recoverable];
            }
        }
    }

    #[DataProvider('states')]
    public function test_reconstitution_preserves_all_persisted_authority(AgentState $state, bool $recoverable): void
    {
        $id = AgentId::generate();
        $credential = AgentCredentialId::generate();
        $name = AgentName::fromString('Existing deployment');
        $permissions = [PermissionId::generate(), PermissionId::generate()];
        $created = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $updated = $created->modify('+1 day');
        $agent = Agent::reconstitute(
            $id,
            $name,
            $state,
            $credential,
            7,
            'persisted-envelope',
            $permissions,
            12,
            $created,
            $updated,
            $recoverable
        );
        self::assertSame($id, $agent->getId());
        self::assertSame($name, $agent->getName());
        self::assertSame($state, $agent->getState());
        self::assertSame($credential, $agent->getCredentialId());
        self::assertSame(7, $agent->getCredentialRevision());
        self::assertSame('persisted-envelope', $agent->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame($permissions, $agent->getPermissionIds());
        self::assertSame(12, $agent->getPermissionAssignmentRevision());
        self::assertSame($created, $agent->getCreatedAt());
        self::assertSame($updated, $agent->getUpdatedAt());
        self::assertSame($recoverable, $agent->hasRecoverableCredentialOperation());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidState(): iterable
    {
        foreach (['credential revision', 'permission revision', 'envelope', 'time', 'duplicate', 'non-list'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidState')]
    public function test_invalid_history_is_rejected_not_repaired_or_normalized(string $case): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $permission = PermissionId::generate();
        $permissions = [$permission];
        if ($case === 'duplicate') {
            $permissions[] = PermissionId::fromString($permission->toString());
        }

        if ($case === 'non-list') {
            $permissions = [2 => $permission];
        }

        try {
            Agent::reconstitute(
                AgentId::generate(),
                AgentName::fromString('Existing deployment'),
                AgentState::ACTIVE,
                AgentCredentialId::generate(),
                $case === 'credential revision' ? -1 : 7,
                $case === 'envelope' ? '' : 'private-persisted-envelope',
                $permissions,
                $case === 'permission revision' ? 0 : 12,
                $at,
                $case === 'time' ? $at->modify('-1 second') : $at,
                false
            );
            self::fail('Invalid historical authority must not be silently reconstructed.');
        } catch (AgentCredentialException $agentCredentialException) {
            self::assertSame('The persisted Agent authority is invalid.', $agentCredentialException->getMessage());
            self::assertNull($agentCredentialException->getPrevious());
            self::assertStringNotContainsString(
                'private-persisted-envelope',
                print_r($agentCredentialException->getTrace()[0], true)
            );
        }
    }

    public function test_only_a_new_credential_can_change_the_legacy_marker_and_it_cannot_be_downgraded(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $legacy = Agent::reconstitute(
            AgentId::generate(),
            AgentName::fromString('Existing deployment'),
            AgentState::ACTIVE,
            AgentCredentialId::generate(),
            7,
            'legacy-envelope',
            [],
            12,
            $at,
            $at,
            false
        );
        $successor = $legacy->rotateRecoverableCredential(
            $legacy->getCredentialId(),
            7,
            AgentCredentialId::generate(),
            'new-envelope',
            $at
        );
        self::assertTrue($legacy->canReplaceCredentialWith($successor));
        self::assertTrue($successor->hasRecoverableCredentialOperation());
        self::assertFalse($legacy->hasRecoverableCredentialOperation());
        self::assertSame(8, $successor->getCredentialRevision());
        self::assertTrue($legacy->canReplaceCredentialWith($legacy->revoke($at)));
        self::assertTrue($successor->canReplaceCredentialWith($successor->revoke($at)));
        foreach ([AgentState::ACTIVE, AgentState::REVOKED] as $state) {
            $markerOnly = Agent::reconstitute(
                $legacy->getId(),
                $legacy->getName(),
                $state,
                $legacy->getCredentialId(),
                7,
                'legacy-envelope',
                [],
                12,
                $at,
                $at,
                true
            );
            self::assertFalse($legacy->canReplaceCredentialWith($markerOnly));
            $downgrade = Agent::reconstitute(
                $successor->getId(),
                $successor->getName(),
                $state,
                $state === AgentState::ACTIVE ? AgentCredentialId::generate() : $successor->getCredentialId(),
                $state === AgentState::ACTIVE ? 9 : 8,
                'new-envelope',
                [],
                12,
                $at,
                $at,
                false
            );
            self::assertFalse($successor->canReplaceCredentialWith($downgrade));
        }

        $inactivePromotion = Agent::reconstitute(
            $legacy->getId(),
            $legacy->getName(),
            AgentState::PROVISIONED,
            AgentCredentialId::generate(),
            8,
            'new-envelope',
            [],
            12,
            $at,
            $at,
            true
        );
        self::assertFalse($legacy->canReplaceCredentialWith($inactivePromotion));

        $exhausted = Agent::reconstitute(
            $legacy->getId(),
            $legacy->getName(),
            AgentState::ACTIVE,
            $legacy->getCredentialId(),
            PHP_INT_MAX,
            'legacy-envelope',
            [],
            12,
            $at,
            $at,
            false
        );
        $this->expectException(AgentCredentialException::class);
        $exhausted->assertRecoverableRotation($exhausted->getCredentialId(), PHP_INT_MAX);
    }
}
