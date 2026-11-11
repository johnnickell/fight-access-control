# Audit transaction conformance (Unreleased)

This test-only foundation binds the [current typed evidence](audit-evidence.md) to the already enabled
`EnableUserHandler`, its real User lifecycle CAS, the ordinary Common `TransactionalUnitOfWork` contract and
post-commit publication. It adds no production orchestrator, persistence API, repository interceptor or business-aware
UoW. It does not implement family optionality or qualify every writer in the [outcome matrix](audit-outcome-coverage.md).

## Run and bind

From an explicitly selected isolated checkout, follow [local tooling](engineering/LOCAL_TOOLING.md):

```sh
./bin/up --worktree /absolute/selected/checkout
./bin/composer update --no-interaction --prefer-dist --no-progress
./bin/exec php vendor/bin/phpunit --fail-on-risky --fail-on-skipped --no-coverage \
  tests/Application/AccessControl/Audit \
  tests/Application/AccessControl/User/CommandHandler/EnableUserHandlerTest.php
./bin/build
```

These are ordinary product behavior tests, included in the default Application suite. `bin/quality` remains the
single ordered full gate; negative controls are expected assertions inside that suite, not a second completion gate.
No web server, SQL server or shared ingress is required for the package binding.

Consumers retain the exact qualified production revision **and that revision's tests tree**, configure development-only
`Fight\\Test\\AccessControl\\` autoloading and extend these suites without replacing assertions or skipping cases:

- [AuditTransactionConformance](../tests/Application/AccessControl/Audit/Conformance/AuditTransactionConformance.php):
  implement `binding()` returning a fresh isolated
  [AuditTransactionBinding](../tests/Application/AccessControl/Audit/Conformance/AuditTransactionBinding.php).
- [AuditStorageConformance](../tests/Application/AccessControl/Audit/Conformance/AuditStorageConformance.php): implement
  `persistAndReopen()` and `hydrateUnsupported()` with the actual adapter's write/read mapping.
- Copy the applicable concrete reference subclass's `CoversClass` metadata; PHPUnit does not inherit class-level
  coverage targets from abstract suites. Never add test fixtures to production autoload.

The reference implementations are
[InMemoryAuditTransactionBinding](../tests/Application/AccessControl/Audit/Conformance/InMemoryAuditTransactionBinding.php),
[the transaction subclass](../tests/Application/AccessControl/Audit/Conformance/InMemoryAuditTransactionConformanceTest.php)
and [the storage subclass](../tests/Application/AccessControl/Audit/Conformance/InMemoryAuditStorageConformanceTest.php).
The binding is setup, fault injection and observation, **not a replacement implementation of enablement**. Consumers
must invoke the real enabled writer with their real repositories/UoW and fresh isolated data. Trusted entry/target
policy still applies; neither the fixture actor nor correlation authorizes an operation.

## Transaction lifetime and observations

`identities()` returns the actual owning transaction/connection objects used by the UoW, business repository and audit
repository. The package binding returns the very `InMemoryUnitOfWork` instances owning each repository's rollback
registrations, not labels, equal generations or active flags. A consumer must identify the underlying shared native
connection and enclosing transaction, not merely DI wrappers that happen to have matching names.

The handler reads a disabled User, obtains its real successor through `User::enable()`, performs the repository's
complete-state CAS, adds typed evidence and commits once. Only then does the ordinary dispatcher publish. Reopened
observations never inspect a returned Event as the source of truth.

[MappedAuditEvidenceRepository](../tests/Application/AccessControl/Audit/Repository/MappedAuditEvidenceRepository.php)
implements the real collection-like audit contract in tests. It stores JSON bytes at insertion, registers removal on
its actual owner's rollback and rehydrates on reads. Duplicate evidence IDs and unsupported mappings fail explicitly.
It deliberately checks its own transaction is active: the wrong-connection controls demonstrate why that check alone
is insufficient. It is not a production persistence adapter or a schema compatibility promise.

