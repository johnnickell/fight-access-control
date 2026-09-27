# ADR 0009: Non-null Custom Permission Tier

- Status: accepted; implemented for the unreleased v0.4.0 package
- Date: 2026-09-25

## Decision

Every Permission has a non-null tier. A runtime-created custom Permission starts `ADMIN_SAFE` and cannot be
reclassified as `SUPER_ADMIN_ONLY`; only managed policy may define the protected tier. This keeps one tier vocabulary
and avoids treating a null tier as implicit authority. `ADMIN_SAFE` makes a Permission eligible for delegation.
The application builder protects command entry points and owns caller and target authorization, as clarified by
WF-013. The package enforces tier eligibility without invoking a consumer authorization port.

The accepted decision and its boundary are recorded in
[WF-012](../wayfinder/tickets/WF-012-custom-permission-delegation.md). Public Permission/query contracts now expose
non-null tiers through [TASK-00041](../tasks/00041-TASK.md); consumer persistence adoption remains separate.
Fight Agent OS has no stored Permissions, so it has no existing grant data to convert. Malformed or unexpected
null authority fails closed. [TASK-00045](../tasks/00045-TASK.md) implements the separate protected-promotion
decision by rejecting forbidden membership without automatic remediation. Consumers follow the
[v0.4.0 migration guide](../../docs/permission-tier-v0.4-migration.md) before adopting the package.
