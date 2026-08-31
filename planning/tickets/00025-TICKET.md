---
id: T-00025
prd: PRD-00002
title: Require an operator-facing Agent name
status: done
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

- [x] Provisioning requires a non-empty, normalized, bounded Agent name; invalid input fails without persisting an
  Agent, credential, audit evidence, or success event.
- [x] The Agent retains its required name independently of its stable Agent ID and sole HMAC credential state.
- [x] The name is not present in raw-secret material, HMAC signing input, credential identity, or authorization
  checks.
- [x] The safe Agent read delivered by T-00021 exposes the name with lifecycle and credential metadata, while the
  authenticated Agent principal remains a security snapshot rather than an administrative view.
- [x] Tests cover valid and invalid names, secret-free failure behavior, persistence of the name, and exact coverage.

## Verification

- Focused Agent-name Domain and provisioning Application tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Added the `AgentName` Domain value with trimming and a 120-character bound, then required it for synchronous Agent
provisioning. The aggregate retains the normalized name independently of credential state. Invalid names fail before
any transaction or credential generation, publish only the existing secret-free failure evidence, and persist no
Agent or audit evidence. T-00021 owns the later safe-read projection of the retained name.

Verified with the focused Agent suites, `./bin/planning-check`, and the complete canonical quality gate: 524 tests,
3,734 assertions, and exact 3,879/3,879 statement coverage, plus PHPCS, PHPStan, architecture, package-boundary,
Rector, documentation, and production-autoload checks.
