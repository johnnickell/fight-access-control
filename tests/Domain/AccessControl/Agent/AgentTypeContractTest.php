<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentReadException;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(Agent::class)]
#[CoversClass(AgentView::class)]
#[CoversClass(ReplaceAgentPermissions::class)]
#[CoversClass(AgentPermissionsReplaced::class)]
final class AgentTypeContractTest extends TestCase
{
    public function test_factories_and_every_successor_preserve_the_extending_aggregate(): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $agent = ExtensibleAgent::provision(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentCredentialId::generate(),
            'envelope',
            $at
        );
        self::assertInstanceOf(ExtensibleAgent::class, $agent);
        $permission = PermissionId::generate();
        $granted = $agent->grantPermission($permission, $at);
        $successors = [
            $agent->rename(AgentName::fromString('Renamed'), $at),
            $granted,
            $granted->revokePermission($permission, $at),
            $agent->replacePermissions([$permission], 1, $at),
            $agent->rotateRecoverableCredential(
                $agent->getCredentialId(),
                0,
                AgentCredentialId::generate(),
                'next',
                $at
            ),
            $agent->revoke($at),
            ExtensibleAgent::reconstitute(
                $agent->getId(),
                $agent->getName(),
                AgentState::ACTIVE,
                $agent->getCredentialId(),
                0,
                'envelope',
                [],
                1,
                $at,
                $at
            )
        ];
        foreach ($successors as $successor) {
            self::assertInstanceOf(ExtensibleAgent::class, $successor);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function permissionTransitions(): iterable
    {
        yield 'grant' => ['grant'];
        yield 'revoke' => ['revoke'];
        yield 'replace' => ['replace'];
    }

    #[DataProvider('permissionTransitions')]
    public function test_revision_exhaustion_rejects_changed_membership_without_breaking_no_ops(string $kind): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $existing = PermissionId::generate();
        $new = PermissionId::generate();
        $agent = Agent::reconstitute(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentState::ACTIVE,
            AgentCredentialId::generate(),
            0,
            'envelope',
            [$existing],
            PHP_INT_MAX,
            $at,
            $at
        );
        self::assertSame($agent, $agent->grantPermission($existing, $at));
        self::assertSame($agent, $agent->revokePermission($new, $at));
        self::assertSame($agent, $agent->replacePermissions([$existing], PHP_INT_MAX, $at));
        $this->expectException(AgentPermissionAssignmentException::class);
        match ($kind) {
            'grant' => $agent->grantPermission($new, $at),
            'revoke' => $agent->revokePermission($existing, $at),
            default => $agent->replacePermissions([], PHP_INT_MAX, $at)
        };
    }

    /** @return iterable<string, array{bool, string, int}> */
    public static function invalidReplacementMembers(): iterable
    {
        foreach ([false, true] as $generator) {
            $kind = $generator ? 'generator' : 'array';
            yield $kind.' wrong identity only' => [$generator, 'wrong only', 1];
            yield $kind.' valid then same-string wrong identity' => [$generator, 'wrong last', 1];
            yield $kind.' same-string wrong identity then valid' => [$generator, 'wrong first', 1];
            yield $kind.' duplicate cannot hide behind no-op' => [$generator, 'no-op', 2];
            yield $kind.' duplicate cannot hide behind exhausted no-op' => [$generator, 'no-op', PHP_INT_MAX];
        }
    }

    #[DataProvider('invalidReplacementMembers')]
    public function test_replacement_rejects_wrong_purpose_members_before_deduplication_or_no_op(
        bool $generator,
        string $case,
        int $revision
    ): void {
        $at = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $permission = PermissionId::generate();
        $wrong = AgentId::fromString($permission->toString());
        $agent = Agent::reconstitute(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentState::ACTIVE,
            AgentCredentialId::generate(),
            0,
            'envelope',
            $case === 'no-op' ? [$permission] : [],
            $revision,
            $at,
            $at
        );
        $members = match ($case) {
            'wrong only' => [$wrong],
            'wrong first' => [$wrong, $permission],
            default => [$permission, $wrong]
        };
        $input = $generator ? (static function () use ($members): iterable {
            yield from $members;
        })() : $members;

        $this->expectException(AgentPermissionAssignmentException::class);
        $this->expectExceptionMessage('Agent Permission identities must be PermissionId values.');
        new ReflectionMethod(Agent::class, 'replacePermissions')->invoke($agent, $input, $revision, $at);
    }

