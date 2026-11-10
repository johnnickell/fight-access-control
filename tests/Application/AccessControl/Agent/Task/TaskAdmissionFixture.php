<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Task;

use Closure;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskCreationScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskReadScope;
use Fight\AccessControl\Application\AccessControl\Agent\Task\AgentTaskUpdateScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Domain\Mcp\Task\TaskDefinition;
use Fight\Common\Domain\Mcp\Task\TaskId;
use Fight\Common\Domain\Value\Basic\StrictJson;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\InMemoryAgentRequestNonceConsumer;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use RuntimeException;

final class TaskAdmissionFixture implements AgentTaskCreationScope, AgentTaskUpdateScope, AgentTaskReadScope
{
    public const string ID = '0123456789abcdef0123456789abcdef';

    public bool $create = true;

    public bool $update = true;

    public bool $read = true;

    public bool $fault = false;

    public string $comparison = 'reader-visibility-v1';

    public ?Closure $afterComparison = null;

    public ?Closure $beforeUpdate = null;

    public int $checks = 0;

    public readonly Agent $agent;

    public readonly InMemoryAgentRepository $agentRepository;

    public readonly InMemoryPermissionRepository $permissionRepository;

    public readonly InMemoryAgentRequestNonceConsumer $nonce;

    public readonly InMemoryUnitOfWork $unitOfWork;

    public readonly CurrentAgentPrincipalProvider $provider;

    public readonly SignedAgentRequest $request;

    /** @phpstan-param list<string> $grants */
    public function __construct(
        array $grants = ['TASK_CREATE', 'TASK_UPDATE', 'TASK_READ', 'TASK_CONTINUE'],
        bool $validSignature = true,
        bool $complete = true,
        int $identity = 201
    ) {
        $this->agentRepository = new InMemoryAgentRepository();
        $this->permissionRepository = new InMemoryPermissionRepository();
        $agent = Agent::provision(
            AgentId::fromString(sprintf('018f0000-0000-7000-8000-%012d', $identity)),
            AgentName::fromString('Task fixture'),
            AgentCredentialId::fromString(sprintf('018f0000-0000-7000-8000-%012d', $identity + 1)),
            'encrypted:fixture',
            self::now()
        );
        foreach ($grants as $index => $grant) {
            $permission = Permission::defineManaged(
                PermissionId::fromString(sprintf('018f0000-0000-7000-8000-%012d', $index + 300)),
                PermissionName::fromString($grant),
                PermissionTier::ADMIN_SAFE,
                self::now()
            );
            $agent = $agent->grantPermission($permission->getId(), self::now());
            if ($complete) {
                $this->permissionRepository->add($permission);
            }
        }

        $this->agent = $agent;
        $this->agentRepository->add($agent);
        $this->unitOfWork = new InMemoryUnitOfWork();
        $this->nonce = new InMemoryAgentRequestNonceConsumer($this->agentRepository, $this->unitOfWork);
        $validRequest = new SignedAgentRequest(
            'POST',
            'task.example',
            '/task',
            '',
            self::now(),
            'fixture-nonce',
            $agent->getCredentialId(),
            'HMAC-SHA256',
            'fixture-signature',
            null,
            ''
        );
        $request = $validRequest;
        if (!$validSignature) {
            $request = new SignedAgentRequest(
                'POST',
                'task.example',
                '/task',
                '',
                self::now(),
                'fixture-nonce',
                $agent->getCredentialId(),
                'HMAC-SHA256',
                'invalid',
                null,
                ''
            );
        }

        $this->request = $request;
        $this->provider = new CurrentAgentPrincipalProvider(
            $this->agentRepository,
            $this->permissionRepository,
            new FixedHmacSharedSecretDecipher('encrypted:'),
            new FixedHmacSignedAgentRequestVerifier($validRequest, 'fixture'),
            new FixedClock(self::now()),
            $this->nonce,
            $this->unitOfWork
        );
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10T12:00:00+00:00');
    }

    public static function definition(string $scope = 'scope', ?StrictJson $operation = null): TaskDefinition
    {
        $operation ??= StrictJson::fromObject([
            'kind'          => 'tool',
            'name'          => 'example.operation',
            'revision'      => 'original-v1',
            'arguments'     => (object) ['private' => 'original'],
            'output_schema' => (object) [
                'type'                 => 'object',
                'properties'           => (object) ['ok' => (object) ['type' => 'boolean']],
                'required'             => ['ok'],
                'additionalProperties' => false
            ]
        ]);

        return TaskDefinition::create(TaskId::fromString(self::ID), $scope, 'producer', $operation);
    }

    public function authority(string $permission): AgentTaskAuthority
    {
        return AgentTaskAuthority::fromRequest(
            $this->provider,
            $this->request,
            'task-fixture',
            [PermissionName::fromString($permission)]
        );
    }

    public function allowsCreation(AuthenticatedAgentPrincipal $principal, TaskDefinition $definition): bool
    {
        $this->check();

        return $this->create && $principal->getAgentId()->equals($this->agent->getId())
            && $definition->scope() === 'scope' && $definition->producer() === 'producer';
    }

    public function allowsUpdate(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $producer
    ): bool {
        $this->check();
        if ($this->beforeUpdate !== null) {
            ($this->beforeUpdate)();
        }

        return $this->update && $principal->getAgentId()->equals($this->agent->getId())
            && $scope === 'scope' && $id->toString() === self::ID && $producer === 'producer';
    }

    public function allowsRead(AuthenticatedAgentPrincipal $principal, string $scope, TaskId $id): bool
    {
        $this->check();

        return $this->read && $principal->getAgentId()->equals($this->agent->getId()) && $scope === 'scope';
    }

    public function comparisonScope(AuthenticatedAgentPrincipal $principal, string $scope, TaskId $id): string
    {
        $this->check();
        if ($this->afterComparison !== null) {
            ($this->afterComparison)();
        }

        return $this->comparison;
    }

    private function check(): void
    {
        ++$this->checks;
        if ($this->fault) {
            throw new RuntimeException('Private consumer policy unavailable.');
        }
    }
}
