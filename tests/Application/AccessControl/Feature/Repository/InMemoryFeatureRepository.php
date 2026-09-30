<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Repository;

use Closure;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureReferenceException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureId;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;

/**
 * Models transactional inserts and authoritative constraints, not real database concurrency.
 * Seeded records represent committed external writers or persisted fixtures, never this pass's inserts.
 */
final class InMemoryFeatureRepository implements FeatureRepository
{
    /** @var array<string, Feature> */
    public array $records = [];

    public int $writes = 0;

    /** @var list<string> */
    public array $lookups = [];

    /** @var null|Closure(Feature): void */
    public ?Closure $beforeAdd = null;

    /** @var null|Closure(Feature): void */
    public ?Closure $afterAdd = null;

    public function __construct(private readonly InMemoryUnitOfWork $unitOfWork)
    {
    }

    public function seed(Feature $feature): void
    {
        $this->records[$feature->getId()->toString()] = $feature;
    }

    public function add(Feature $feature): void
    {
        if (!$this->unitOfWork->transactionActive) {
            throw new LogicException('Feature writes require the shared transaction.');
        }

        $this->beforeAdd?->__invoke($feature);
        $references = $this->unitOfWork->authorizationReferenceState();
        $references->holdThroughCompletion();
        if (!$references->permissionsAreAuthoritative([$feature->getPermissionId()])) {
            throw new FeatureReferenceException('The testing Permission is no longer authoritative.');
        }

        foreach ($this->records as $stored) {
            if ($stored->getId()->equals($feature->getId()) || $stored->getName()->equals($feature->getName())) {
                throw new FeatureConflictException('Feature identity or name already exists.');
            }
        }

        $key = $feature->getId()->toString();
        $this->records[$key] = $feature;
        ++$this->writes;
        $this->unitOfWork->onRollback(function () use ($key): void {
            unset($this->records[$key]);
        });
        $this->afterAdd?->__invoke($feature);
    }

    public function getById(FeatureId $id): ?Feature
    {
        return $this->records[$id->toString()] ?? null;
    }

    public function getByName(FeatureName $name): ?Feature
    {
        $this->lookups[] = $name->toString();
        foreach ($this->records as $feature) {
            if ($feature->getName()->equals($name)) {
                return $feature;
            }
        }

        return null;
    }
}
