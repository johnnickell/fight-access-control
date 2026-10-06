<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentUpdateException;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(Agent::class)]
#[CoversClass(AgentUpdateInitiator::class)]
#[CoversClass(UpdateAgent::class)]
#[CoversClass(AgentNameChanged::class)]
#[CoversClass(AgentUpdateException::class)]
final class AgentRenameTest extends TestCase
{
    public function test_rename_changes_only_name_and_updated_time_without_mutating_predecessor(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $changedAt = $at->modify('+1 hour');
        $permission = PermissionId::generate();
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Original'),
            AgentCredentialId::generate(),
            'test-envelope',
            $at
        )->grantPermission($permission, $at);
        $renamed = $agent->rename(AgentName::fromString('  New name  '), $changedAt);
        self::assertSame('Original', $agent->getName()->toString());
        self::assertSame('New name', $renamed->getName()->toString());
        self::assertSame($changedAt, $renamed->getUpdatedAt());
        self::assertSame($agent->getId(), $renamed->getId());
        self::assertSame($agent->getState(), $renamed->getState());
        self::assertSame($agent->getCredentialId(), $renamed->getCredentialId());
        self::assertSame($agent->getCredentialRevision(), $renamed->getCredentialRevision());
        self::assertSame(
            $agent->getEncryptedHmacSharedSecretEnvelope(),
            $renamed->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame([$permission], $renamed->getPermissionIds());
        self::assertSame($agent->getPermissionAssignmentRevision(), $renamed->getPermissionAssignmentRevision());
        self::assertSame($at, $renamed->getCreatedAt());
        self::assertSame($renamed, $renamed->rename(AgentName::fromString('New name'), $changedAt->modify('+1 hour')));
        self::assertFalse($agent->canReplaceCredentialWith($renamed));
    }

    public function test_revoked_targets_reject_even_unchanged_names_and_real_updates_reject_backdating(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Original'),
            AgentCredentialId::generate(),
            'test-envelope',
            $at
        );
        foreach ([$agent->revoke($at), $agent] as $target) {
            foreach (['Original', 'Changed'] as $name) {
                if ($target === $agent && $name === 'Original') {
                    self::assertSame($agent, $agent->rename(AgentName::fromString($name), $at->modify('-1 second')));
                    continue;
                }

                try {
                    $target->rename(AgentName::fromString($name), $at->modify('-1 second'));
                    self::fail('Expected an inactive target or backdated real update to reject.');
                } catch (AgentUpdateException) {
                    self::assertSame('Original', $target->getName()->toString());
                    self::assertSame($at, $target->getUpdatedAt());
                }
            }
        }
    }

    public function test_typed_provenance_and_messages_round_trip_for_each_initiator_type(): void
    {
        $id = UserId::generate()->toString();
        $agentId = AgentId::generate();
        $changedAt = new DateTimeImmutable('2026-01-01T12:30:00.123456+02:00');
        $user = new AgentUpdateInitiator(UserId::fromString($id));
        $machine = new AgentUpdateInitiator(AgentId::fromString($id));
        self::assertFalse($user->equals($machine));
        foreach ([$user, $machine] as $initiator) {
            $type = $initiator === $user ? AuthenticatedPrincipalType::USER : AuthenticatedPrincipalType::AGENT;
            self::assertSame($type, $initiator->getType());
            self::assertSame($id, $initiator->getId()->toString());
            self::assertSame($type->value.':'.$id, $initiator->toString());
            self::assertTrue($initiator->equals(AgentUpdateInitiator::fromArray($initiator->toArray())));
            self::assertTrue($initiator->equals(AgentUpdateInitiator::fromString($initiator->toString())));
            $command = new UpdateAgent($initiator, $agentId, '  New name  ');
            self::assertSame($initiator, $command->getInitiator());
            self::assertSame($agentId, $command->getAgentId());
            self::assertSame('  New name  ', $command->getName());
            self::assertEquals($command, UpdateAgent::fromArray($command->toArray()));
            $event = new AgentNameChanged($initiator, $agentId, AgentName::fromString('New name'), $changedAt);
            self::assertSame($initiator, $event->getInitiator());
            self::assertSame($agentId, $event->getAgentId());
            self::assertSame('New name', $event->getName()->toString());
            self::assertSame($changedAt, $event->getChangedAt());
            self::assertEquals($event, AgentNameChanged::fromArray($event->toArray()));
            self::assertSame('2026-01-01T12:30:00.123456+02:00', $event->toArray()['changed_at']);
        }
    }

    public function test_missing_required_message_and_provenance_fields_reject(): void
    {
        $initiator = new AgentUpdateInitiator(UserId::generate());
        $agentId = AgentId::generate();
        $command = new UpdateAgent($initiator, $agentId, 'New name');
        $event = new AgentNameChanged($initiator, $agentId, AgentName::fromString('New name'), new DateTimeImmutable());
        foreach ([$initiator, $command, $event] as $value) {
            foreach (array_keys($value->toArray()) as $key) {
                $data = $value->toArray();
                unset($data[$key]);
                try {
                    $value::fromArray($data);
                    self::fail('Missing required data was accepted.');
                } catch (DomainException $failure) {
                    self::assertStringContainsString($key, $failure->getMessage());
                }
            }
        }
    }

    public function test_unqualified_initiator_rejects_without_inferring_a_type(): void
    {
        $this->expectException(DomainException::class);
        AgentUpdateInitiator::fromString(UserId::generate()->toString());
    }

    public function test_unknown_initiator_type_rejects_without_inferring_user_or_agent(): void
    {
        $this->expectException(ValueError::class);
        AgentUpdateInitiator::fromArray(['type' => 'service', 'id' => AgentId::generate()->toString()]);
    }
}
