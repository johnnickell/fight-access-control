# Handle Attributes referencing missing Feature records

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Permission-based feature flags](../permission-based-feature-flags-map.md)
**Depends on:** [WF-017](WF-017-feature-availability-and-preview.md)

## Question

How should we prevent code Attributes from referencing absent Feature records, and what should runtime evaluation
do when the named Feature is nevertheless absent?

## Must decide

- Settled: unknown names must not grant availability; report an error rather than assume ON. Exact error type and
  transport presentation remain downstream.
- Settled: retain released Features as ON until code references are removed; deletion requires a live-code scan
  and must reject if checks remain.
- Settled: every check must be discoverable through an Attribute or an explicitly registered programmatic reference.
  John favors a registry, potentially a simple value collection; its exact API is not settled.
- Settled: code declares references; the database owns environment-specific status and testing Permission. Provision
  required records before activating referencing code; deployment preserves existing configuration.
- Settled: automatically provision missing registered Features OFF using `DEFAULT_FEATURE_PERMISSION`, configured
  by canonical Permission name and resolved to an ID. Missing/unresolvable defaults reject required creation;
  existing records retain their status and Permission choices.
- Settled: deletion checks current code references and registry entries; any match rejects deletion. Retry after
  deployment removes those references. Do not reserve names or fence future deployments.
- Settled: a later deployment reintroducing a deleted name provisions a new OFF record with the current default
  Permission. It does not recover the deleted record's status or Permission choice.
- Exact operational error types and invalid existing Permission behavior remain downstream/WF-018; an unknown
  Feature is an error, not an ON fallback.

## Required evidence

- John accepted blocking Permission deletion while any Feature references it in
  [WF-018](WF-018-feature-preview-permission-binding.md).
- John initially considered absent-means-ON, then accepted the safer approach: unknown names can be errors;
  released Features remain ON until references are removed. He requires Feature deletion to scan the live codebase
  for existing Feature Attributes and fail closed if checks remain.
- [WF-017](WF-017-feature-availability-and-preview.md) establishes ON as removal of the feature restriction only;
  ordinary action authorization still applies. Unknown-name behavior is settled by this decision, not inherited
  from a consumer's implementation.
- John explicitly bounded deletion to references or registered pointers in current code. A deployment removing them
  permits a later deletion retry; a later reintroduction by name creates a new Feature record. He rejected the need
  for the proposed cross-deployment fencing requirement.
- Establish validation coverage limits: enumerating Attributes can detect names declared in scanned code, but not
  arbitrary dynamic feature lookups or an intended gate whose Attribute was never written. A deployment check alone
  also does not prevent later data deletion or restoration drift.

## Considered options

1. **Absent means ON (not selected):** avoids breaking an old Attribute after record removal, but also removes the feature gate for
   typos, accidental deletion, and missing rollout data. Business authorization still applies, but ordinary authorized
   users could reach unreleased behavior intended only for testers.
2. **Unknown names do not grant availability (accepted):** diagnose the configuration problem; intentionally
   released features retain an ON record until references are removed from all supported running code versions.
3. **Explicit retirement evidence (not selected):** known retired names could pass while truly unknown names do not.
   Instead, later reintroduction creates a fresh OFF record; no tombstone or name reservation is required.

John accepted prevention through code-reference validation, guarded Feature deletion, and the rollout workflow:
a consumer build enumerates declared feature names; a consumer deployment check validates them against the target
environment before serving traffic. Feature deletion must check live code references, not only database foreign keys.
Do not auto-create missing features as ON to make validation pass.

## Accepted so far

- Unknown Feature names do not imply ON; report a configuration error without granting feature access.
- Retain ON records while Feature Attributes remain in live code.
- The only supported Feature deletion path must scan live code for references and reject deletion while checks remain.
- Every check must be discoverable through an Attribute or an explicitly registered programmatic reference. John
  accepted this restriction and the registry/database ownership split below. The registry can be a simple collection
  of names; its concrete API and the provisioning interface remain open.
- John confirmed the code-first declaration/database-before-activation workflow, preserving existing configuration
  across deployments and retaining ON records through reference-removal deployment and older-version drainage.
- Feature naming, required creation inputs, status management, and backward-transition confirmation are recorded in
  [WF-020](WF-020-feature-naming-and-management.md), not duplicated here.

## Accepted current-reference deletion boundary

- At a deletion attempt, inspect current code references and registered pointers. If either contains the name, reject
  deletion regardless of Feature status. Removing just the Attribute while retaining registration is insufficient.
- If the check cannot establish absence, fail closed; scanner failure is not an empty reference set. The consumer
  supplies current-code discovery behind a package contract; this package gains no production filesystem adapter.
- Retry deletion after deployment removes the references. Do not add cross-deployment locks, future-name reservations,
  or a historical release registry as prerequisites for this feature.
- A later deployment may register the same name. Normal pre-activation provisioning creates a new record with a new
  identity, OFF status, and the then-configured default Permission. Deleted settings are not resurrected.
- Attribute scanning and explicitly registered programmatic references form the supported discovery boundary.
  Unregistered dynamic checks remain unsupported; concrete compliance checks belong to implementation design.
- This is a current-reference guarantee, not atomic coordination with arbitrary concurrent deployments. A scan alone
  cannot prove that another version will not become active afterward. If overlap/drift leaves a runtime lookup without
  a record, the accepted unknown-name error applies; it must never turn into ON or request-time provisioning.
