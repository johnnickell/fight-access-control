<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Agent::class)]
#[CoversClass(AgentRotationRequest::class)]
final class AgentRecoverableRotationTest extends TestCase
{
    public function test_rotation_preserves_agent_identity_and_permissions_with_exact_successor_revision(): void
    {
        $at = new DateTimeImmutable('2026-09-27T12:00:00Z');
        $permission = PermissionId::generate();
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Agent'),
            AgentCredentialId::generate(),
            'original-envelope',
            $at
        )->grantPermission($permission, $at);
        $credential = AgentCredentialId::generate();
        $next = $agent->rotateRecoverableCredential(
            $agent->getCredentialId(),
            0,
            $credential,
            'successor-envelope',
            $at
        );
        self::assertTrue($agent->canReplaceCredentialWith($next));
        self::assertSame($agent->getId(), $next->getId());
        self::assertSame($agent->getCreatedAt(), $next->getCreatedAt());
        self::assertSame([$permission], $next->getPermissionIds());
        self::assertSame(2, $next->getPermissionAssignmentRevision());
        self::assertSame($credential, $next->getCredentialId());
        self::assertSame(1, $next->getCredentialRevision());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSuccessors(): iterable
    {
        foreach (['revoked', 'credential', 'revision', 'same successor', 'backdated'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidSuccessors')]
    public function test_invalid_predecessors_and_successors_fail_closed(string $case): void
    {
        $at = new DateTimeImmutable('2026-09-27T12:00:00Z');
        $agent = Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Agent'),
            AgentCredentialId::generate(),
            'original-envelope',
            $at
        );
        if ($case === 'revoked') {
            $agent = $agent->revoke($at);
        }

        $this->expectException(AgentCredentialException::class);
        $agent->rotateRecoverableCredential(
            $case === 'credential' ? AgentCredentialId::generate() : $agent->getCredentialId(),
            $case === 'revision' ? 1 : 0,
            $case === 'same successor' ? $agent->getCredentialId() : AgentCredentialId::generate(),
            'replacement-envelope',
            $case === 'backdated' ? $at->modify('-1 second') : $at
        );
    }

    public function test_request_binds_original_target_predecessor_and_destination(): void
    {
        $agent = AgentId::generate();
        $credential = AgentCredentialId::generate();
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 2);
        $request = new AgentRotationRequest($agent, $credential, 4, $destination);
        self::assertSame($agent, $request->getAgentId());
        self::assertSame($credential, $request->getExpectedCredentialId());
        self::assertSame(4, $request->getExpectedCredentialRevision());
        self::assertSame($destination, $request->getDestination());
        self::assertSame([
            'rotate', $agent->toString(), $credential->toString(), 4, $destination->getId()->toString(), 2
        ], json_decode($request->canonicalize(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($request->canonicalize(), new AgentRotationRequest(
            AgentId::fromString($agent->toString()),
            AgentCredentialId::fromString($credential->toString()),
            4,
            $destination
        )->canonicalize());

        foreach ([-1, PHP_INT_MAX] as $revision) {
            try {
                new AgentRotationRequest($agent, $credential, $revision, $destination);
                self::fail('Invalid revisions must reject.');
            } catch (AgentOperationRejectedException $failure) {
                self::assertSame(AgentOperationFailure::INVALID_REQUEST, $failure->getReason());
            }
        }
    }
}
