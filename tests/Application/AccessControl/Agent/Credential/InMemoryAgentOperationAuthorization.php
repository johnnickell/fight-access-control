<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Closure;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\AgentOperationAuthorization;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;

final class InMemoryAgentOperationAuthorization implements AgentOperationAuthorization
{
    /** @var array<string, bool> */
    public array $scopes = [];

    /** @var array<string, int> */
    public array $destinations = [];

    public bool $locked = false;

    public int $epoch = 1;

    public bool $delegationExpired = false;

    public string $actor = 'maintainer-42';

    public UserId|AgentId|SystemId|AnonymousId|null $originatingActorId;

    public int $calls = 0;

    public ?Closure $afterAuthorization = null;

    /** @var array<string, bool> */
    public array $readDelegations = [];

    /** @var list<string> */
    public array $deniedTargets = [];

    /** @var list<array{string, string, ?string}> */
    public array $readChecks = [];

    public function __construct(private readonly InMemoryUnitOfWork $unitOfWork)
    {
        $this->originatingActorId = UserId::generate();
    }

    public function authorize(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination
    ): UserId|AgentId|SystemId|AnonymousId|null {
        ++$this->calls;
        if (!$this->unitOfWork->transactionActive) {
            throw new LogicException('Authorization must share the active package transaction.');
        }

        if (
            !($this->scopes[$scope->toString()] ?? false)
            || ($this->actor !== $scope->getCallerId()
                && !($this->readDelegations[$this->actor.':'.$scope->toString()] ?? false))
            || ($this->destinations[$destination->getId()->toString()] ?? null) !== $destination->getRevision()
            || $this->delegationExpired
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        $this->holdFence();
        $this->afterAuthorization?->__invoke();

        return $this->originatingActorId;
    }

    public function authorizeRotation(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        AgentId $target
    ): UserId|AgentId|SystemId|AnonymousId|null {
        if (
            ($this->actor !== $scope->getCallerId()
                && !($this->readDelegations[$this->actor.':'.$scope->toString()] ?? false))
            || in_array($target->toString(), $this->deniedTargets, true)
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }

        return $this->authorize($scope, $destination);
    }

    public function authorizeRead(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentId $target
    ): void {
        if ($this->unitOfWork->transactionActive) {
            throw new LogicException('Status reads must not open an issuance transaction.');
        }

        $this->readChecks[] = [$this->actor, $scope->toString(), $target?->toString()];
        if (
            !($this->scopes[$scope->toString()] ?? false)
            || ($this->actor !== $scope->getCallerId()
                && !($this->readDelegations[$this->actor.':'.$scope->toString()] ?? false))
            || ($this->destinations[$destination->getId()->toString()] ?? null) !== $destination->getRevision()
            || $this->delegationExpired
            || ($target !== null && in_array($target->toString(), $this->deniedTargets, true))
        ) {
            throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
        }
    }

    public function holdFence(): void
    {
        $this->locked = true;
        $this->unitOfWork->onCompletion(function (): void {
            $this->locked = false;
        });
    }

    public function changeAuthority(Closure $writer): void
    {
        $advance = function () use ($writer): void {
            $writer();
            ++$this->epoch;
        };
        if ($this->locked) {
            $this->unitOfWork->onCompletion($advance);

            return;
        }

        $advance();
    }
}
