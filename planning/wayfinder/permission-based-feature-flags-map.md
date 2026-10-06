# Wayfinder Map: Permission-based feature flags

**Label:** `wayfinder:map`
**Status:** Closed

> This map is an index, not a store. Each material decision belongs to one linked decision ticket.

## Destination

Plan framework-neutral feature flags for Fight AccessControl, informed by Fight CMS, so selected Users and Agents
can exercise unreleased features in production while ordinary principals cannot. Preview eligibility should use
Permissions rather than direct Role checks; `TEST_UNRELEASED_FEATURES` is John's illustrative consumer Permission,
not an approved hard-coded package constant.

**Done** = every linked decision is closed, remaining fog is resolved or explicitly excluded, and the map links to
an approved EPIC handoff. That handoff is [EPIC-00010 — Permission-Based Feature Flags](../epics/00010-EPIC.md),
approved by John. Requirement TICKET and implementation TASK decomposition follow separately.

## Notes

- John requested isolated Wayfinder planning, not production implementation, publication, or release. Worktree:
  `.runs/worktree/feature-flags-planning/`, branch `feature/feature-flags-planning`, from local `develop`
  `ea1e316c0cfa3499e73b213f538257bfd1ee3888`. Unrelated main-checkout work is excluded.
- Private consumer-source inspection informed the discussion; its repository/revision/file provenance is retained
  in ignored local planning evidence, not published here. Inspection was not executed testing or consumer qualification.
- AccessControl already has a shared Permission-checking contract for authenticated Users and Agents. Users obtain
  Permissions through Roles; Agents have direct Permissions. Permission-based evaluation does not introduce direct
  User Permission assignments or Agent Roles.
- Package Domain/Application ownership and consumer-owned persistence, transport, framework composition, and runtime
  remain the existing boundary. Consumer framework-specific authentication integrations stay outside this package.
- The user direction is Permission-based preview for both principal types. WF-017 settles the three-state behavior
  and string-backed `FeatureStatus` enum. WF-018 records accepted per-feature Permission ownership, persisted ID
  reference, mandatory Permission selection at creation in every status, selection-based management, and a
  feature-name-only Attribute. Permission deletion is blocked while any Feature references it. Any currently defined
  Permission is eligible under existing tier/reclassification rules. Missing Permission references fail closed;
  consumers may present 404 like OFF while retaining an internal diagnostic. WF-019 settles missing Feature records,
  provisioning, and the current-reference deletion boundary; no cross-deployment fencing is required.

## Decisions so far

