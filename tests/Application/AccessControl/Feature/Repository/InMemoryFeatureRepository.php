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
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
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

    /** @var null|Closure(Feature, Feature): void */
    public ?Closure $beforeReplace = null;

    /** @var null|Closure(Feature): void */
    public ?Closure $afterReplace = null;

    /** @var null|Closure(Feature): void */
    public ?Closure $beforeRemove = null;

    /** @var null|Closure(Feature): void */
    public ?Closure $afterRemove = null;

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
        $this->unitOfWork->authorizationReferenceState()->retainFeature($feature);
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
        $references->retainFeature($feature);
        ++$this->writes;
        $this->unitOfWork->onRollback(function () use ($key, $feature, $references): void {
            unset($this->records[$key]);
            $references->removeFeature($feature->getId());
        });
        $this->afterAdd?->__invoke($feature);
    }

    /** @return ResultSet<Feature> */
    public function getAll(Pagination $pagination): ResultSet
    {
        return new ResultSet(
            $pagination->page(),
            $pagination->perPage(),
            count($this->records),
            ArrayList::of(Feature::class)->replace(array_slice(
                array_values($this->records),
                $pagination->offset(),
                $pagination->limit()
            ))
        );
    }

    public function replace(Feature $expected, Feature $replacement): bool
    {
        if (!$this->unitOfWork->transactionActive) {
            throw new LogicException('Feature writes require the shared transaction.');
        }

        $this->beforeReplace?->__invoke($expected, $replacement);
        $references = $this->unitOfWork->authorizationReferenceState();
        $references->holdThroughCompletion();
        if (!$references->permissionsAreAuthoritative([$replacement->getPermissionId()])) {
            throw new FeatureReferenceException('The testing Permission is no longer authoritative.');
        }

        $key = $expected->getId()->toString();
        $stored = $this->records[$key] ?? null;
        if (!$stored instanceof Feature || !$this->sameState($stored, $expected)) {
            return false;
        }

        if (
            !$replacement->getId()->equals($expected->getId())
            || !$replacement->getName()->equals($expected->getName())
        ) {
            throw new LogicException('Feature identity and name are immutable.');
        }

        $changed = $replacement->getStatus() !== $expected->getStatus()
            || !$replacement->getPermissionId()->equals($expected->getPermissionId());
        if ($replacement->getRevision() !== $expected->getRevision() + (int) $changed) {
            throw new LogicException('A Feature replacement must advance exactly once on a real change.');
        }

        if (!$changed) {
            return true;
        }

        $this->records[$key] = $replacement;
        $references->retainFeature($replacement);
        ++$this->writes;
        $this->unitOfWork->onRollback(function () use ($key, $stored, $references): void {
            $this->records[$key] = $stored;
            $references->retainFeature($stored);
        });
        $this->afterReplace?->__invoke($replacement);

        return true;
    }

    public function remove(Feature $expected): bool
    {
        if (!$this->unitOfWork->transactionActive) {
            throw new LogicException('Feature writes require the shared transaction.');
        }

        $this->beforeRemove?->__invoke($expected);
        $key = $expected->getId()->toString();
        $stored = $this->records[$key] ?? null;
        if (!$stored instanceof Feature || !$this->sameState($stored, $expected)) {
            return false;
        }

        $references = $this->unitOfWork->authorizationReferenceState();
        $references->holdThroughCompletion();
        unset($this->records[$key]);
        $references->removeFeature($expected->getId());
        ++$this->writes;
        $this->unitOfWork->onRollback(function () use ($key, $stored, $references): void {
            $this->records[$key] = $stored;
            $references->retainFeature($stored);
        });
        $this->afterRemove?->__invoke($expected);

        return true;
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

    private function sameState(Feature $left, Feature $right): bool
    {
        return $left->getId()->equals($right->getId())
            && $left->getName()->equals($right->getName())
            && $left->getPermissionId()->equals($right->getPermissionId())
            && $left->getStatus() === $right->getStatus()
            && $left->getRevision() === $right->getRevision();
    }
}
