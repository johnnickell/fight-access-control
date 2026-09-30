---
id: TICKET-00015
epic: EPIC-00010
title: Register and provision Features safely
status: in-progress
---

# Register and provision Features safely

## Problem and Outcome

A code declaration must not accidentally expose unfinished behavior or reset environment-specific operator choices.
Consumers need a portable inventory of declared Feature names and package-owned provisioning that creates only missing
records, OFF and bound to an existing Permission, before referencing code becomes active.

John approved this requirement area in the three-TICKET decomposition of
[EPIC-00010](../epics/00010-EPIC.md), then approved TASK-00061–00063 and the design choices below. Readiness and
fulfilled blockers identify planned executable scope, not separate implementation or deployment authorization.

## Use Cases

The operation labels below describe intent, not finalized PHP message names or new HTTP/CLI surfaces.

| Actor and trigger | Commands / operations | Queries / reads | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| A developer declares a Feature check | Construct name-only Attribute or explicitly register a programmatic reference | Read the combined reference inventory | N/A: metadata declaration does not mutate the catalog | Validated names identify required Features; repeated references identify one Feature. No status, Permission selection, principal grant, or enforcement is embedded in the declaration. |
| An authorized consumer deployment prepares candidate code | Provision missing registered Features with a supplied default Permission name | Look up existing Features and resolve the default to an existing Permission ID when creation is needed | Creation facts only for newly committed records; no success event for unchanged records | Missing records acquire new identities and start OFF. Existing records and their operator choices remain unchanged. No Permission or principal grant is created. |
| A deployment retries provisioning or another writer creates the same name | Repeat the same provisioning operation | Authoritative catalog reads | No duplicate creation fact for an unchanged existing record | Uniqueness and create-if-absent behavior preserve the winner's record rather than overwriting its status or Permission. A failed pass must not be reported as activation readiness. |
| A consumer prepares activation after provisioning | No additional mutation required by validation | Validate candidate references against the target catalog and required Permission definitions | N/A: validation is read-only | Missing or invalid definitions block consumer activation. Validation neither repairs existing choices silently nor grants runtime availability. |
| Later code reintroduces a previously deleted name | Normal provisioning | Same inventory/default lookups | Creation fact for the new committed record | Create a new identity OFF with the current default Permission, never restore deleted settings. |

## Feature and Declaration Requirements

- A Feature has a stable identity, a unique immutable FeatureName, exactly one existing Permission ID, and status.
  Use the accepted string-backed FeatureStatus cases `OFF = 'off'`, `PREVIEW = 'preview'`, and `ON = 'on'`.
  Every new record starts OFF, including consumer-authorized manual creation under TICKET-00017.
- FeatureName is 1–128 ASCII characters: lowercase letters/digits separated by single hyphens, starting with a letter.
  Accept `a`, `dashboard`, `new-checkout`, and `agent-tools-v2`; reject empty/overlong names, non-ASCII, uppercase,
  leading digits, whitespace, underscores, repeated hyphens, and trailing hyphens. Do not normalize input.
- Existing-record uniqueness is authoritative, not merely an in-memory duplicate check. Names cannot be renamed or
  aliased. A deleted name is reusable for a new identity; no permanent reservation is introduced.
- Use the same name validation in metadata, explicit registration, creation, and evaluation inputs. The Attribute
  carries only the name. John approved an initial method-only, nonrepeatable Attribute: one Feature per method
  declaration, with other checks using explicit registration. No class-wide or multi-name composition is introduced.
  The registry contains references, not environment-specific Feature settings.
- Every supported check is discoverable through an Attribute or explicit programmatic registration. Unregistered
  dynamic checks are unsupported. A declaration is not enforcement and cannot detect an intended check never written.
- Consumers supply actual scanning and registry composition. Distinguish an empty inventory from failed/incomplete
  discovery; never treat inability to discover required references as evidence that preparation is complete.

## Provisioning, Validation, and Recovery

- Consumer configuration `DEFAULT_FEATURE_PERMISSION` supplies a canonical Permission name through the package
  boundary; package code does not read environment variables or hard-code a Permission name. Resolve it to the
  environment's stable Permission ID when a missing Feature needs creation.
- If creation is required and the default is absent, invalid, or unresolved, fail provisioning. Do not create an
  incomplete Feature, substitute a different Permission, or continue activation on a false success result.
  If no creation is needed, an unused default is not required; existing definitions still need activation validation.
- Any currently defined Permission is eligible, including protected Permissions. A Feature reference is not a grant
  and changes neither membership nor tier/reclassification policy. Preserve reference integrity through creation,
  coordinated with the removal guards owned by TICKET-00017.
- Repeated provisioning, changes to the default, and concurrent create attempts must not reset existing status or
  Permission selection. Invalid existing references are not silently rebound or repaired by provisioning.
- Runtime evaluation never provisions, and absence from the new inventory never automatically deletes a record.
- John approved one atomic database transaction per provisioning pass: all new records from the pass commit together;
  a pre-commit failure rolls back that pass's inserts, not other writers' commits. No partial-batch protocol is needed.
  A concurrent creation conflict may reject the pass for a fresh retry; retry rereads authoritative stored state and
  preserves the winner. Completion still requires successful reference validation before consumer activation.
- Mutations use the project's atomic Unit of Work and post-commit event rules. Creation facts follow the pass's
  confirmed commit; pre-commit rejection publishes none. A post-commit publication failure does not undo committed
  records; retry preserves them without new creation facts for unchanged records. Event delivery is neither reliable
  by implication nor an activation receipt. EPIC-00009's credential-only publication exception does not apply.

## Permissions and Consumer Integration

Consumers authorize provisioning/setup and any management entry points. The selected testing Permission does not
confer provisioning or management authority. No package-mandated SUPER_ADMIN_ONLY administrator is introduced.