1. **[WF-017 — Define feature availability and preview semantics](tickets/WF-017-feature-availability-and-preview.md) is settled.** John accepted string-backed `FeatureStatus` with `OFF = 'off'`, `PREVIEW = 'preview'`, and `ON = 'on'`: deny everyone, require configured preview Permission authority, or remove the feature restriction respectively. Ordinary action authorization still applies.
2. **[WF-018 — Bind preview eligibility to configurable Permissions](tickets/WF-018-feature-preview-permission-binding.md) is settled.** Every Feature is created with a Permission, persisted by ID and chosen through a management selector, regardless of status. Applications may share or vary Permissions; the UI can update each Feature's stored Permission after creation. The package hard-codes none. The Attribute supplies only the feature name. Permission deletion is blocked while any Feature references it. Any currently defined Permission is eligible, including protected Permissions, with existing principal/tier rules unchanged. Missing Permission references deny availability without default substitution; consumers may present 404 like OFF while preserving internal diagnostics.
3. **[WF-019 — Handle Attributes referencing missing Feature records](tickets/WF-019-missing-feature-records.md) is settled.** Unknown names error. Code declares discoverable references; automatic pre-activation provisioning creates missing records OFF using `DEFAULT_FEATURE_PERMISSION` resolved by name to ID, preserving existing choices and rejecting required creation if the default cannot resolve. Deletion rejects any current code reference or registry entry and fails closed if absence cannot be established. Retry after references are removed; later reintroduction creates a fresh OFF record with the current default. John declined cross-deployment fencing and historical name reservation.
4. **[WF-020 — Define Feature naming and management transitions](tickets/WF-020-feature-naming-and-management.md) is settled.** Creation supplies a name and testing Permission. FeatureName accepts 1–128 lowercase ASCII letters/digits with single hyphen separators, starting with a letter; invalid input is rejected without normalization. The list offers OFF/PREVIEW/ON selection, with a warning and required confirmation when moving backward from ON. New Features start OFF. Names are unique and immutable, with no aliases or rename operation; deleted names may later identify a fresh record under WF-019. Operator management and backward-transition confirmation are consumer UI workflows, with no general management CLI or independent public management API. Automatic provisioning remains separate. Stale updates reject and refresh/reconfirm using lightweight optimistic concurrency; management authorization is consumer-owned, not hard-coded SUPER_ADMIN_ONLY.
5. **[WF-021 — Define Feature evaluation freshness](tickets/WF-021-feature-evaluation-freshness.md) is settled.** Each explicit check reads current stored Feature status and testing Permission, while using the existing authenticated principal snapshot. No shared cache or retained Feature settings between worker jobs; OFF affects subsequent checks without automatically cancelling work already admitted.
6. **[WF-022 — Confirm Feature package and consumer integration scope](tickets/WF-022-feature-package-integration-scope.md) is settled.** John approved a package-only EPIC with portable Attribute/registry contracts and package-owned policy; consumers supply scanning, runtime enforcement, persistence, UI, and deployment wiring. Actual CMS adoption remains separate. [EPIC-00010](../epics/00010-EPIC.md) owns the handoff and downstream requirement/design details.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-017 | [Define feature availability and preview semantics](tickets/WF-017-feature-availability-and-preview.md) | Grilling | HITL | **Closed** | — |
| WF-018 | [Bind preview eligibility to configurable Permissions](tickets/WF-018-feature-preview-permission-binding.md) | Grilling | HITL | **Closed** | WF-017 |
| WF-019 | [Handle Attributes referencing missing Feature records](tickets/WF-019-missing-feature-records.md) | Grilling | HITL | **Closed** | WF-017 |
| WF-020 | [Define Feature naming and management transitions](tickets/WF-020-feature-naming-and-management.md) | Grilling | HITL | **Closed** | WF-017 |
| WF-021 | [Define Feature evaluation freshness](tickets/WF-021-feature-evaluation-freshness.md) | Grilling | HITL | **Closed** | WF-017, WF-018, WF-020 |
| WF-022 | [Confirm Feature package and consumer integration scope](tickets/WF-022-feature-package-integration-scope.md) | Grilling | HITL | **Closed** | WF-017, WF-018, WF-019, WF-020, WF-021 |

## Blocking relationships

```text
WF-017 → WF-018, WF-019, WF-020
WF-017 + WF-018 + WF-020 → WF-021
WF-017 through WF-021 → Integration scope (WF-022) → EPIC handoff
```

## Frontier

None. All six decisions are closed. [EPIC-00010 — Permission-Based Feature Flags](../epics/00010-EPIC.md) owns the
approved destination and subsequent requirement TICKET decomposition; implementation TASK planning follows separately.

## Remaining fog disposition

WF-022's approved scope resolves or assigns every remaining area without treating technical design as completed:

- Package Attribute/registry ownership and consumer scanning, enforcement, UI, authorization, and environment
  composition boundaries are settled. Actual CMS adoption/migration is a separate consumer follow-up.
- EPIC-00010 assigns exact interfaces and Attribute targets/composition, anonymous wiring, operational errors,
  commands/queries/events, provisioning failure/retry mechanics, adapter contracts, and scenario/conformance evidence
  to requirement/design work. Genuine product ambiguities discovered there require a new decision, not assumptions.
- Compatibility and release-version assessment remain later work; this handoff neither qualifies a consumer nor
  authorizes a release, migration, or deployment.

## Out of scope

- Production code, consumer adapters, schema changes, UI implementation, migration, commits/push/PR publication,
  release, and deployment in this planning session unless separately requested.
- A general policy engine, percentage rollouts, experimentation analytics, or billing entitlements without a new request.
- Hard-coding `TEST_UNRELEASED_FEATURES`, bypassing normal business authorization, direct User Permission assignment,
  Agent Roles, or changing the existing protected-Permission tier rules as an incidental feature-flag change.
