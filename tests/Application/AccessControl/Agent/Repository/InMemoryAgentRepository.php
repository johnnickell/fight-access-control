<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Repository;

use Closure;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryAuthorizationReferenceState;
use Throwable;

final class InMemoryAgentRepository implements AgentRepository
{
    /** @var list<Agent> */
    private array $agents = [];

    private readonly InMemoryAuthorizationReferenceState $authorizationReferences;

    private int $permissionAssignmentReplacementCalls = 0;

    public function __construct(
        private readonly ?InMemoryUnitOfWork $unitOfWork = null,
        private readonly bool $replacePermissionAssignmentsSucceeds = true,
        ?InMemoryAuthorizationReferenceState $authorizationReferences = null,
        private readonly ?Closure $beforeReplacePermissionAssignments = null,
        private readonly ?Throwable $replacePermissionAssignmentsFailure = null
    ) {
        $resolvedAuthorizationReferences = $authorizationReferences ?? new InMemoryAuthorizationReferenceState();
        if (
            $unitOfWork instanceof InMemoryUnitOfWork
            && !$authorizationReferences instanceof InMemoryAuthorizationReferenceState
        ) {
            $resolvedAuthorizationReferences = $unitOfWork->authorizationReferenceState();
        }

        $this->authorizationReferences = $resolvedAuthorizationReferences;
    }

    public function add(Agent $agent): void
    {
        $this->agents[] = $agent;
        $this->authorizationReferences->retainAgent($agent);
        $this->unitOfWork?->onRollback(function () use ($agent): void {
            array_pop($this->agents);
            $this->authorizationReferences->removeAgent($agent);
        });
    }

    public function getById(AgentId $id): ?Agent
    {
        foreach ($this->agents as $agent) {
            if ($agent->getId()->equals($id)) {
                return $agent;
            }
        }

        return null;
    }

    public function getByCredentialId(AgentCredentialId $credentialId): ?Agent
    {
        foreach ($this->agents as $agent) {
            if ($agent->getCredentialId()->equals($credentialId)) {
                return $agent;
            }
        }

        return null;
    }

    public function getAll(Pagination $pagination): ResultSet
    {
        $records = ArrayList::of(Agent::class)->replace(array_slice(
            $this->agents,
            $pagination->offset(),
            $pagination->limit()
        ));

        return new ResultSet(
            $pagination->page(),
            $pagination->perPage(),
            count($this->agents),
            $records
        );
    }

    public function replace(Agent $expected, Agent $replacement): bool
    {
        foreach ($this->agents as $index => $agent) {
            if (
                $agent !== $expected
                || !$replacement->getId()->equals($expected->getId())
                || $replacement->getPermissionAssignmentRevision() !== $expected->getPermissionAssignmentRevision()
                || !$this->permissionMembershipIsSame($expected, $replacement)
            ) {
                continue;
            }

            $this->agents[$index] = $replacement;
            $this->authorizationReferences->retainAgent($replacement);
            $this->unitOfWork?->onRollback(function () use ($expected, $index, $replacement): void {
                if (($this->agents[$index] ?? null) === $replacement) {
                    $this->agents[$index] = $expected;
                    $this->authorizationReferences->retainAgent($expected);
                }
            });

            return true;
        }

        return false;
    }

    public function replacePermissionAssignments(Agent $expected, Agent $replacement): bool
    {
        ++$this->permissionAssignmentReplacementCalls;

        if ($this->replacePermissionAssignmentsFailure instanceof Throwable) {
            throw $this->replacePermissionAssignmentsFailure;
        }

        $this->authorizationReferences->holdThroughCompletion();
        $this->beforeReplacePermissionAssignments?->__invoke();
        if (
            !$this->replacePermissionAssignmentsSucceeds
            || !$this->authorizationReferences->permissionsAreAuthoritative($replacement->getPermissionIds())
        ) {
            return false;
        }

        foreach ($this->agents as $index => $agent) {
            if ($agent !== $expected || !$this->permissionAssignmentReplacementIsValid($expected, $replacement)) {
                continue;
            }

            $this->agents[$index] = $replacement;
            $this->authorizationReferences->retainAgent($replacement);
            $this->unitOfWork?->onRollback(function () use ($expected, $index, $replacement): void {
                if (($this->agents[$index] ?? null) === $replacement) {
                    $this->agents[$index] = $expected;
                    $this->authorizationReferences->retainAgent($expected);
                }
            });

            return true;
        }

        return false;
    }

    /** @return list<Agent> */
    public function all(): array
    {
        return $this->agents;
    }

    public function permissionAssignmentReplacementCalls(): int
    {
        return $this->permissionAssignmentReplacementCalls;
    }

    private function permissionAssignmentReplacementIsValid(Agent $expected, Agent $replacement): bool
    {
        $permissionKeys = $this->permissionKeys($replacement);
        $replacementEnvelope = $replacement->getEncryptedHmacSharedSecretEnvelope();
        $expectedEnvelope = $expected->getEncryptedHmacSharedSecretEnvelope();
        $credentialEnvelopeIsSame = $replacementEnvelope === $expectedEnvelope;
        $replacementRevision = $replacement->getPermissionAssignmentRevision();
        $expectedRevision = $expected->getPermissionAssignmentRevision();
        $revisionIsNext = $replacementRevision === $expectedRevision + 1;

        return $replacement->getId()->equals($expected->getId())
            && $replacement->getName()->equals($expected->getName())
            && $replacement->getState() === $expected->getState()
            && $replacement->getCredentialId()->equals($expected->getCredentialId())
            && $replacement->getCredentialRevision() === $expected->getCredentialRevision()
            && $credentialEnvelopeIsSame
            && count($permissionKeys) === count(array_unique($permissionKeys))
            && !$this->permissionMembershipIsSame($expected, $replacement)
            && $revisionIsNext
            && $replacement->getCreatedAt() == $expected->getCreatedAt();
    }

    /** @return list<string> */
    private function permissionKeys(Agent $agent): array
    {
        return array_map(
            static fn($permissionId): string => $permissionId->toString(),
            $agent->getPermissionIds()
        );
    }

    private function permissionMembershipIsSame(Agent $expected, Agent $replacement): bool
    {
        $expectedKeys = $this->permissionKeys($expected);
        $replacementKeys = $this->permissionKeys($replacement);
        sort($expectedKeys);
        sort($replacementKeys);

        return $expectedKeys === $replacementKeys;
    }
}
