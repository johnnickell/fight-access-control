<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditRoleRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\MessageId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditRoleRevisions::class)]
#[CoversClass(RoleRevision::class)]
final class AuditCreationAdmissionTest extends TestCase
{
    /** @return iterable<string, array{AuditAction, array<array-key, mixed>, bool}> */
    public static function noninitialCreation(): iterable
    {
        foreach ([false, true] as $hydrate) {
            foreach ([1, 2, 99, PHP_INT_MAX] as $revision) {
                yield 'Agent revision '.$revision.' hydrate '.(int) $hydrate => [
                    AuditAction::AGENT_PROVISIONED,
                    [
                        'before'      => null,
                        'after'       => [
                            'credential_id'       => AgentCredentialId::generate()->toString(),
                            'credential_revision' => $revision,
                            'state'               => 'active'
                        ],
                        'delivery_id' => AgentDeliveryId::generate()->toString()
                    ],
                    $hydrate
                ];
            }

            foreach ([2, 99, RoleRevision::MAX_SEQUENCE] as $sequence) {
                $incarnation = explode(':', RoleRevision::initial()->toString())[0];
                yield 'Role sequence '.$sequence.' hydrate '.(int) $hydrate => [
                    AuditAction::ROLE_CREATED,
                    ['before' => null, 'after' => $incarnation.':'.$sequence],
                    $hydrate
                ];
            }
        }
    }

