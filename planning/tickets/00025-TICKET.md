---
id: T-00025
prd: PRD-00002
title: Require an operator-facing Agent name
status: ready-for-agent
blocked_by: T-00019
---

# Require an operator-facing Agent name

## Outcome

A maintainer must provide a bounded, human-friendly Agent name when provisioning an Agent, so safe administrative
reads can identify the correct machine authority without making a UUID the only operator-visible reference.

## Scope

- In scope: a required `AgentName` Domain value, normalization and bounds validation, Agent provisioning and
  persistence of that name, and a safe-read contract update for [T-00021](00021-TICKET.md).
- The name is an operator-facing description only. It is not unique, is not credential material, and never affects
  HMAC signing, credential identity or revision, authentication, or authorization decisions.
- Out of scope: Agent renaming, name uniqueness, consumer UI, production persistence schema, Agent Roles, and
  inclusion of the name in an authenticated principal.

## Acceptance Criteria

- [ ] Provisioning requires a non-empty, normalized, bounded Agent name; invalid input fails without persisting an
  Agent, credential, audit evidence, or success event.
- [ ] The Agent retains its required name independently of its stable Agent ID and sole HMAC credential state.
- [ ] The name is not present in raw-secret material, HMAC signing input, credential identity, or authorization
  checks.
- [ ] The safe Agent read delivered by T-00021 exposes the name with lifecycle and credential metadata, while the
  authenticated Agent principal remains a security snapshot rather than an administrative view.
- [ ] Tests cover valid and invalid names, secret-free failure behavior, persistence of the name, and exact coverage.

## Verification

- Focused Agent-name Domain and provisioning Application tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Record the verified outcome only when terminal.
