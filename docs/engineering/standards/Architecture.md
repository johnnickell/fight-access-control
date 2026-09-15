# Architecture and business ownership

Use DDD and ports and adapters with the direction `Adapter → Application → Domain`. Domain owns business concepts and invariants. Application coordinates use cases through Domain/Application interfaces. Adapters implement infrastructure and presentation. Keep framework dependencies within the project's explicitly permitted boundaries; use its dependency checker rather than inventing a second layer map.

Read and maintain CONTEXT.md for ubiquitous language, aggregate responsibility, important state transitions, and accepted decisions. A domain concept belongs with the object holding its knowledge even on its first use. Repeated logic appearing more than three times may suggest extraction; this is a design signal, not a numeric rule.

```php
if (!$user->canLogIn()) {
    throw new LoginRejectedException();
}
```

Avoid feature envy: handlers and adapters should not reconstruct domain policy from a collection of getters. Entities and value objects own state-based decisions. Rules needing repositories or other services commonly use an Application `CompositeSpecification`, normally registered in the container with injected dependencies. Handlers inject that specification; simple local construction is acceptable where it makes sense.

```php
if (!$this->uniqueEmailSpecification->isSatisfiedBy($emailAddress)) {
    throw DuplicateEmailException::fromEmail($emailAddress);
}
```

Prefer capability interfaces and dependency injection for testable business coordination. Apply SOLID to concrete responsibilities: cohesive reasons to change, substitutable contract behavior, interfaces limited to consumer needs, and dependencies pointing inward. Add abstractions for demonstrated variation or boundaries, not speculative extensibility. Fowler-style refactoring should reduce duplication and misplaced knowledge while preserving behavior; principle names alone are not review findings.

## CQRS and persistence

Commands express intent; queries return data without business mutation; events express facts. Follow the project's message identity, metadata, bus, and dispatch contracts. Keep transport information out of domain messages unless it is meaningful use-case context. Application handlers orchestrate; adapters do not become alternate business workflows.

Repositories may return null from `getById()`. The QueryHandler or CommandHandler decides whether absence is a failure and throws Fight Common's `LookupException` where appropriate. An adapter translates that failure. Do not rename a nullable repository method solely to imply failure behavior.

Map migrations to the persistence adapter. Coordinate domain/model changes before dependent migrations, messages, handlers, adapters, and UI. Check compatibility and existing data consequences for schema changes; do not assume a migration is reversible merely because a down method exists.

## Transactions and events

Identify which handler owns the transaction, what commits atomically, and what happens if later delivery fails. Prefer the current `TransactionalUnitOfWork` contract where available; reconcile consumer compatibility explicitly. Avoid a blanket rule that all external calls must be outside all transactions.

Distinguish business persistence, durable delivery work, event publication, and subscriber execution. Post-commit dispatch can be lost; durable delivery state alone is not a transactional outbox. State the actual guarantees in the use case and evidence. Event sourcing and outboxes are deliberate choices, not mandatory CQRS infrastructure.

When implementing projections or publication workers, inspect current checkpoint/cursor semantics. A successful write followed by failed checkpoint persistence can repeat delivery. Determine idempotency, ordering, concurrency, retry, and recovery from the real use case. Recorded subscriber failure is not proof of automatic retry. Do not advertise reliable publication until the implementation proves it.
