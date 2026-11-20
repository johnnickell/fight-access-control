# Feature management (v0.5.0)

AccessControl supplies framework-neutral Domain/Application operations; consumers authorize every read and write entry point (including direct command-bus use), build UI/adapters, and qualify persistence. A Feature's testing Permission grants **no** management authority. Use `CreateFeature` with a validated name and existing Permission ID for manual creation without registration; `CreateFeatureHandler` commits OFF at revision 1, then publishes `FeatureCreated`. Provisioning later preserves the original row, binding, status and revision even if the configured default changes. Unique identity/name and reference validation at commit remain adapter obligations.

`GetFeatureById` and `ListFeatures` use `FeatureRepository::getById` and `getAll(Pagination)` to return safe `FeatureView` results. The latter preserves the requested page, size and total through `ResultSet<FeatureView>`. Each result contains `feature_id`, immutable `name`, `status`, stored `permission_id`, nullable `permission_name`, explicit `binding_missing`, and positive `revision`. A missing definition never fabricates a name or substitutes the configured default; a missing Feature ID throws Common Application `ResourceNotFound`. A different returned Feature identity is an internal fault, checked before resolving its Permission. See [required-resource Queries](required-resource-queries.md). Existing `ListPermissions` supplies the selection choices. Reads do not transact, publish, grant or decide availability. Adapters must preserve exact current IDs and ordering/pagination semantics, and distinguish absence from storage failure or invalid reconstruction.

`SetFeatureStatus` and `SetFeaturePermission` each require the current positive `expected_revision` and use one shared revision. `Feature::withStatus` / `withPermission` own immutable-name transitions; real edits increment once and publish `FeatureStatusChanged` / `FeaturePermissionChanged` **after** commit. No-op selections still validate current expected state and an authoritative bound Permission through `FeatureRepository::replace($expected, $successor)`, but write nothing, advance nothing and publish no success fact. Broken bindings remain visible for recovery: status changes cannot launder them, and a valid selected ID can repair them through the ordinary rebinding path. An explicit stale selection (including stale no-op) throws Common Application `PreconditionFailed`; a missing target or selected Permission throws `ResourceNotFound`. Final state/reference races throw `StateConflict`. Wrong lookup identities and infrastructure errors remain internal, never ordinary absence or conflict. The handler publishes `CommandFailedEvent` on failure and rethrows the original throwable. A post-commit publication fault does not roll back the edit or promise eventual event delivery.

## Mutation contracts (unreleased)

All five Feature Commands remain void through supported Common synchronous routing and decoded-message dispatch.
Creation and rebinding validate the exact requested Permission ID; update/removal validate the exact Feature ID
before revision checks, discovery or mutation. Provisioning validates existing names and its lazily resolved default
name without validating unused configuration or repairing existing bindings. Known repository uniqueness/reference
outcomes translate narrowly to `StateConflict`, retaining the Domain cause. Unknown failures are not translated.
Current-code references deliberately refuse retirement with `RuleViolation`.

