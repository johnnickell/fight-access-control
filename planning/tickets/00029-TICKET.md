---
id: T-00029
prd: PRD-00004
title: Make Agent Permission changes safe to retry
status: done
blocked_by:
---

# Make Agent Permission changes safe to retry

## Outcome

An authorized consumer can safely retry direct Agent Permission grants, revocations, and complete-set replacements.
An already-satisfied request completes normally without recording a change, while invalid authority and stale
concurrency expectations continue to fail hard.

## Scope

- In scope: desired-state Agent Permission behavior, complete-set normalization, assignment-revision semantics,
  authorization and reference validation, exact Permission resolution, transactional compare-and-replace,
  purpose-specific events, failure publication, the shared mutation behavioral matrix, and internal coordination.
- Out of scope: User Role changes, custom-Role Permission changes, direct User Permissions, Agent Roles, production
  adapters, framework retry policy, generic authorization messages, and the later Fight Common UnitOfWork type
  migration.

## Acceptance Criteria

- [ ] Granting an already-assigned Permission and revoking an already-absent Permission complete normally as
  desired-state no-ops.
- [ ] Complete replacement treats input as a set: duplicate value-equal IDs and input order do not cause rejection
  or manufacture a change, and stored assignments remain duplicate-free.
- [ ] Equal-set replacement is a no-op only after the expected Permission-assignment revision is confirmed current;
  a stale expected revision fails even when the submitted set matches authoritative state.
- [ ] Authorization, Agent existence, and every referenced Permission are validated before any apparent no-op is
  accepted.
- [ ] A no-op performs no repository replacement, advances no Permission-assignment revision or timestamp, and
  publishes no grant, revoke, replacement, or synthetic no-op event.
- [ ] Every real change uses one `commitTransactional()` boundary, one aggregate-owned transition, one
  compare-and-replace persistence operation, and its existing purpose-specific success event after commit.
- [ ] Final Permission-reference or compare-and-replace loss fails without partial authority change or success event.
- [ ] Every failure dispatches `CommandFailedEvent` for the original command and rethrows the identical `Throwable`.
- [ ] Repeated Application execution behavior is factored only into small final `@internal` concrete coordinators;
  public commands, authorization ports, events, aggregates, and repositories retain their specific contracts.
- [ ] The shared authorization-modification behavioral matrix proves real changes, no-op retries, reference and
  concurrency failures, write and revision outcomes, event ordering, and exception identity with exact coverage.

## Verification

- Focused Agent desired-state and complete-set Domain tests
- Agent Permission command-handler matrix and failure-ordering tests
- Public-boundary and architecture checks for internal coordinators
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Delivered desired-state direct Agent Permission grants, revocations, and complete-set replacements. No-op retries
validate authorization, the Agent, and Permission references before completing without a replacement, revision or
timestamp change, or success event; complete-set input is normalized and still rejects stale revisions. Verified by
focused Domain and handler tests, `./bin/planning-check`, and the canonical exact-coverage `./bin/build` on
2026-08-30.
