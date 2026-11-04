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

## AccessControl placement and dependency rules

Apply this section when placing or moving a type, selecting an inward dependency, or reviewing a namespace.
It codifies [EPIC-00015](../../../planning/epics/00015-EPIC.md)'s approved target, not certification of existing
source. The [profile](../../../planning/agents/project-profile.md#package-boundary-and-contracts) records current
implementation and follow-up owners. Existing code is evidence, not an exception by precedent.

### Domain: aggregate knowledge first

Use `src/Domain/{BoundedContext}/{Aggregate}/`; AccessControl is the bounded context, not each aggregate.
Keep a root, its identity and repository interface beside one another, for example
`Domain/AccessControl/User/{User,UserId,UserRepository}.php`. Owned entities and values live with their aggregate;
use meaningful subnamespaces only when they clarify responsibility. Do not impose an `Entity/` folder or require
every Domain type to be an entity. Values, specifications, immutable message/read DTOs and a justified Domain
service are legitimate: a Domain service owns domain knowledge that cannot naturally belong to one entity/value,
not storage, framework integration or use-case coordination.

Keep `Command`, `Query`, `Event` and aggregate `Exception` namespaces with the owner. Commands and Queries use
verb-first intent/data names; Events name past facts. Reuse existing Common low-level Domain primitives such as
UniqueId, EmailAddress and StrictJson rather than copying them or creating a generic contracts directory. A shared
capability/value with no honest aggregate owner needs a specific reason and a named home, not a folder quota.
AgentCredentialOperation's approved separate aggregate home remains TASK-00114 work, not a new bounded context.

### Application: mirrored ownership and cohesive capabilities

Use `src/Application/{BoundedContext}/{Aggregate}/` for corresponding `CommandHandler` and `QueryHandler` types.
Name policy subscribers for the policy they perform, in the owning aggregate's `EventSubscriber` namespace.
Group authentication, provisioning, rotation/revocation, delivery, maintenance, continuation, Permission assignment,
Feature discovery and authorization admission by their actual semantic responsibility. `Service` is a fallback
when no more informative capability name fits, not a miscellaneous bucket. Do not add one-class folders, forwarding
interfaces/facades, service locators or injected handlers merely to make the tree look uniform.

State-based invariants belong to Domain; repository-backed coordination belongs to Application. QueryHandlers
read through Domain repositories only: no mutation, commit or domain-event dispatch. Normal non-sensitive messages
use configured Common buses and public handler contracts, not direct-handler bypass or copied Common use cases.
Application clocks/generators/ciphers remain explicit inward contracts owned by the relevant capability; mechanism
implementations belong outward. Their current Service homes are not authorization for a new generic helper layer.

Secret-bearing use cases remain synchronous, with SensitiveParameter inputs that never enter serializable
Commands, Queries or Events. EPIC-00015 replaces the *single AuthenticationService placement requirement* with
cohesive authentication/token issuance, refresh-session, password and email-confirmation owners. TASK-00112 owns
that replacement after TASK-00111's User transitions. Preserve the supported JWT profile, safe immutable results,
login/throttle/rehash, grant/session/revocation semantics, audit and actual failure/transaction ordering; do not keep
the old service as a forwarding facade. Until that work is accepted, AuthenticationService remains current source.
Internally generating a secret does not exempt secret-free intent from Commands; the separately planned Agent
provision/rotate conversion still needs qualified result-capable Common dispatch and is not part of TASK-00107.

### Adapter: capability and aggregate presentation

Framework/provider mechanisms use `Adapter/<Capability>/<Framework-or-Provider-or-Standard>/...`, including
`Adapter/ServiceContainer/<Framework>/...`. Protocol presentation may instead group by capability, surface and
aggregate: `Adapter/Mcp/{Tool,Resource,Skill}/Agent/...`. This transport-neutral MCP destination specializes
WF-035's earlier framework-only layout; do not add speculative `Http/V1` folders. Concrete Tool schemas, input/output
and protocol failure mapping, Common descriptor/registry bindings and family availability implementations belong
outward. Authentication, Permission decisions and synchronized continuation remain inward; a neutral upstream
interface does not by itself make a concrete protocol presenter Application policy.

Aggregate-oriented persistence, for example `Adapter/Persistence/<Provider>/User/...`, illustrates how a consumer
can implement a Domain repository. It is **not approval to ship persistence adapters in AccessControl**. Consumers
still own actual storage, grants/target scope, routes/bootstrap, keys, queues and runtime. Keep adapters thin,
explicitly enabled and framework dependencies optional/isolated. Mirror production ownership in tests, including
`tests/Adapter`; no test moves, new namespaces or gate wiring are implemented by this guidance.

### Allowed inward dependencies and finite exceptions

| Layer | Allowed contracts | Excluded mechanisms |
| --- | --- | --- |
| Domain | Pure PHP, own Domain types and supported upstream Domain contracts | Application, Adapter, frameworks, storage/transport implementations |
| Application | Own Application/Domain, PSR contracts and supported upstream Application/Domain contracts | Adapter and third-party framework/infrastructure mechanisms |
| Adapter | Inward contracts plus the explicitly approved framework/provider/standard mechanism | Duplicated business policy or upstream protocol engines/use cases |

Existing Common password/token ports, messaging contracts, transaction boundary and low-level values are justified
package contracts, not a license to import Common Adapter or Standards code inward. Common owns neutral MCP ports,
protocol engines and its existing execution/Task/Notice use cases; AccessControl owns its authority/Permission
policy. Consult the [source-linked coordination notes](../../../planning/agents/domain.md#consumed-common-contracts)
before changing consumed APIs. A planned canonical name or Common's deprecated 1.x bridge is not a qualified API.
Keep a specific exception's purpose, owner and limits visible; new exceptions need an approved decision, not a
blanket allow-list. The current dependency checker remains the executable baseline; broader target permission
(such as Application PSR contracts) does not silently amend its collectors or prove existing source conformance.

### Repository capability is not transaction ownership

Domain repository interfaces sit beside their aggregate. Use canonical add/getById/remove where those operations
fit, while preserving actual atomic compare-and-replace, uniqueness, coupled writes and transaction-duration
fences. For example, UserRepository's authority replacement and coupled refresh-session insertion cannot be reduced
to get-then-save. Do not invent Application Store/save/reservation ports or combine a repository with a UoW.
The Application use case owns the actual transaction through the separate TransactionalUnitOfWork contract.
Known owned callback results may use a matching local @var with its native mixed return; no generic supplier
replacement, result wrapper or redundant runtime check is required for that local annotation.

Preserve current-contract hydration, independent authorization, retained keys, canonical marker 2, receipts,
tombstones, generations, ordering, writer fences and restoration safety. Apply
[ADR 0011](../../../planning/adr/0011-pre-v1-current-contract-only.md): remove obsolete AccessControl APIs directly
when their replacement is implemented, without aliases, historical readers or migration/backfill machinery.
Do not confuse those removals with permission to weaken current safety. Success facts follow confirmed commit;
uncertainty and the scoped Agent issuance publication-warning exception remain distinct under the profile.
Required audit in current source is unchanged here; EPIC-00017's optional-atomic audit destination has separate owners.
Exact owned-statement coverage and OpenAPI integration coverage remain mandatory. An actual source/policy mismatch
returns to its named TASK/decision owner; this guidance neither repairs it nor authorizes later work.

## CQRS and persistence

Commands express intent; queries return data without business mutation; events express facts. Follow the project's message identity, metadata, bus, and dispatch contracts. Keep transport information out of domain messages unless it is meaningful use-case context. Application handlers orchestrate; adapters do not become alternate business workflows.

Repositories may return null from `getById()`. The QueryHandler or CommandHandler decides whether absence is a failure and throws Fight Common's `LookupException` where appropriate. An adapter translates that failure. Do not rename a nullable repository method solely to imply failure behavior.

Where a project owns migrations, map them to its persistence adapter and coordinate Domain/model changes before dependent artifacts. AccessControl's ADR 0011 overrides any previous-iteration migration/compatibility obligation; actual consumer storage and current-state restoration safety remain consumer qualification. Do not infer a package persistence adapter or reversible migration from this general pattern.

## Transactions and events

Identify which handler owns the transaction, what commits atomically, and what happens if later delivery fails. Prefer the current `TransactionalUnitOfWork` contract where available; reconcile consumer compatibility explicitly. Avoid a blanket rule that all external calls must be outside all transactions.

Distinguish business persistence, durable delivery work, event publication, and subscriber execution. Post-commit dispatch can be lost; durable delivery state alone is not a transactional outbox. State the actual guarantees in the use case and evidence. Event sourcing and outboxes are deliberate choices, not mandatory CQRS infrastructure.

When implementing projections or publication workers, inspect current checkpoint/cursor semantics. A successful write followed by failed checkpoint persistence can repeat delivery. Determine idempotency, ordering, concurrency, retry, and recovery from the real use case. Recorded subscriber failure is not proof of automatic retry. Do not advertise reliable publication until the implementation proves it.
