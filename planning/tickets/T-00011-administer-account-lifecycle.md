---
id: T-00011
title: Administer account lifecycle
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00006
  - T-00009
branch: feature/t-00011-administer-account-lifecycle
---

# Administer account lifecycle

## Outcome

Authorized administrators can disable, enable, soft-delete, and restore one stable identity while user and session
administration remains safe, paginated, and auditable.

## Acceptance criteria

- [ ] Disable immediately revokes sessions; enable does not restore prior sessions.
- [ ] Delete and restore retain stable identity and canonical-email uniqueness without permitting duplicate reinvitation.
- [ ] User and session queries return typed pages of immutable safe views.
- [ ] Every classified sensitive lifecycle mutation has durable secret-free audit evidence.
- [ ] Tests prove authorization, state transitions, session effects, canonical uniqueness, and safe query output.

## Exclusions

No retention-job implementation, database soft-delete mapping, admin UI, or HTTP endpoint.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