    /** @return iterable<string, array{AuditAction, array<array-key, mixed>}> */
    public static function authoritativeOutcomes(): iterable
    {
        $at = new DateTimeImmutable('2026-11-09T12:00:00.000000+00:00');
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Audit creation'),
            AgentCredentialId::generate(),
            'synthetic-envelope-not-admitted',
            $at
        );
        yield 'Agent provision' => [AuditAction::AGENT_PROVISIONED, new AuditAgentOutcome(
            null,
            AuditAgentState::fromAgent($agent),
            AgentDeliveryId::generate()
        )->toArray()];
        foreach ([0, 99, PHP_INT_MAX - 1, PHP_INT_MAX] as $revision) {
            $existing = Agent::reconstitute(
                $agent->getId(),
                $agent->getName(),
                AgentState::ACTIVE,
                $agent->getCredentialId(),
                $revision,
                'synthetic-envelope-not-admitted',
                [],
                1,
                $at,
                $at
            );
            $before = AuditAgentState::fromAgent($existing);
            yield 'Agent revoke '.$revision => [AuditAction::AGENT_CREDENTIAL_REVOKED, new AuditAgentOutcome(
                $before,
                AuditAgentState::fromAgent($existing->revoke($at)),
                null
            )->toArray()];
            if ($revision < PHP_INT_MAX) {
                $rotated = $existing->rotateRecoverableCredential(
                    $existing->getCredentialId(),
                    $existing->getCredentialRevision(),
                    AgentCredentialId::generate(),
                    'synthetic-successor-envelope-not-admitted',
                    $at
                );
                yield 'Agent rotate '.$revision => [AuditAction::AGENT_CREDENTIAL_ROTATED, new AuditAgentOutcome(
                    $before,
                    AuditAgentState::fromAgent($rotated),
                    AgentDeliveryId::generate()
                )->toArray()];
            }
        }

        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_AUDIT'), [], $at);
        yield 'Role create' => [
            AuditAction::ROLE_CREATED, new AuditRoleRevisions(null, $role->getRevision())->toArray()
        ];
        foreach ([1, 99, RoleRevision::MAX_SEQUENCE - 1, RoleRevision::MAX_SEQUENCE] as $sequence) {
            $incarnation = explode(':', $role->getRevision()->toString())[0];
            $existing = Role::reconstitute(
                $role->getId(),
                $role->getName(),
                [],
                false,
                $at,
                $at,
                RoleRevision::fromString($incarnation.':'.$sequence)
            );
            $before = $existing->getRevision();
            yield 'Role remove '.$sequence => [
                AuditAction::ROLE_REMOVED, new AuditRoleRevisions($before, null)->toArray()
            ];
            if ($sequence < RoleRevision::MAX_SEQUENCE) {
                $renamed = $existing->renameCustom(RoleName::fromString('ROLE_RENAMED_AUDIT'), $at);
                yield 'Role rename '.$sequence => [AuditAction::ROLE_RENAMED, new AuditRoleRevisions(
                    $before,
                    $renamed->getRevision()
                )->toArray()];
            }
        }
    }

    /** @return iterable<string, array{AuditAction, array<array-key, mixed>, bool}> */
    public static function invalidAdjacentOutcomes(): iterable
    {
        foreach ([false, true] as $hydrate) {
            $state = [
                'credential_id'       => AgentCredentialId::generate()->toString(),
                'credential_revision' => PHP_INT_MAX,
                'state'               => 'active'
            ];
            $successor = $state;
            $successor['credential_id'] = AgentCredentialId::generate()->toString();
            yield 'Agent exhausted rotation hydrate '.(int) $hydrate => [
                AuditAction::AGENT_CREDENTIAL_ROTATED,
                ['before' => $state, 'after' => $successor, 'delivery_id' => AgentDeliveryId::generate()->toString()],
                $hydrate
            ];
            $incarnation = explode(':', RoleRevision::initial()->toString())[0];
            $maximum = $incarnation.':'.RoleRevision::MAX_SEQUENCE;
            yield 'Role exhausted change hydrate '.(int) $hydrate => [
                AuditAction::ROLE_RENAMED,
                ['before' => $maximum, 'after' => $incarnation.':1'],
                $hydrate
            ];
        }

        foreach (self::authoritativeOutcomes() as $name => [$action, $facts]) {
            $invalid = [];
            if (str_starts_with($name, 'Agent')) {
                $invalid['wrong successor lifecycle'] = $facts;
                $invalid['wrong successor lifecycle']['after']['state'] = 'revoked';
                if ($action === AuditAction::AGENT_CREDENTIAL_REVOKED) {
                    $invalid['wrong successor lifecycle']['after']['state'] = 'active';
                }

                if ($facts['before'] === null) {
                    $invalid['invented predecessor'] = $facts;
                    $invalid['invented predecessor']['before'] = $facts['after'];
                } else {
                    $invalid['missing predecessor'] = $facts;
                    $invalid['missing predecessor']['before'] = null;
                    $invalid['revoked predecessor'] = $facts;
                    $invalid['revoked predecessor']['before']['state'] = 'revoked';
                    $invalid['wrong credential identity'] = $facts;
                    $wrongCredentialId = $facts['before']['credential_id'];
                    if ($action === AuditAction::AGENT_CREDENTIAL_REVOKED) {
                        $wrongCredentialId = AgentCredentialId::generate()->toString();
                    }

                    $invalid['wrong credential identity']['after']['credential_id'] = $wrongCredentialId;
                    foreach ([0, 1, 98, PHP_INT_MAX] as $revision) {
                        if ($revision === $facts['after']['credential_revision']) {
                            continue;
                        }

                        $invalid['wrong successor revision '.$revision] = $facts;
                        $invalid['wrong successor revision '.$revision]['after']['credential_revision'] = $revision;
                    }
                }

                if ($action !== AuditAction::AGENT_CREDENTIAL_REVOKED) {
                    $invalid['missing delivery'] = $facts;
                    $invalid['missing delivery']['delivery_id'] = null;
                }
            } else {
                foreach ([AuditAction::ROLE_CREATED, AuditAction::ROLE_RENAMED, AuditAction::ROLE_REMOVED] as $other) {
                    if ($other !== $action) {
                        foreach ([false, true] as $hydrate) {
                            yield $name.' wrong action '.$other->value.' hydrate '.(int) $hydrate
                                => [$other, $facts, $hydrate];
                        }
                    }
                }

                if ($action === AuditAction::ROLE_RENAMED) {
                    $invalid['unchanged revision'] = ['before' => $facts['before'], 'after' => $facts['before']];
                    $invalid['different incarnation'] = $facts;
                    $invalid['different incarnation']['after'] = RoleRevision::initial()->next()->toString();
                    $invalid['reversed revision'] = ['before' => $facts['after'], 'after' => $facts['before']];
                    $before = RoleRevision::fromString($facts['before']);
                    if (!str_ends_with($facts['after'], ':'.RoleRevision::MAX_SEQUENCE)) {
                        $invalid['skipped revision'] = [
                            'before' => $facts['before'], 'after' => $before->next()->next()->toString()
                        ];
                    }
                }
            }

            foreach ($invalid as $mutation => $contradiction) {
                foreach ([false, true] as $hydrate) {
                    yield $name.' '.$mutation.' hydrate '.(int) $hydrate => [$action, $contradiction, $hydrate];
                }
            }
        }
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('authoritativeOutcomes')]
    public function test_domain_derived_creation_and_existing_histories_preserve_the_supplied_facts(
        AuditAction $action,
        array $facts
    ): void {
        foreach ([false, true] as $hydrate) {
            $evidence = $this->admit($action, $facts, $hydrate);
            self::assertSame($facts, $evidence->getFacts()->toArray());
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        }
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('invalidAdjacentOutcomes')]
    public function test_adjacent_action_identity_lifecycle_and_revision_contradictions_reject(
        AuditAction $action,
        array $facts,
        bool $hydrate
    ): void {
        $this->expectException(DomainException::class);
        $this->admit($action, $facts, $hydrate);
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('noninitialCreation')]
    public function test_creation_rejects_invented_intermediate_revisions(
        AuditAction $action,
        array $facts,
        bool $hydrate
    ): void {
        $this->expectException(DomainException::class);
        $this->admit($action, $facts, $hydrate);
    }

    /** @param array<array-key, mixed> $facts */
    private function admit(AuditAction $action, array $facts, bool $hydrate): AuditEvidence
    {
        $subject = new AuditSubject(AgentId::generate());
        if (
            in_array($action, [AuditAction::ROLE_CREATED, AuditAction::ROLE_RENAMED, AuditAction::ROLE_REMOVED], true)
        ) {
            $subject = new AuditSubject(RoleId::generate());
        }

        $at = new DateTimeImmutable('2026-11-09T12:00:00.000000+00:00');
        $correlation = new AuditCorrelation(MessageId::generate(), new AgentOperationKey(
            new AgentOperationScope('audit-test', 'user', 'origin'),
            AgentOperationId::generate()
        ));
        if ($hydrate) {
            return AuditEvidence::fromArray([
                'id'          => AuditEvidenceId::generate()->toString(),
                'actor'       => ['kind' => 'system', 'id' => SystemId::generate()->toString()],
                'subject'     => $subject->toArray(),
                'action'      => $action->value,
                'occurred_at' => $at->format('Y-m-d\TH:i:s.uP'),
                'correlation' => $correlation->toArray(),
                'facts'       => $facts
            ]);
        }

        if ($subject->getId() instanceof RoleId) {
            $outcome = AuditRoleRevisions::fromArray($facts);
        } else {
            $outcome = AuditAgentOutcome::fromArray($facts);
        }

        return AuditEvidence::record(SystemId::generate(), $subject, $action, $at, $outcome, $correlation);
    }
}