The package owns the model, repository and registry contracts, portable Attribute, provisioning policy, and safe
results/errors. Consumers own persistence/schema, candidate-code scanning, configuration, deployment hooks, and the
actual decision to activate code only after successful preparation. Scanners must identify the intended code; later
Feature deletion uses the current-code discovery contract and stricter absence check in TICKET-00017.

## Dependencies and Design Handoff

This is the shared-contract foundation for [TICKET-00016](00016-TICKET.md) and [TICKET-00017](00017-TICKET.md).
TICKET-00017 owns manual creation orchestration, management, and Permission/Feature deletion guards; all creation paths
reuse this TICKET's invariants. There is no independent supported partial release without those integrity protections.

The approved chain is [TASK-00061](../tasks/00061-TASK.md) → [TASK-00062](../tasks/00062-TASK.md) →
[TASK-00063](../tasks/00063-TASK.md): declarations/inventory, atomic provisioning, then preparation validation and
reusable contract scenarios. Each slice owns its tests and integration guidance. The Attribute target/composition
and transaction shape are now settled; the TASKs own concrete public types, repository obligations, and error/result
contracts within those boundaries. Return any genuine product ambiguity or conflict for approval rather than adding
a framework scanner, production Adapter namespace, partial-batch system, or release commitment.

Cross-TICKET acceptance is not silently discharged by finishing these three TASKs. TICKET-00017's
[TASK-00065](../tasks/00065-TASK.md) owns supported Permission-removal/provisioning conflict proof;
[TASK-00066](../tasks/00066-TASK.md) owns actual rebinding conflicts and manual-create/provision preservation;
[TASK-00067](../tasks/00067-TASK.md) owns the guarded deletion/reintroduction scenario and final evidence map.
These checks remain downstream, not reverse blockers on declaration/provisioning, so the graph stays acyclic.

## Exclusions

Actual scanners, schema migrations, persistence adapters, deployment hooks, runtime interception, management UI,
CMS migration, and release/publication are consumer or later delivery work. No rollout matrix, experimentation,
historical release ledger, distributed deployment fencing, or automatic runtime provisioning is added.

## Acceptance Evidence

- [ ] Domain tests prove accepted/rejected name boundaries, immutable identities/names, required Permission binding,
      duplicate-name rejection, and OFF creation; registry duplicates identify a single Feature.
- [ ] Native PHP Attribute fixtures prove method-only, nonrepeatable metadata; registry/discovery tests distinguish
      complete empty discovery from failed/incomplete discovery, without a production scanner.
- [ ] Real package provisioning proves initial creation, repeat invocation, changed defaults, valid protected defaults,
      missing/invalid/unresolved required defaults, and the no-creation/unused-default case without grants or resets.
- [ ] Provisioning and activation-validation scenarios reject missing/invalid records and incomplete discovery without
      a false activation-ready outcome; publication failure is not represented as rollback of a confirmed commit.
- [ ] Failure/retry and concurrent-creation scenarios prove one atomic transaction per pass, including rollback of
      earlier inserts when a later insert fails, authoritative uniqueness, winner preservation, and no incomplete
      Feature. Retry after post-commit publication failure preserves committed records without duplicate creation
      facts. Package doubles are not real database proof.
- [ ] Deletion/reintroduction scenarios composed with TICKET-00017 prove a new identity, OFF state, and current default.
- [ ] Integration guidance and consumer-bindable scenarios identify scanner completeness, uniqueness/reference fences,
      configuration, and pre-activation obligations. Actual consumer conformance is separately qualified.
- [ ] Implementation passes `./bin/planning-check` and the full `./bin/build`, including exact production statement
      coverage. Planning validation alone is not implementation acceptance.

## Decision Links and Progress

Implements [WF-018](../wayfinder/tickets/WF-018-feature-preview-permission-binding.md),
[WF-019](../wayfinder/tickets/WF-019-missing-feature-records.md),
[WF-020](../wayfinder/tickets/WF-020-feature-naming-and-management.md), and
[WF-022](../wayfinder/tickets/WF-022-feature-package-integration-scope.md), within
[ADR 0001](../adr/0001-domain-application-package-boundary.md).
John approved TASK-00061–00063 and the method-only/nonrepeatable Attribute plus atomic-pass design. TASK-00061 has
no blocker; TASK-00062 waits on 00061 and TASK-00063 waits on 00062. TASK-00061 now implements the
[declaration/discovery contract](../../docs/feature-references.md) in the main checkout on
`feature/task-00061-feature-references`. Independent review accepted `0408f72` with all criteria passing and no findings;
independent QA passed six scenarios and 202 checks. TASK-00061 is done for accepted implementation/local verification.
John requested landing; [PR #99](https://github.com/johnnickell/fight-access-control/pull/99) is open against unchanged
`develop` at the initial publication checkpoint. Provisioning and preparation validation remain unimplemented;
the TICKET's composed acceptance is open, with no merge, release or consumer qualification claimed.
TICKET-00016 has [TASK-00064](../tasks/00064-TASK.md), blocked by TASK-00062; TICKET-00017 now has approved
TASK-00065–00067 and the concrete cross-TICKET evidence ownership above. EPIC TASK planning is complete. John
authorized TASK-00061 execution in the main checkout; downstream execution and worktree selection still require
authorization. Existing portfolio priorities are unchanged.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 61 | [TASK-00061](../tasks/00061-TASK.md) | Declare and register Feature references | done |
| 62 | [TASK-00062](../tasks/00062-TASK.md) | Provision registered Features without resetting choices | ready-for-agent |
| 63 | [TASK-00063](../tasks/00063-TASK.md) | Validate Feature preparation before activation | ready-for-agent |
