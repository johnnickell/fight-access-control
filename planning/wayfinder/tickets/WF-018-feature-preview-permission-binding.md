# Bind preview eligibility to configurable Permissions

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Permission-based feature flags](../permission-based-feature-flags-map.md)
**Depends on:** [WF-017](WF-017-feature-availability-and-preview.md)

## Question

How does the consumer designate the Permission authority required to preview a feature without the package
hard-coding `TEST_UNRELEASED_FEATURES` or checking specific Roles?

## Must decide

- Settled: the Feature entity owns one preview Permission binding. Applications may reuse the same Permission across
  features or select different Permissions; no runtime application-wide binding or override hierarchy is required.
  WF-019 owns the accepted configured creation default for automatic provisioning.
- Settled: every Feature must be created with a Permission, regardless of status; it is not optional for OFF or ON.
- Settled: persist the Permission reference by ID; management selects an existing Permission, and the Attribute
  designates only the feature name.
- Settled: block Permission deletion while any Feature references it, including OFF and ON Features. Reassign or
  remove referencing Features first.
- Settled: any currently defined Permission may be selected, including SUPER_ADMIN_ONLY. Existing assignment and
  reclassification rules apply; Features add no new tier restriction or bypass.
- Settled: an existing Feature with a missing referenced Permission fails closed. The consumer may present it as
  unavailable/404, like OFF, rather than exposing configuration details; no default-Permission substitution.
- Settled: Users and Agents use their existing Permission authority, without automatic Role/super-admin bypass.
  A protected Permission therefore supports eligible human preview only, not Agent preview.

## Required evidence

- John's example: a consumer Permission such as `TEST_UNRELEASED_FEATURES` designates selected Users or Agents who
  may access production features that ordinary principals cannot. The name is illustrative, not a required constant.
- At AccessControl base `ea1e316c0cfa3499e73b213f538257bfd1ee3888`,
  [SecurityContext](../../../src/Application/AccessControl/Authorization/Service/SecurityContext.php) delegates
  `hasPermission(PermissionName)` to one selected
  [AuthenticatedAuthority](../../../src/Domain/AccessControl/Authorization/AuthenticatedAuthority.php).
  [AuthenticatedUserPrincipal](../../../src/Domain/AccessControl/Authorization/AuthenticatedUserPrincipal.php) and
  [AuthenticatedAgentPrincipal](../../../src/Domain/AccessControl/Agent/AuthenticatedAgentPrincipal.php) both expose
  Permission checks. These existing checks are a candidate integration point, not a finalized feature evaluator API.
- The [project context](../../../CONTEXT.md) defines User Permissions through Roles and direct Agent Permissions.
  [AgentPermissionAssignmentCoordinator](../../../src/Application/AccessControl/Agent/CommandHandler/AgentPermissionAssignmentCoordinator.php)
  rejects assignments outside `ADMIN_SAFE`. A Permission intended for both Users and Agents must respect that tier;
  `SUPER_ADMIN_ONLY` is human-only under the existing policy. `ADMIN_SAFE` does not mean freely grantable: consumers
  still protect assignment entry points.
- Confirm at least one shared-tester example and whether different features need different tester cohorts. Trace a
  User's Role-derived Permission and an Agent's direct Permission to identical availability outcomes.

## Considered options

- **Consumer-wide binding:** inject/configure one existing Permission for all unreleased features. Simple for a common
  tester cohort; unsuitable if different features require different preview audiences.
- **Per-feature binding (accepted):** each Feature entity selects one existing Permission. Reusing the same consumer-defined Permission
  across definitions supports a shared tester cohort without hard-coding it; distinct Permissions support narrower cohorts.
- A runtime shared default plus overrides is not selected. John's accepted creation-default decision in
  [WF-019](WF-019-missing-feature-records.md) supplies an initial stored Permission ID, not a runtime fallback.

## Resolution boundary

Set Permission ownership, selection, and reference semantics after availability behavior is settled. Do not create
new principal types, direct User grants, Agent Roles, a generic policy engine, or change the protected-tier invariant.
Concrete storage and reconciliation mechanisms, consumer grant administration, remaining public API design, and
conformance follow later. The accepted ID reference, selection-based management interaction, and feature-name-only
Attribute are interface constraints, not authority to implement a database adapter, UI, or framework listener here.

## Accepted so far

John chose the Feature entity as the owner of its preview Permission binding: an application can use the same
Permission for every feature or designate a different Permission for individual features. This settles per-feature
granularity and a single Permission rather than any/all lists. The package hard-codes no Permission name.

John further specified:

- Every Feature must be created with a Permission, regardless of its initial status. John rejected making it optional
  for OFF and ON. This is required configuration, not an instruction to enforce the Permission in OFF or ON:
  WF-017 still denies everyone in OFF and removes the feature restriction in ON.
- Block Permission deletion while any Feature references it, regardless of Feature status. Reassign or remove those
  Features first. John explicitly accepted this restriction; the atomic enforcement mechanism remains downstream.
- Any currently defined Permission is eligible as a Feature's testing Permission, including SUPER_ADMIN_ONLY.
  John confirmed: “we should support any currently designated permission.” This also permits such a Permission as
  the provisioning default; it does not make that Permission assignable to Agents. Existing tier, membership, and
  reclassification rules continue unchanged. A Feature reference is not a grant and adds no tier-promotion blocker.
- The database reference to the Permission is its ID, not its name.
- The management UI offers a dropdown or equivalent selection of an existing Permission; users need not enter IDs.
  John explicitly confirmed that the UI can update a Feature's Permission after creation, including replacing the
  initial Permission chosen through the accepted configured provisioning default. Persist the new per-feature ID;
  do not change the shared Permission definition or grant it to principals. Exact widget, labels, search behavior,
  update authorization, and any confirmation for changing a live PREVIEW audience remain downstream.
- The Attribute needs only the feature name. It does not duplicate the Permission binding; evaluation obtains that
  binding from the named Feature. Attribute placement and enforcement wiring remain downstream.
- A missing referenced Permission is invalid configuration and must not grant access. John accepted a configuration
  error or a consumer-facing 404, as if the Feature were OFF. Preserve the diagnostic internally while allowing the
  consumer adapter to conceal the feature's availability. Do not mutate its stored status, recreate the Permission,
  or substitute DEFAULT_FEATURE_PERMISSION. A stored ON value is not permission to ignore known invalid configuration.
  The framework-neutral package does not return HTTP responses; exact result/exception types and adapter mapping
  are implementation design. This does not classify database outages as 404 or change WF-019's unknown-name error.

The persisted identity is settled; exact entity accessor signatures and evaluation API changes are not. Existing
name-based authorization helpers must not cause an ID-bound feature to silently rebind to a recreated Permission
with the same name.

## Resolution

Closed. Each Feature requires one existing Permission, persisted by ID, selectable and editable in management.
Any currently defined Permission is eligible under unchanged tier, reclassification, and User/Agent assignment rules.
No Role bypass or hard-coded Permission is introduced. Referencing Features block Permission deletion. If invalid
stored data nevertheless leaves a missing Permission, deny availability without a default substitution; a consumer
may present 404 like OFF while retaining the internal configuration diagnostic.

WF-019 owns the creation default and missing-Feature/provisioning/deletion workflow. Exact evaluator API, diagnostic
mapping, reference enforcement mechanics, and caller protection are downstream implementation design; freshness
remains map fog. This closure is a planning decision, not implementation authority.
