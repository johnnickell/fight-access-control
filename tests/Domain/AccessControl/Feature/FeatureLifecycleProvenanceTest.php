<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Feature;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\CreateFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateFeature::class)]
#[CoversClass(RemoveFeature::class)]
final class FeatureLifecycleProvenanceTest extends TestCase
{
    public function test_all_actor_kinds_survive_canonical_message_round_trip_without_authority_inference(): void
    {
        $actors = [UserId::generate(), AgentId::generate(), SystemId::generate(), AnonymousId::generate(), null];
        foreach ($actors as $actor) {
            foreach (
                [
                new CreateFeature(FeatureName::fromString('dashboard'), PermissionId::generate(), $actor),
                new RemoveFeature(FeatureId::generate(), 9, $actor)
                ] as $command
            ) {
                $message = CommandMessage::create($command);
                $restored = CommandMessage::arrayDeserialize($message->arraySerialize())->payload();
                self::assertInstanceOf($command::class, $restored);
                self::assertEquals($actor, $restored->getActorId());
                self::assertSame($command->toArray(), $restored->toArray());
            }
        }
    }

    public function test_disabled_input_can_omit_provenance_but_partial_or_malformed_provenance_rejects(): void
    {
        foreach (
            [
            new CreateFeature(FeatureName::fromString('dashboard'), PermissionId::generate()),
            new RemoveFeature(FeatureId::generate(), 9)
            ] as $command
        ) {
            $payload = $command->toArray();
            unset($payload['actor_kind'], $payload['actor_id']);
            self::assertNull($command::fromArray($payload)->getActorId());
            foreach (
                [
                ['actor_kind' => 'user'],
                ['actor_id' => UserId::generate()->toString()],
                ['actor_kind' => false, 'actor_id' => 'invalid'],
                ['actor_kind' => 'worker', 'actor_id' => UserId::generate()->toString()],
                ['actor_kind' => 'system', 'actor_id' => UserId::generate()->toString()],
                ['actor_kind' => 'anonymous', 'actor_id' => 'invalid']
                ] as $invalid
            ) {
                try {
                    $command::fromArray($payload + $invalid);
                    self::fail('Malformed provenance must not become missing or inferred attribution.');
                } catch (DomainException) {
                    self::assertNull($command->getActorId());
                }
            }
        }
    }
}
