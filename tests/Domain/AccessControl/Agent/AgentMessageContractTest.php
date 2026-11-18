<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RevokePermissionFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionGrantedToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionRevokedFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Payload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(AgentUpdateInitiator::class)]
#[CoversClass(GrantPermissionToAgent::class)]
#[CoversClass(ReplaceAgentPermissions::class)]
#[CoversClass(RevokePermissionFromAgent::class)]
#[CoversClass(UpdateAgent::class)]
#[CoversClass(AgentNameChanged::class)]
#[CoversClass(AgentPermissionsReplaced::class)]
#[CoversClass(PermissionGrantedToAgent::class)]
#[CoversClass(PermissionRevokedFromAgent::class)]
#[CoversClass(GetAgentById::class)]
#[CoversClass(GetAgentProfile::class)]
#[CoversClass(ListAgents::class)]
final class AgentMessageContractTest extends TestCase
{
    /** @return iterable<string, array{class-string<Payload>, array<string, mixed>}> */
    public static function messages(): iterable
    {
        $identity = [
            'actor_id' => '018f0000-0000-7000-8000-000000000101',
            'agent_id' => '018f0000-0000-7000-8000-000000000201'
        ];
        $permission = '018f0000-0000-7000-8000-000000000301';
        $membership = [...$identity, 'permission_id' => $permission];
        $initiator = ['type' => 'user', 'id' => $identity['actor_id']];
        $named = ['initiator' => $initiator, 'agent_id' => $identity['agent_id'], 'name' => 'New name'];
        $at = '2030-01-01T00:00:00+00:00';
        yield 'grant' => [GrantPermissionToAgent::class, $membership];
        yield 'revoke' => [RevokePermissionFromAgent::class, $membership];
        yield 'replace' => [ReplaceAgentPermissions::class, [
            ...$identity, 'expected_permission_assignment_revision' => 1, 'permission_ids' => [$permission]
        ]];
        yield 'update' => [UpdateAgent::class, $named];
        yield 'granted' => [PermissionGrantedToAgent::class, [...$membership, 'granted_at' => $at]];
        yield 'revoked' => [PermissionRevokedFromAgent::class, [...$membership, 'revoked_at' => $at]];
        yield 'replaced' => [AgentPermissionsReplaced::class, [
            ...$identity, 'permission_ids' => [$permission], 'permission_assignment_revision' => 2, 'replaced_at' => $at
        ]];
        yield 'renamed' => [AgentNameChanged::class, [...$named, 'changed_at' => '2030-01-01T00:00:00.123456+00:00']];
        yield 'get' => [GetAgentById::class, ['agent_id' => $identity['agent_id']]];
        yield 'profile' => [GetAgentProfile::class, ['agent_id' => $identity['agent_id']]];
        yield 'list' => [ListAgents::class, ['page' => 1, 'per_page' => 20, 'orderings' => ['name' => 'ASC']]];
    }

    /**
     * @param class-string<Payload> $class
     * @param array<string, mixed> $data
     */
    #[DataProvider('messages')]
    public function test_valid_current_round_trip_and_missing_fields(string $class, array $data): void
    {
        self::assertSame($data, $class::fromArray($data)->toArray());
        foreach (array_keys($data) as $key) {
            $missing = $data;
            unset($missing[$key]);
            $this->assertRejected($class, $missing);
        }
    }

    /**
     * @param class-string<Payload> $class
     * @param array<string, mixed> $data
     */
    #[DataProvider('messages')]
    public function test_decoded_types_are_not_coerced(string $class, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $invalidValues = [null, 1, true, [], new readonly class ($value) implements Stringable {
                    public function __construct(private string $value)
                    {
                    }

                    public function __toString(): string
                    {
                        return $this->value;
                    }
                }];
            } elseif (is_int($value)) {
                $invalidValues = [null, (string) $value, (float) $value, true, []];
            } else {
                $invalidValues = [null, true, 1, 'not-an-array'];
            }

            foreach ($invalidValues as $invalid) {
                $this->assertRejected($class, array_replace($data, [$key => $invalid]));
            }
        }
    }

    /**
     * @param class-string<Payload> $class
     * @param array<string, mixed> $data
     */
    #[DataProvider('messages')]
    public function test_timestamps_require_real_explicit_instants(string $class, array $data): void
    {
        foreach (array_keys($data) as $key) {
            if (!str_ends_with($key, '_at')) {
                continue;
            }

            foreach (
                ['', 'now', 'tomorrow', '2030-01-01', '2030-02-30T00:00:00+00:00',
                '2030-01-01T24:00:00+00:00', '2030-01-01T00:00:00+99:99'] as $invalid
            ) {
                $this->assertRejected($class, array_replace($data, [$key => $invalid]));
            }
        }

        self::addToAssertionCount(1);
    }

    /**
     * @param class-string<Payload> $class
     * @param array<string, mixed> $data
     */
    #[DataProvider('messages')]
    public function test_list_revision_and_nested_provenance_boundaries(string $class, array $data): void
    {
        if (isset($data['permission_ids'])) {
            foreach (
                ['018f0000-0000-7000-8000-000000000301', [2 => '018f0000-0000-7000-8000-000000000301'],
                [null], [1], [[]]] as $invalid
            ) {
                $this->assertRejected($class, array_replace($data, ['permission_ids' => $invalid]));
            }
        }

        foreach (['expected_permission_assignment_revision', 'permission_assignment_revision'] as $key) {
            if (isset($data[$key])) {
                $this->assertRejected($class, array_replace($data, [$key => 0]));
                $this->assertRejected($class, array_replace($data, [$key => -1]));
            }
        }

        if (isset($data['initiator'])) {
            foreach (
                [[], ['type' => 'user'], ['type' => true, 'id' => 'bad'],
                ['type' => 'agent', 'id' => 1]] as $invalid
            ) {
                $this->assertRejected($class, array_replace($data, ['initiator' => $invalid]));
            }
        }

        if ($class === ListAgents::class) {
            foreach ([[1 => 'asc'], ['name' => 1]] as $invalid) {
                $this->assertRejected($class, array_replace($data, ['orderings' => $invalid]));
            }
        }

        self::addToAssertionCount(1);
    }

    /**
     * @param class-string<Payload> $class
     * @param array<string, mixed> $data
     */
    private function assertRejected(string $class, array $data): void
    {
        try {
            $class::fromArray($data);
            self::fail('Malformed '.$class.' input was accepted.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