    public function test_valid_generator_preserves_first_occurrences_subtype_and_no_op_authority(): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $later = $at->modify('+1 hour');
        $agent = ExtensibleAgent::provision(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentCredentialId::generate(),
            'envelope',
            $at
        );
        $first = PermissionId::generate();
        $second = PermissionId::generate();
        $sameFirst = PermissionId::fromString($first->toString());
        $input = (static function () use ($first, $second, $sameFirst): iterable {
            yield 'repeated key' => $first;
            yield 'repeated key' => $second;
            yield $sameFirst;
        })();
        $next = $agent->replacePermissions($input, 1, $later);
        self::assertInstanceOf(ExtensibleAgent::class, $next);
        self::assertSame([$first, $second], $next->getPermissionIds());
        self::assertSame(2, $next->getPermissionAssignmentRevision());
        self::assertSame($later, $next->getUpdatedAt());
        self::assertTrue($next->hasPermission($sameFirst));
        self::assertSame([], $agent->getPermissionIds());
        self::assertSame($agent->getCredentialId(), $next->getCredentialId());
        self::assertSame($agent->getCredentialRevision(), $next->getCredentialRevision());
        self::assertSame($agent->getEncryptedHmacSharedSecretEnvelope(), $next->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame($agent->getCreatedAt(), $next->getCreatedAt());
        $sameSet = (static function () use ($second, $sameFirst): iterable {
            yield $second;
            yield $sameFirst;
            yield $second;
        })();
        self::assertSame($next, $next->replacePermissions($sameSet, 2, $later->modify('+1 hour')));
        self::assertSame([$first, $second], $next->getPermissionIds());
    }

    /** @return iterable<string, array{bool, int}> */
    public static function generatorRevisionCases(): iterable
    {
        yield 'current exhausted no-op' => [false, PHP_INT_MAX];
        yield 'stale exhausted no-op' => [false, PHP_INT_MAX - 1];
        yield 'current exhausted change' => [true, PHP_INT_MAX];
        yield 'stale exhausted change' => [true, PHP_INT_MAX - 1];
    }

    #[DataProvider('generatorRevisionCases')]
    public function test_generator_revision_checks_keep_stale_no_op_and_exhaustion_order(
        bool $changed,
        int $expectedRevision
    ): void {
        $at = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $permission = PermissionId::generate();
        $agent = Agent::reconstitute(
            AgentId::generate(),
            AgentName::fromString('Worker'),
            AgentState::ACTIVE,
            AgentCredentialId::generate(),
            0,
            'envelope',
            [$permission],
            PHP_INT_MAX,
            $at,
            $at
        );
        $input = (static function () use ($changed, $permission): Generator {
            yield $changed ? PermissionId::generate() : $permission;
            if (!$changed) {
                yield PermissionId::fromString($permission->toString());
            }
        })();
        try {
            if ($expectedRevision !== PHP_INT_MAX || $changed) {
                $this->expectException(AgentPermissionAssignmentException::class);
                $message = 'The Agent Permission assignment revision is exhausted.';
                if ($expectedRevision !== PHP_INT_MAX) {
                    $message = 'The Agent Permission assignment revision is stale.';
                }

                $this->expectExceptionMessage($message);
            }

            self::assertSame($agent, $agent->replacePermissions($input, $expectedRevision, $at->modify('+1 hour')));
        } finally {
            self::assertFalse($input->valid());
            self::assertSame([$permission], $agent->getPermissionIds());
            self::assertSame(PHP_INT_MAX, $agent->getPermissionAssignmentRevision());
            self::assertSame($at, $agent->getUpdatedAt());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function collectionConstructors(): iterable
    {
        yield 'view' => ['view'];
        yield 'command' => ['command'];
        yield 'event' => ['event'];
    }

    #[DataProvider('collectionConstructors')]
    public function test_direct_collection_constructors_reject_wrong_element_types(string $kind): void
    {
        $at = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $agent = AgentId::generate();
        $actor = UserId::generate();
        [$class, $arguments] = match ($kind) {
            'view' => [AgentView::class, [$agent, AgentName::fromString('Worker'), AgentState::ACTIVE,
                AgentCredentialId::generate(), 0, 1, ['not a snapshot']]],
            'command' => [ReplaceAgentPermissions::class, [$actor, $agent, 1, ['not an identity']]],
            default => [AgentPermissionsReplaced::class, [$actor, $agent, ['not an identity'], 2, $at]]
        };
        $this->expectException(DomainException::class);
        new ReflectionClass($class)->newInstanceArgs($arguments);
    }

    /** @return iterable<string, array{int, int, array<array-key, PrincipalPermission>}> */
    public static function invalidViews(): iterable
    {
        yield 'negative credential revision' => [-1, 1, []];
        yield 'zero assignment revision' => [0, 0, []];
        yield 'negative assignment revision' => [0, -1, []];
        yield 'non-list' => [0, 1, [2 => new PrincipalPermission(
            PermissionId::generate(),
            PermissionName::fromString('READ')
        )]];
    }

    /** @param array<array-key, PrincipalPermission> $permissions */
    #[DataProvider('invalidViews')]
    public function test_safe_views_cannot_claim_invalid_revisions_or_list_shapes(
        int $credentialRevision,
        int $assignmentRevision,
        array $permissions
    ): void {
        $this->expectException(AgentReadException::class);
        new ReflectionClass(AgentView::class)->newInstanceArgs([
            AgentId::generate(), AgentName::fromString('Worker'), AgentState::ACTIVE, AgentCredentialId::generate(),
            $credentialRevision, $assignmentRevision, $permissions
        ]);
    }
}
