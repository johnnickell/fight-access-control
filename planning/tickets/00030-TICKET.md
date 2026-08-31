---
id: T-00030
prd: PRD-00004
title: Make User Role changes safe to retry
status: done
blocked_by:
---

# Make User Role changes safe to retry

## Outcome

An authorized consumer can safely retry assigning a Role to a User or removing it. An already-correct membership
completes normally without changing the User, while missing or concurrently changed authority still fails hard.

## Scope

- In scope: desired-state User Role assignment and removal, authorization and reference validation, User assignment
  revision and timestamp behavior, final Role-reference fencing, transactional compare-and-replace, existing events,
  failure publication, and extension of the shared mutation behavioral matrix.
- Out of scope: direct User Permissions, complete-set User Role commands, Agent Permission behavior, custom-Role
  Permission behavior, authentication Roles supplied only by frameworks, production adapters, and framework retry
  configuration.

## Acceptance Criteria

- [x] Assigning an already-assigned Role and removing an already-absent Role complete normally as desired-state
  no-ops.
- [x] Actor authorization, target User existence, and referenced Role existence are validated before either no-op is
  accepted.
- [x] A no-op performs no User repository replacement, advances no authorization-assignment revision or timestamp,
  and publishes no assignment, removal, or synthetic no-op event.
- [x] A real assignment or removal uses one `commitTransactional()` boundary, one User-owned state transition, one
  compare-and-replace persistence operation, and its existing purpose-specific success event after commit.
- [x] Role-reference or compare-and-replace authority loss fails without partial User state or a success event.
- [x] Users continue to receive effective Permissions only through Role membership; no direct User Permission model
  is introduced.
- [x] Every failure dispatches `CommandFailedEvent` for the original command and rethrows the identical `Throwable`.
- [x] The shared authorization-modification behavioral matrix proves User real changes and no-op retries alongside
  the Agent contract, including validation, writes, revisions, timestamps, event ordering, and failure identity.
- [x] Public commands, authorization ports, events, aggregate behavior, and Domain repository ownership remain
  purpose-specific while any reused coordinator stays final and `@internal`.

## Verification

- Focused User Role desired-state Domain tests
- User Role command-handler matrix, reference-fence, and failure-ordering tests
- Public-boundary and architecture checks
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Delivered aggregate-owned desired-state User Role assignment and removal with a non-writing final Role-reference
fence for no-ops and expected-plus-successor Role fencing for real changes. The public command/event boundary
remains purpose-specific; no direct User Permission model was introduced. Verified by `./bin/planning-check` and
the canonical `./bin/build`: 604 tests, 4,538 assertions, and exact statement coverage 4,597/4,597.