- Consumer deployment wiring must identify the actual current code, not an unrelated checkout. The package cannot
  protect arbitrary privileged SQL or certify undisclosed running code. Exact discovery mechanics and failure
  diagnostics belong to consumer integration, without expanding this into a distributed deployment coordinator.

## Accepted authoring and rollout workflow

**Code first for declaration; database first before that code serves traffic.** Separate the code's reference registry
from the database's operational state rather than making them competing stores of status or Permission assignment.

1. In the feature branch, declare the feature name in code and use it in Attributes or registered programmatic checks.
   The registry can be a simple immutable set of validated names: combine discovered Attributes with explicit
   programmatic declarations. Its concrete class/format is not yet selected.
2. Produce a reference inventory for the candidate release and validate names/check declarations without needing
   access to the production database during the product build.
3. Before activating that release in an environment, create missing Feature records through an authorized setup step,
   supplying the required testing Permission. New records start OFF, as recorded in
   [WF-020](WF-020-feature-naming-and-management.md). A name-only registry cannot choose the Permission; the setup
   interface supplies `DEFAULT_FEATURE_PERMISSION` by canonical name and resolves its existing database ID.
4. Validate all candidate references against the target database before traffic/workers use the new code. Missing or
   invalid definitions block activation; runtime checks neither auto-create records nor assume ON. Repeated setup
   preserves existing Feature status and Permission selection; deployment must not reset operator changes.
5. After deployment, administrators select PREVIEW for testing, then ON for general availability. The database owns
   those environment-specific choices, including the testing Permission; a deployment registry must not overwrite them.
6. To retire a released flag, retain ON, remove Attributes/programmatic references and registry declarations, deploy
   the cleanup and drain older users of the code. Only then request deletion through the live-reference guard.
   Absence from one new release's registry never implies permission to delete the record.

An early database record is harmless and may be provisioned ahead of code. The required ordering is configuration
before activation, not necessarily database work before developers author code. Automatic pre-activation provisioning
is selected below; manual pre-creation remains possible. The exact command/API is downstream. The registry describes
current declared references; it is not a historical release ledger. John's current-reference deletion boundary above
supersedes the earlier proposal for cross-deployment coordination. Later reintroduction uses ordinary provisioning.

## Accepted default-Permission provisioning

John agreed to automatic pre-activation provisioning using `DEFAULT_FEATURE_PERMISSION`, configured by Permission
name and resolved to its database ID. Newly discovered Features start OFF; existing choices survive redeployment and
default changes. If creation is needed but the default cannot resolve, provisioning fails rather than creating
incomplete Features. The UI can subsequently update the per-feature binding, as recorded in
[WF-018](WF-018-feature-preview-permission-binding.md).

The default is a creation input, not a runtime authorization fallback. Each created Feature persists its own Permission
ID; code does not duplicate the Feature's operational configuration.

Accepted behavior and downstream implementation constraints:

- Run authorized registry provisioning as part of pre-activation deployment/setup, not during request evaluation.
- Configure the existing Permission by canonical name (for example `TEST_UNRELEASED_FEATURES`) and resolve its ID in
  the target environment. No Permission name is built into the package; consumers supply configuration through the
  package boundary rather than requiring package code to read environment variables.
- Create only missing registered Features, always OFF, with that Permission ID. Never create a Permission implicitly
  or grant it to a User/Agent as a side effect. Respect existing Permission tier rules.
- If creation is needed and the default is absent, invalid, or unresolved, reject provisioning and block activation;
  do not create a Feature without a Permission. If no creation is needed, do not require an unused default.
- Preserve existing records' status and Permission choices, including after the default changes. Concurrency-safe
  create-if-absent behavior must not overwrite a Feature another operator or deployment has just created.
- Applications may create a Feature ahead of provisioning with a different Permission, or select a different Permission
  in management before enabling PREVIEW. No runtime default/override hierarchy is introduced.
- Names absent from the registry are never provisioned on demand. Runtime unknown-name evaluation remains an error.

Exact configuration type, creation batch/failure atomicity, and caller authorization remain to be specified. This
workflow automates preparation; it does not bypass deployment validation, deletion protection, or status confirmation.

## Resolution boundary

Set missing-Feature semantics and bounded consumer prevention obligations. AccessControl remains framework-neutral;
consumer tooling owns code scanning, deployment integration, and concrete enforcement adapters. Exact scan mechanics,
HTTP responses, runtime wiring, and migration execution follow later. This decision grants no implementation authority.

## Resolution

Closed by John's accepted workflow and explicit current-reference scope. Unknown names error; every check is
discoverable; code declares references and the database owns status/Permission choices. Provision missing registered
Features OFF using the configured Permission name resolved to an ID, preserving existing choices and rejecting
required creation when that default cannot resolve. Reject deletion while current references or registration remain,
or absence cannot be established. Retry after removing them; a later reintroduced name gets a fresh record under the
same provisioning rules. No cross-deployment fencing or historical name reservation is required.

This resolves planning behavior, not implementation qualification or a claim that concurrency cannot exist. Exact
provisioning API, batch failure behavior, scanner integration, and typed operational diagnostics remain downstream.
WF-018 still owns invalid Permission-reference/reclassification policy, and WF-020 owns naming/management details.
