# ADR 0009: Non-null Custom Permission Tier

- Status: accepted for v0.4.0 planning; implementation pending
- Date: 2026-09-25

## Decision

Every Permission has a non-null tier. A runtime-created custom Permission starts `ADMIN_SAFE` and cannot be
reclassified as `SUPER_ADMIN_ONLY`; only managed policy may define the protected tier. This keeps one tier vocabulary
and avoids treating a null tier as implicit authority. `ADMIN_SAFE` makes a Permission eligible for delegation, while
the package command invokes consumer-supplied actor and target authorization before changing a Role or Agent.

The accepted decision and its boundary are recorded in
[WF-012](../wayfinder/tickets/WF-012-custom-permission-delegation.md). Public Permission/query contracts and consumer
persistence must move to non-null tiers. Fight Agent OS has no stored Permissions, so it has no existing grant data to
convert; malformed or unexpected null authority fails closed pending the separate remediation decision.
