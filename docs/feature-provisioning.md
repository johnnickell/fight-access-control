# Atomic Feature provisioning (unreleased)

[TASK-00062](../planning/tasks/00062-TASK.md) adds the stored Feature model and create-if-absent provisioning.
It consumes [complete candidate discovery](feature-references.md), creates only missing names OFF, and preserves
existing identities/settings. It does **not** establish activation readiness or a supported partial Feature release:
preparation validation (TASK-00063), evaluation (TASK-00064), Permission-removal guards (TASK-00065), management
(TASK-00066), and guarded retirement/final lifecycle evidence (TASK-00067) remain separate.

## Composition and invocation

Consumers authorize the deployment/setup entry point and bind discovery to the exact intended candidate code.
A testing Permission grants neither setup nor management authority. The package reads no environment variables:
pass the consumer's `DEFAULT_FEATURE_PERMISSION` value as a nullable string. Configure both repositories on the
same connection/transaction as the `TransactionalUnitOfWork`; do not wrap the handler in another transaction.

```php
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

// Consumer-owned discovery, persistence, transaction and event-dispatcher implementations.
$handler = new ProvisionFeaturesHandler(
    $candidateDiscovery,
    $featureRepository,
    $permissionRepository,
    $transactionalUnitOfWork,
    $eventDispatcher
);
$handler->handle(CommandMessage::create(new ProvisionFeatures($configuredDefaultOrNull)));
```

`commandRegistration()` returns `ProvisionFeatures::class` for Common command-handler registration. The command's
canonical payload is `['default_permission_name' => string|null]`; `fromArray()` requires the key and rejects other
types. It deliberately does not validate an unused string as a Permission name. Names come from the injected
`FeatureReferenceDiscovery`, not the command, process environment, or serialized scanner internals. Execute in the
intended candidate composition; queued dispatch alone does not pin code identity. Discovery must freshly attest
completeness for the intended code, and retries must not reuse stale negative catalog lookups.

The handler requests `CANDIDATE`, extracts only a complete result for that scope **before opening a transaction**,
then performs authoritative reads and all inserts in one `commitTransactional()` call. A wrong scope, failed scan,
invalid declaration or incomplete inventory rejects; a complete empty inventory is valid. The consumer may supply
an already-completed inventory through its discovery implementation, but must retain its correct scope/code identity.

The handler returns `void`. A normal return means this creation pass completed and its attempted event dispatches
returned; it says nothing about subscriber completion, existing definitions' integrity, deployment activation or
runtime access. The consumer must perform preparation validation before activating referencing code. That package
validation path is not supplied by this TASK; do not infer readiness from a normal provisioning return meanwhile.

## Model and persistence contract

Domain types live under `Fight\AccessControl\Domain\AccessControl\Feature`:

- `FeatureId` extends Common `UniqueId`. `FeatureName` is the existing strict, immutable name value.
- `Feature::define($id, $name, $permissionId)` starts OFF at revision 1. It cannot select another initial status.
- `FeatureStatus` serializes exactly `OFF = 'off'`, `PREVIEW = 'preview'`, `ON = 'on'`. The enum is a stored setting,
  not an evaluator or authority grant.
- `Feature::reconstitute($id, $name, $permissionId, $status, $revision)` preserves stored settings and requires a
  positive revision. Hydration cannot invent defaults, substitute IDs or reset revisions. Use it for authoritative
  persisted data, not management input. Invalid names/IDs/status strings must reject at their typed value boundaries.
- `getId()`, `getName()`, `getPermissionId()`, `getStatus()` and `getRevision()` expose the state. Identity/name and
  this snapshot's properties are readonly. The entity is extensible for adapters; no rename, status update, rebinding,
  delete or runtime evaluator is introduced here. The single revision prepares the accepted downstream concurrency
  contract without supplying a management transition.

`FeatureRepository` owns `add(Feature): void`, `getById(FeatureId): ?Feature` and
`getByName(FeatureName): ?Feature`. A read returning null means authoritative absence; storage failures throw,
never masquerade as absence. Existing records, including broken Permission references, are preserved without repair.

**Adapter obligations:**

1. `add()` atomically enforces unique ID and exact name. Reject conflicts with `FeatureConflictException`; never
   upsert, overwrite the winner, or turn an existing record OFF. Application preflight lookup is not a uniqueness lock.
2. Validate the actual Permission ID at insertion and hold the **shared Permission-reference fence through commit**.
   Reject an unsafe/missing binding with `FeatureReferenceException`. All Permission-removal and Feature-reference
   writers must share that fence/transaction protocol; both referencing creation and conflicting removal cannot win.
   Existing Permission names can change without changing a stored Feature's identity binding.
3. Any currently defined Permission tier is eligible, including SUPER_ADMIN_ONLY. A Feature reference is not a Role
   membership, Agent grant or extra protected-tier promotion restriction. Existing principal and tier policy stays intact.
4. All inserts in one pass commit together. Rollback removes only this pass's writes, not other writers' committed
   records. Invalidate transaction-local state after rollback/uncertainty as necessary; use fresh authoritative state
   on retry. Adapters choose concrete unique constraints, reference locks and isolation, then qualify the actual races.
5. Coordinate the creation-side contract above with TASK-00065's **not-yet-implemented** all-path Permission-removal
   guards, and later management/rebinding/retirement. Neither this interface nor the package tests qualify a complete
   consumer composition or authorize deploying a Feature capability without those integrity protections.

Consumers own the new Feature storage mapping, constraints and adapter. This TASK adds no production database
adapter, schema migration, historical compatibility reader, permanent name reservation or distributed deployment lock.

