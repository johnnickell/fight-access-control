# Feature retirement (unreleased TASK-00067)

Use `RemoveFeature` to retire an unreferenced Feature by **stable ID and positive expected revision**, obtained
from the [management read](feature-management.md). Consumers authorize every entry point, including direct bus
and repository use; the Feature's testing Permission grants no management authority. This package supplies no
HTTP/CLI surface, UI, scanner, database adapter or deployment workflow.

```php
use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\RemoveFeatureHandler;
use Fight\AccessControl\Domain\AccessControl\Feature\Command\RemoveFeature;
use Fight\Common\Domain\Messaging\Command\CommandMessage;

// Consumer-owned current-code discovery, shared persistence/transaction and event publisher.
$handler = new RemoveFeatureHandler($currentDiscovery, $featureRepository, $transactionalUnitOfWork, $eventDispatcher);
$handler->handle(CommandMessage::create(new RemoveFeature($featureId, $expectedRevision)));
```

`commandRegistration()` returns `RemoveFeature::class`. The immutable command serializes exactly
`feature_id` and `expected_revision`; `fromArray()` requires a string ID and integer revision, with no inferred
revision or name-based target. Invalid revisions throw `FeatureRevisionException`; invalid IDs reject through
the existing identity type. A successful call returns `void`, not a release, activation or deployment receipt.
The new command/fact are PHP messaging contracts; no new HTTP/OpenAPI schema or endpoint is introduced.

## Current references, not candidate absence

The handler opens one `commitTransactional()` operation, loads the target by ID and rejects an unknown target or
stale revision. It then requests `FeatureReferenceScope::CURRENT` from `FeatureReferenceDiscovery` and extracts
only a complete result for that same scope. Both native `#[FeatureFlag]` declarations and explicit registrations
must be included. Either source referencing the immutable name rejects at **every** status, including OFF and ON.
Removing only one source does not permit deletion while the other remains.

Failed/incomplete discovery, malformed declarations after partial accumulation, wrong-scope results and scanner
exceptions stop removal. Never catch them as a complete empty inventory. A genuinely complete, successful empty
inventory is valid absence evidence. A CANDIDATE inventory omitting the name does not establish current absence;
never relabel it as CURRENT. Consumers must identify actual currently running code, all modules and all supported
registrations, including deployments they continue to serve. See [discovery obligations](feature-references.md).
The consumer discovery implementation may supply an already-completed inventory, but must preserve its correct
code identity, freshness and completeness; it must not open a nested package transaction during discovery.

Remove references, deploy the removal under consumer policy, refresh the management record, then retry. Keep the
released ON record while current references remain. No code/registration cleanup is performed automatically.
The package neither certifies an arbitrary scanner nor fences another version becoming active after discovery.
There is intentionally no cross-deployment lock, release ledger, permanent name reservation, tombstone or approval
protocol. Undisclosed running code and privileged direct SQL remain outside the guarantee. Runtime drift still
uses the [unknown-Feature error](feature-availability.md), not ON or on-demand provisioning.

## Final expected-state write and reference release

`FeatureRepository::remove(Feature $expected): bool` is mandatory for adopting repositories. Under the handler's
shared transaction, compare **ID, name, status, Permission ID and revision at the final write boundary**, not only
at the earlier read. Return false for missing or stale state. A status/Permission edit during discovery must leave
the newer record intact. Never retarget a name to a newly created identity. The handler maps a final false result
to `FeatureRevisionException`; initial absence throws `FeatureNotFoundException`.

The removal and release of its Permission reference commit together and both restore on rollback. Use the same
transaction-duration Permission-reference fence as creation/rebinding/removal: another Permission removal must
not commit against a reference released by an uncommitted Feature deletion that might roll back. A broken binding
need not be repaired before retiring an otherwise unreferenced Feature. Feature deletion does not delete a
Permission, change grants or substitute a default. Remaining Feature/Role/Agent references still block later
[Permission removal or managed reconciliation](feature-permission-references.md). Adapters must qualify actual
isolation, expected-state comparisons, lock ordering, rollback and ORM cache behavior on their database/connection.
Controlled in-memory tests are not that qualification. No prior-format reader or migration is supplied.

## Commit, publication and retry

On confirmed success, `FeatureRemoved` publishes **after** commit. Its exact safe payload is `feature_id`, `name`,
with typed getters and canonical `fromArray()`/`toArray()` round trips. It records the removed identity, not a
historical name reservation. Discovery, validation and pre-commit write/commit failures publish no removal success;
confirmed pre-commit failures roll back the stored removal and reference release.

Any failure attempts `CommandFailedEvent` with the original command and error message, then rethrows the **same
original throwable**, even when the failure publisher also throws. Post-commit publication failure cannot restore
the record or establish rollback; lost commit acknowledgement likewise cannot prove rollback. There is no outbox,
notification replay or guaranteed eventual delivery. A retry of an already removed ID reports unknown rather than
silently deleting another identity. Protect arbitrary scanner/storage diagnostics internally and map safe consumer
errors; a failure event is neither a public response nor a rollback receipt. The credential-only warning exception
does not apply.

After successful retirement, later declaration/registration and ordinary [provisioning](feature-provisioning.md)
create a **new identity, OFF, revision 1**, bound to the then-configured existing default Permission. Missing required
default configuration fails without incomplete records; subsequent valid provisioning works normally. Repeated
provisioning preserves the new choices even if the default changes. Old delete/update commands cannot affect the
new identity. Neither previous ON/PREVIEW nor the old audience is resurrected. Between removal and reprovisioning,
lookup is unknown and does not create storage. The fresh OFF record passes [preparation](feature-preparation.md)
while denying runtime availability. Preparation never grants activation or action authorization.

## Executable evidence and adoption

- `tests/Application/AccessControl/Feature/CommandHandler/RemoveFeatureHandlerTest.php` exercises actual handlers
  with native declarations, registrations, all statuses, failed/incomplete/malformed/wrong-scope discovery, scanner
  faults, stale/unknown targets, edits during discovery and final write, rollback, post-commit ordering and both
  publishers failing. Its composed lifecycle uses real management → cleanup/retry → deletion → unknown evaluation
  → new candidate registration → failed then valid provisioning → preparation → OFF evaluation, with a changed
  default and old delete/update rejection.
- `tests/Application/AccessControl/Feature/Conformance/FeatureRetirementConformance.php` is consumer-bindable
  through `FeatureRetirementEnvironment`: current registration cleanup/retry, reference release, stale deletion,
  reintroduced identity and **each full-state field** at the final repository boundary. The default in-memory
  binding is a controlled package model; bind disposable real adapters and add overlapping transaction tests.
- `tests/Application/AccessControl/Feature/Conformance/ManagedFeatureRemovalTest.php` executes guarded Feature
  retirement followed by real managed-policy reconciliation: no remaining references permits Permission removal;
  another Feature, custom Role or Agent reference still rejects it. No fixture-only Permission deletion substitutes
  for that composition.
- [Complete scenario/evidence inventory](feature-evidence.md) retains all Feature EPIC/TICKET requirements, the
  preceding TASK acceptance subjects and scoped evidence, and remaining independent/consumer qualification.

Before adoption, qualify actual scanner/code identity and completeness (both reference sources and invalid/partial
scans), real final-state races/reference-release rollback, UI retirement/confirmation where exposed, every caller
entry point and deployment cleanup/reintroduction. Package tests, technical review, QA, release and consumer
qualification remain distinct. TASK-00067 requires its own independent review and behavioral QA; a builder pass
alone does not complete Feature acceptance or authorize a partial release, adoption or deployment.
