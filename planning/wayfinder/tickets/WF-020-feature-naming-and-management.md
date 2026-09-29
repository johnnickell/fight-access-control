# Define Feature naming and management transitions

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Permission-based feature flags](../permission-based-feature-flags-map.md)
**Depends on:** [WF-017](WF-017-feature-availability-and-preview.md)

## Question

What naming and management contract lets consumers create Features, select their testing Permissions, and safely
change availability without duplicating feature policy in their UI?

## Must decide

- Settled: dedicated FeatureName validation uses 1–128 ASCII characters, lowercase letters/digits with single hyphen
  separators, starting with a letter; reject invalid input without normalization.
- Settled: names are unique in the Feature catalog and immutable after creation; no rename operation, aliases,
  or automatic rewriting of code references.
- Settled: new Features start OFF under the accepted rollout workflow. The UI warns and requires confirmation
  for ON-to-PREVIEW/OFF; no additional transition restrictions are introduced here.
- Settled: operator management is through the consumer UI; its ON-to-PREVIEW/OFF workflow owns warning and
  confirmation. No general management CLI or independent public management API is in scope. Automatic deployment
  provisioning remains a separate capability.
- Settled: reject stale updates, refresh, and reconfirm if the new transition still leaves ON. Keep protection
  lightweight; John permits a simple revision for optimistic concurrency, not a larger coordination protocol.
- Settled: consuming projects authorize Feature management. SUPER_ADMIN_ONLY is a likely consumer policy, not
  a package requirement. No UI or adapter implementation is implied by planning this behavior.

## Required evidence

- John requested that Feature creation supply a name and the Permission used for testing, a list with an off/preview/on
  toggle, and a warning with required confirmation when moving backward from ON.
- [WF-017](WF-017-feature-availability-and-preview.md) owns the exact string-backed FeatureStatus and availability
  semantics. [WF-018](WF-018-feature-preview-permission-binding.md) owns the mandatory per-feature Permission binding,
  persisted by ID and selected in management UI. [WF-019](WF-019-missing-feature-records.md) owns Feature deletion safety.
- Existing [PermissionName](../../../src/Domain/AccessControl/Permission/PermissionName.php) validates uppercase words
  separated by underscores (`^[A-Z]+(?:_[A-Z]+)*$`). It does not normalize arbitrary strings or accept digits. This is
  a source reference for a dedicated validated Feature name, not a reason to reuse PermissionName for features.
- Acceptance examples should cover invalid/duplicate names, creation without a valid Permission, each status selection,
  cancellation and confirmation of ON-to-PREVIEW/OFF, and concurrent changes. Exact criteria follow the open choices.

## Accepted so far

- Creation includes a Feature name and its testing Permission.
- John accepted a dedicated FeatureName value used consistently by registry declarations, Attributes, persisted
  records, and management validation: 1–128 characters, lowercase ASCII letters and digits, single hyphen separators,
  and a leading letter. No spaces, underscores, repeated hyphens, or trailing hyphens. Reject invalid input rather
  than silently trimming, lowercasing, or otherwise normalizing it. Examples: `new-checkout`, `agent-tools-v2`,
  `dashboard`; single-letter names are valid.
- John accepted unique, immutable names. A different name means introducing a new Feature and retiring the old one
  through WF-019's guarded deletion workflow; no aliases or automatic code-reference rewriting. Uniqueness applies
  to existing records, not a permanent historical reservation: after deletion, WF-019 permits reintroducing the name
  as a fresh OFF record. Repeated registry references to the same name identify one Feature, not duplicate records.
- New Features start OFF. John accepted this recommendation as part of the code-declaration/database-setup rollout
  workflow in [WF-019](WF-019-missing-feature-records.md). Redeployment preserves existing status and Permission choices.
- The feature list exposes a three-way status control for OFF, PREVIEW, and ON.
- Moving from ON back to PREVIEW or OFF must warn and require confirmation; a bare toggle must not silently apply
  those transitions.
- John accepted UI-based operator management for status changes, Permission changes, and guarded deletion. The
  warning/confirmation belongs to that consumer workflow; its backend still invokes framework-neutral package
  operations. This does not introduce a package-wide human-approval protocol or general management CLI/API.
- Automatic pre-activation provisioning remains separate: create missing registered Features OFF and leave existing
  status and Permission selections untouched. A deployment hook is not an operator-management CLI.
- Naming or status controls do not bypass ordinary caller authorization or the deletion guard from WF-019.

## Accepted lightweight concurrency and management authority

- John accepted rejecting stale updates and refreshing the displayed state, with renewed confirmation if the intended
  transition still leaves ON. He emphasized low expected contention and avoiding disproportionate effort.
- A simple expected revision/optimistic update is acceptable. Exact revision mechanics belong to implementation design;
  do not add distributed coordination, interactive edit locks, or a separate approval system for this rare case.
- Consumers protect management entry points with their own authorization policy. John expects management may use a
  SUPER_ADMIN_ONLY Permission and often a single administrator, depending on the project. Neither restriction is
  hard-coded into Feature behavior; low expected concurrency is not a single-administrator invariant.
- The testing Permission attached to a Feature does not itself grant authority to manage that Feature.

## Resolution boundary

Set observable naming and management behavior. Exact widget design, framework Attribute placement, persistence
adapters, consumer HTTP composition, and implementation slicing follow later. Do not add production UI to AccessControl
or a general management CLI to this scope.

## Resolution

Closed. FeatureName has the accepted grammar/length, uniqueness, and immutability. Features start OFF; operators
manage status, Permission selection, and guarded deletion through the consumer UI. Moving from ON to PREVIEW/OFF
requires warning and confirmation in that workflow. Stale updates are rejected without overwriting another change;
refresh and reconfirm as appropriate, using lightweight optimistic concurrency rather than a larger coordination scheme.
Management authorization belongs to the consuming project, with no package-mandated SUPER_ADMIN_ONLY policy or
single-administrator assumption. Automatic pre-activation provisioning remains separate and never resets existing choices.

Exact revision and UI/backend confirmation mechanics are implementation design, not additional planning blockers.
This closure authorizes neither implementation nor consumer UI/CLI publication.
