---
id: T-00030
prd: PRD-00004
title: Make User Role changes safe to retry
status: ready-for-agent
blocked_by: T-00029
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

- [ ] Assigning an already-assigned Role and removing an already-absent Role complete normally as desired-state
  no-ops.
- [ ] Actor authorization, target User existence, and referenced Role existence are validated before either no-op is
  accepted.
- [ ] A no-op performs no User repository replacement, advances no authorization-assignment revision or timestamp,
  and publishes no assignment, removal, or synthetic no-op event.
- [ ] A real assignment or removal uses one `commitTransactional()` boundary, one User-owned state transition, one
  compare-and-replace persistence operation, and its existing purpose-specific success event after commit.
- [ ] Role-reference or compare-and-replace authority loss fails without partial User state or a success event.
- [ ] Users continue to receive effective Permissions only through Role membership; no direct User Permission model
  is introduced.
- [ ] Every failure dispatches `CommandFailedEvent` for the original command and rethrows the identical `Throwable`.
- [ ] The shared authorization-modification behavioral matrix proves User real changes and no-op retries alongside
  the Agent contract, including validation, writes, revisions, timestamps, event ordering, and failure identity.
- [ ] Public commands, authorization ports, events, aggregate behavior, and Domain repository ownership remain
  purpose-specific while any reused coordinator stays final and `@internal`.

## Verification

- Focused User Role desired-state Domain tests
- User Role command-handler matrix, reference-fence, and failure-ordering tests
- Public-boundary and architecture checks
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Record the verified outcome only when terminal.
