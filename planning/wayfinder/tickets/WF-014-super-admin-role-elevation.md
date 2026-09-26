# Set Super Admin assignment and removal guarantees

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** John's decision and evidence of the consumer's confirmation/audit requirements.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011

## Question

Which authority, confirmation, audit, and last-admin rules govern assigning and removing the designated managed
Super Admin Role?

## Must decide

- Which assignment/removal safeguards are package invariants, given [WF-013](WF-013-target-aware-role-administration.md)
  has settled that the actor-only User Role-assignment port is removed and caller authorization belongs to the
  application builder. The original proposal asks consumers to require both ordinary role-association authority
  and explicit `ASSIGN_SUPER_ADMIN`; do not assume package authorization.
- How the consumer proves confirmation and records an audit without a forged command flag or leaked internal state.
- Removal authority and confirmation independent of `ASSIGN_SUPER_ADMIN`; self-removal, last-admin, disabled/deleted
  accounts, bootstrap, recovery, idempotent no-op, and concurrent assignment/removal behavior.
- How renamed custom Roles, stale IDs, inconsistent managed policy, and alternate adapters fail closed.

## Required evidence

- Inspect current assign/remove handlers, User assignment revisions, repository fences, principal resolution, and
  the consumer's actual permission definitions and confirmation/audit protocol. Test ordinary and elevated targets.

## Resolution boundary

Set elevation and de-elevation policy. Historical memberships and atomic proof remain downstream.

## Resolution

Open; no policy accepted.