Business state uses the existing transaction-aware User repository. After transaction completion, including failure,
the binding encodes its observed backing state with
[StoredAuditUser](../tests/Application/AccessControl/Audit/Conformance/StoredAuditUser.php), then independent reads
rehydrate from those bytes. Observation also runs after failure so a missing rollback cannot be hidden by retaining an
old successful checkpoint. This codec supports the material-free, relationship-free User used here, including all
its persisted counters/times; other User shapes reject explicitly. It is a modeled completion checkpoint, not proof
of physical disk durability, mid-transaction reader isolation or a process restart. Consumer bindings must close/reopen
real connections and read through independent sessions/processes, not copy this checkpoint technique as SQL proof.

## Fault controls and disposition

Each `binding(fault)` owns a fresh dataset and clock (`2030-01-02T03:04:05.123456-04:00`). Consumers implement the
following named controls at corresponding **actual** boundaries, preserving the same scenario outcomes:

| Control | Injection and independent observation |
| --- | --- |
| `none` | Confirmed shared commit; active User and exactly one matching evidence record, including before/after counters, trusted time and real message correlation. |
| `audit-before` / `audit-after` | Audit failure before insertion / after staged insertion; complete User and evidence rollback, exact primary fault despite a distinct failure-notification fault. |
| `rollback` | Failure after callback staging and before commit; both participants roll back. |
| `concurrent` | Independent connection commits the same enabled writer after the loser's read and before its CAS; only the winning User/evidence survive. |
| `concurrent-final` | Final transaction validation rejects after staged evidence; losing mutation/evidence roll back. The reference injects final conflict, not a real SQL lock race. |
| `uncertain-uncommitted` / `uncertain-committed` | Lost completion acknowledgement with respectively absent/present commit; the caller sees a fault in both cases, never proof of rollback. Reopened authoritative state distinguishes them. |
| `publication` | Success publisher and failure subscriber throw distinct faults after confirmed commit; the original publication fault propagates and state/evidence remain committed. No issuance-only warning/result exception is added. |
| `orphan` | A different audit transaction is already active; business rolls back but foreign audit commits. The shared atomicity oracle detects orphaned evidence. |
| `missing` | A different audit transaction is already active; business commits but foreign audit rolls back. The same oracle detects missing evidence despite successful handler return. |

Both wrong-connection controls deliberately keep two enclosing compositions active using a Fiber barrier; they are
broken configurations, not supported independent/nested audit commits. The default suite asserts their failure of
the same atomicity oracle used by positive cases, so its expected overall result is green. No production interception,
UoW decoration or repository-generated business outcome makes the positive composition work.

The storage corpus crosses all four actor kinds (including equal UUID text for User/Agent and distinct fixed-nil
System/Anonymous), all five primary subject kinds and all eight current fact value shapes. Whole-payload equality
preserves unique non-nil evidence IDs, precise occurrence time/offset, complete before/after revisions and Role
incarnation, relationship IDs, Agent delivery identity and complete scoped operation/message correlation. Creation
and removal retain absent predecessor/successor state, not fabricated revisions. This is representation coverage,
not execution/authorization qualification of each represented family's writer.

## Safety and qualification limits

The corpus contains only synthetic typed facts, no raw credentials, hashes, ciphertext, messages, entity snapshots,
sink payloads or diagnostic text in exported assertions. Current facts admit bounded enums/IDs/counters, not arbitrary
free-text reasons. A future free-text field needs field-specific safety admission; a string type or regex is not a
sanitizer. Evidence and identifiers are not automatically public/log-safe: protect storage access, retention, test
artifacts and publication independently. Re-thrown faults still need consumer response/log sanitation.

Pending family proof remains with TASK-00123–00127 and TASK-00153–00165 (including no-change, retained-key replay,
changed-subject batches and external send/cleanup distinctions). Actual Role create/rename/remove composition remains
TASK-00168; integrated reconciliation remains TASK-00169. No scenario is silently skipped or counted as family proof.
Existing entry, target/scope, reference, cohort and restoration fences remain unchanged. Provenance grants nothing.

Real SQL mapping/schema/uniqueness, isolation/locks and writer races, independent processes, storage loss/restoration,
sink/key durability, external-effect recovery and activation/use each require separately owned consumer bindings and
evidence. Audit rollback cannot undo an external send. Retain exact candidate, dependencies, adapter/runtime identity,
connection/transaction identities, barriers, fault boundaries, complete logs/exits and independent read observations.
Package passes alone authorize no consumer workaround retirement, schema operation, adoption, release or deployment.