Consumers must update exception catches directly; Domain constructor validation, availability configuration errors
and malformed persisted state remain distinct internal boundaries. Common's opt-in safe middleware renders deliberate
absence/precondition/conflict/rule intent as generic 404/412/409/422, and unknown/native/identity faults as generic 500.
Never classify by diagnostic text or codes. Existing local transaction-result annotations describe owned Event/null
or Event-list branches while the UoW remains native mixed. No new result DTO or generic supplier is introduced.
Manual creation, retirement and setting changes offer the optional audit compositions below; provisioning remains separate. See [operation outcomes](operation-outcomes.md#managed-policy-composition) for the shared policy path.

## Optional manual lifecycle audit (unreleased)

`CreateFeatureHandler` and `RemoveFeatureHandler` take the existing Application `Timing\Service\Clock` before
`TransactionalUnitOfWork`, and an optional **last** nullable `AuditEvidenceRepository`. Omitted/null deliberately
disables durable audit; configured storage failure never falls back to disabled behavior. Supply the ordinary UoW,
Feature/Permission repositories and audit adapter on the same actual connection and enclosing transaction. No Event
inspection, transaction decorator, independent audit commit or post-commit subscriber is needed.

`CreateFeature` and `RemoveFeature` accept optional last typed `actorId`: `UserId`, `AgentId`, `SystemId` or
`AnonymousId`. Trusted entry callers supply the real originating identity, not a worker replacement or guessed
target. This grants no management authority. Canonical payloads include nullable `actor_kind` and `actor_id`;
provenance may be omitted/null when auditing is disabled. With enabled storage, missing attribution rejects
before the first mutation requiring evidence. There is no ambient principal lookup or system/anonymous fallback.
System/anonymous kinds retain their distinct fixed-nil identities, not User/Agent nil substitutes.

After authoritative persistence and before commit, enabled creation records `feature.created` with a typed Feature
subject and `AuditFeatureOutcome(null, AuditFeatureState)` containing actual OFF/revision 1 and the related Permission
ID. Enabled retirement records `feature.removed` with the authoritative prior state and null successor. Both use
a fresh evidence identity, incoming message ID and trusted Clock outcome time. Feature stores no timestamp: the
clock does not fabricate one or use message/database insertion time. Shared Feature-created definitions are already
available for separately owned code-defined provisioning; that handler is not changed by this slice.

Evidence contains no Feature/Permission names, messages, entities or diagnostics. Treat identifiers/evidence as
restricted stored facts, not automatically public logs/responses. Duplicate creation, stale/recreated retirement,
current references, incomplete/wrong-scope discovery and final CAS/reference loss add no committed evidence.
Any existing Permission tier remains eligible as a binding; this is a reference, not a grant.

Audit failure rolls back staged Feature/reference/evidence together, preserving concurrent winners and the primary
throwable even if failure notification also fails. Unknown commit acknowledgement remains uncertain. Ordinary
post-commit publication/subscriber failure still throws while committed state/evidence survive; failure notification
is not a rollback receipt. No audit backfill or retry deduplication is invented for previously unaudited history.

**Pre-v1 adoption:** update constructor/named callers, current actor-kind/UUID messages and persistence mapping
explicitly. Qualify the real shared connection, constraints, locks, rollback and both uncertain outcomes before
opt-in. Package in-memory mapped fixtures establish modeled outcomes only; no schema operation, compatibility reader,
consumer change, release or deployment is supplied. See [retirement](feature-retirement.md) for its unchanged guards.

## Optional atomic setting audit (unreleased)

`SetFeatureStatusHandler` and `SetFeaturePermissionHandler` now require the existing Application
`Timing\Service\Clock` before the Unit of Work, and accept an optional **last nullable**
`AuditEvidenceRepository`. Omission/null deliberately disables durable audit. A configured writer must join the
Feature repository's actual connection and transaction; failure is not permission to discard evidence.

Both Commands accept optional final `actorId: UserId|AgentId|SystemId|AnonymousId|null`. Canonical serialization
includes nullable `actor_kind` and `actor_id`; supply both together, or neither/nulls for absent provenance.
Trusted entry callers supply the originating identity, even when a worker executes later. Actor IDs grant no
management, binding, availability or business-action authority. No ambient resolver or fallback actor is used.
Enabled real changes reject missing provenance with `InvalidInput` before persistence. Disabled work and fully
validated no-change require no new audit-only actor. Stale or invalid-reference no-ops still reject.

```php
$handler = new SetFeatureStatusHandler(
    $featureRepository,
    $clock,
    $unitOfWork,
    $eventDispatcher,
    $auditEvidenceRepository,
);
$commandBus->execute(new SetFeatureStatus($featureId, FeatureStatus::PREVIEW, $expectedRevision, $originatingActorId));
```

Register the handler with that same configured bus. Binding uses the analogous constructor with
`$permissionRepository` after `$featureRepository`. Any existing Permission tier remains eligible: this is a
Feature relationship, not a Role/Agent grant. No package authorization is added.

After authoritative full-state replacement, the handler explicitly inserts one `feature.status_changed` or
`feature.permission_changed` record before commit, under the retained Permission-reference fence. The existing
`AuditFeatureOutcome` contains only before/after revision, status and typed Permission identity; the primary
subject is the Feature. No Permission mutation/revision, Feature timestamp, name, raw message, entity, diagnostic
or free text is captured. Evidence has a unique identity, actual incoming message correlation and trusted Clock
outcome time. It is not automatically authorized public/log output.

Audit failure or a losing state/reference fence rolls back staged Feature/evidence together, preserving unrelated
state and concurrent winners. Unknown commit acknowledgement remains uncertain; do not blindly replay or backfill.
Ordinary success/subscriber publication failure still throws after commit and cannot erase either state or evidence;
secondary failure reporting cannot replace the primary throwable. Fully validated no-change inserts nothing and
publishes no mutation success. Enabling audit later does not manufacture historical records.

**Pre-v1 adoption:** update current constructor/named callers and Command/schema mappings directly, then qualify
actual evidence mapping, shared connection, transaction/isolation, all-writer reference fences and cache bypass.
Package transaction-aware tests model these properties, not a production database, entry policy or deployment.
There is no legacy reader, compatibility mode, migration/backfill or rejected-attempt store. Other Feature and
managed-policy audit paths retain their own TASKs.

## Precise Domain types (unreleased)

Feature Commands, Queries, Events and `FeatureView::toArray()` now expose exact static payload shapes.
Decoded `ListFeatures` requires integer `page`/`per_page` and a string-keyed, string-valued `orderings` map;
nulls, numeric strings, booleans, floats and arrays are not coerced into bounds. This is input-type validation,
not a change to Query absence, pagination policy or Common dispatch typing. Callers must decode integers and
ordering maps before constructing the current message; valid payload bytes and ordering normalization remain unchanged.
Other Feature messages retain their existing strict field validation and nullable raw provisioning configuration.

Direct `FeatureView` construction rejects nonpositive revisions just as authoritative Feature hydration does.
At `PHP_INT_MAX`, a real status/binding transition rejects with `FeatureStateException` before integer overflow;
an unchanged selection still returns the original aggregate. Nullable repository reads, full expected-state writes,
Permission reference/tier fences, retirement and availability versus action permission retain their existing meaning.
The complete 30-declaration/member audit and regression/static evidence belong to TASK-00120's canonical Work
handoff. Independent Review ACCEPT and nonvisual QA PASS cover candidate
`fb01e06e9b86ff1bdc550eaef437573ec3896b84`; current delivery results belong to the canonical TASK-00120 Land
handoff and actual PR. No real consumer persistence qualification is claimed.

## Atomic adapter obligation

`FeatureRepository::replace` must compare the entire expected state (identity, name, status, binding and revision) at the *final* write/no-op boundary, not merely a preflight read. It rejects stale or missing rows, forbids identity/name changes, accepts only a single revision advance for a real edit, and performs no write for an unchanged successor. Validate the selected Permission ID on **every** attempt, including no-ops; coordinate with `PermissionRepository::remove` and `FeatureRepository::add` using the same transaction-duration reference fence held through commit. A removal and new binding cannot both win. The actual consumer must prove these properties on its database/connection, including isolation, lock ordering, rollback and any ORM identity-map cache bypass. Persist positive revisions for *all* creation/hydration paths. Existing pre-v1 mappings require deliberate consumer adapter/schema adjustment; no package migration or legacy reader is provided. Package fixtures model these contracts, not real concurrent database qualification.

## Consumer workflow and evidence

Offer OFF/PREVIEW/ON choices. When leaving ON for PREVIEW or OFF, show a warning and require confirmation **before** invoking the command. Cancellation invokes nothing; after a stale rejection, refresh settings and request new confirmation if the proposed transition still leaves ON. Authorize caller and target for all paths; these UI interactions, HTTP mapping and security wiring are consumer-owned. A real management change affects the next explicit `FeatureAvailability` check with the *same* captured User/Agent authority snapshot; availability does not grant business/action authorization or cancel already admitted work. Invalid/missing bindings fail closed at runtime, while management reads remain useful for authorized repair. See [Feature availability](feature-availability.md), [provisioning](feature-provisioning.md), and [Permission reference integrity](feature-permission-references.md).

The package tests run actual management handlers with controlled shared persistence, rollback, stale edits, binding conflicts and fresh evaluation. Bind `tests/Application/AccessControl/Feature/Conformance/FeatureManagementConformance.php` to the consumer repositories and transaction/event ports: it checks manual creation, cross-field stale updates, safe reads, both committed rebinding/removal winner orders, and release of the old reference through public handlers. A controlled in-transaction probe also attempts target removal after the new binding is retained, while a separate rollback probe attempts removal before retention. Broken-binding read, fail-closed evaluation and fresh evaluation after repair share one principal snapshot in package tests. Also run actual database concurrency (both rebinding/removal winner orders and rollback), pagination, broken-binding recovery and UI confirmation/security checks before adoption. Controlled in-memory interleavings are not database proof. [TASK-00067](feature-retirement.md) supplies independently accepted guarded Feature deletion and full retirement/reintroduction evidence, with behavioral QA. No partial Feature release or deployment is implied.