## Default selection, failure and retry

The first missing name requires a supplied valid canonical `PermissionName`, resolved to its existing ID. The pass
reuses that resolution for new records, with each insertion still reference-fenced. A changed default, repeated
reference or repeated pass never changes existing identity, status, binding or revision. No Permission or grant is
created. With no missing names, even absent, malformed or unresolved configuration is unused and does not reject.
Unregistered stored records are untouched; absence from the inventory never deletes anything.

| Outcome | State and caller action |
| --- | --- |
| Missing required configuration | `FeatureProvisioningException`; no new records commit. Supply the intended default and retry. |
| Malformed required Permission name | Existing `PermissionNameException`; no new records commit. Correct configuration. |
| Unresolved required default | `FeatureProvisioningException`; no new records commit. Consumer must establish the intended definition, not let provisioning create a Permission. |
| Incomplete/wrong-scope discovery | `FeatureDiscoveryException` (or propagated scanner error); no transaction or inserts. Repair discovery and rerun. |
| Duplicate ID/name | `FeatureConflictException`; losing pass rolls back entirely. Retry with fresh storage; preserve the winning record's settings. No internal unbounded retry. |
| Lost/unsafe Permission reference | `FeatureReferenceException`; roll back the entire pass, including earlier inserts. Resolve the underlying reference/integration condition before retry. |
| Confirmed pre-commit failure | Transaction rolls back all this pass's writes; no creation fact publishes. Retry consults storage rather than a cached missing-name list. |
| Commit acknowledgement lost | The same throwable propagates; it does not certify rollback. Storage may contain all inserts or none. A fresh pass discovers which records are missing. |
| Creation publisher throws after commit | All records remain committed; some/none of the facts may have published. Retry leaves them unchanged and does not recreate or replay their facts. |

On any discovery, transaction or success-publication failure the handler attempts Common `CommandFailedEvent` with
the **original command and error message**, then rethrows the **same original throwable**, even if failure publication
also throws. This is the ordinary package failure contract, not the credential-operation warning exception.
Failure events are not rollback receipts. Arbitrary collaborator errors may contain infrastructure details: consumers
protect internal diagnostics and map failures to their own safe public presentation. Do not expose these messages or
serialize arbitrary throwables to an untrusted caller.

After a confirmed commit, the handler publishes one Domain `Event\FeatureCreated` per newly inserted record, in
first-reference order. Its exact payload is `feature_id`, `name`, `permission_id`, with typed getters and validated
`fromArray()`/`toArray()` round trips. Creation means OFF at revision 1, not readiness or authorization. Existing records
produce no fact. Publication is best effort: a failed publisher stops the remaining success dispatches. There is no
outbox, replay ledger, publication recovery worker or promise of reliable delivery. Subscribers and completion
notifications cannot substitute for catalog reads and preparation validation.

After actual guarded deletion, later reintroduction may create a fresh identity OFF with the then-current default.
Here only a seeded absent-record fixture proves that creation behavior. The real reference-removal/deletion/
reintroduction sequence remains TASK-00067's composed evidence obligation, not a delivered deletion capability.

## Compatibility and schema assessment

The new PHP types and repository contract are additive. Existing APIs, persisted formats, credential operations and
Permission tier rules are unchanged. Consumers opting into Features need a new storage/adapter composition; this is
not a migration/backfill promise for previous package iterations. No Feature release version is selected.

The opt-in OpenAPI component `Fight.AccessControl.ProvisionFeatures` describes the required nullable raw
`default_permission_name`. It deliberately has no name-pattern constraint because unused strings are accepted.
`openapi/bootstrap.php` includes its non-autoloaded anchor; generated-schema integration tests run in the default
suite. The component is additive under ADR 0007 and adds no endpoint, HTTP result, Feature View, or activation schema.
The event is an internal messaging fact, not a newly exposed HTTP response catalog. Consumer transport policy stays
separate; production Domain/Application code has no OpenAPI dependency.

## Evidence and remaining qualification

- `tests/Domain/AccessControl/Feature/FeatureTest.php`: OFF creation, identity/binding, exact serialized status,
  faithful positive-revision hydration and entity extension. Existing `FeatureNameTest.php` protects strict names.
- `tests/Domain/AccessControl/Feature/FeatureMessagesTest.php`: exact command/event round trips, required nullable
  configuration, invalid types/missing fields and invalid event names.
- `tests/Application/AccessControl/Feature/CommandHandler/ProvisionFeaturesHandlerTest.php`: actual handler with
  controlled discovery/repositories; empty/incomplete/native-invalid input, preservation, defaults, rollback of
  earlier inserts, identity/name conflicts, reference rejection, commit failure/uncertainty, restart retry, partial
  publication, both publishers failing and seeded reintroduction. Checks persisted outcomes as well as event ordering.
- `tests/OpenApi/FeatureComponentsTest.php`: generated public schema against real serialized command values.

The in-memory transaction and reference-fence model is **not** actual database concurrency, scanner completeness,
consumer authorization or deployment qualification. Consumer-bindable preparation scenarios follow in TASK-00063;
all-path Permission-removal races in TASK-00065; manual-create/provision and rebinding conflicts in TASK-00066; real
retirement/reintroduction and complete lifecycle traceability in TASK-00067. No UI exists here: executable library
behavior and generated-contract tests are the useful nonvisual evidence. Independent review and behavioral QA remain
separate from builder tests, and no build result authorizes consumer adoption, publication or release.
