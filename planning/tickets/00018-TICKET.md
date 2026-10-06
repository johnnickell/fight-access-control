---
id: TICKET-00018
epic: EPIC-00011
title: Rename an Agent without changing its authority
status: ready-for-agent
---

# Rename an Agent without changing its authority

## Problem and Outcome

Consumer-authorized callers need one framework-neutral way to rename an Agent, whether initiated by an Agent through
self-service or by a User through a separately authorized administrative entry point. Provide a **generic name-only**
package command that records typed User-or-Agent initiator provenance without using the supplied identity as proof of
authority. Its atomic write must preserve current credential and direct-Permission authority, including when other
writers race the rename. This TICKET owns the generic transition; [TICKET-00019](00019-TICKET.md) owns the self-service
MCP tools and their permission binding.

## Use Cases

| Actor and trigger | Command | Queries | Events | Observable outcome and side effects |
| --- | --- | --- | --- | --- |
| A consumer-authorized User or Agent requests a new name for a chosen Agent | Generic `UpdateAgent`, carrying target, new name and typed initiating User-or-Agent identity | N/A; the handler may load current Agent state but issues no public read query or compensating acknowledgement lookup | A name-changed fact on a real committed change only | Normalize with `AgentName::fromString()`; if current target is `ACTIVE`, commit only the new name and `updatedAt`. After successful void dispatch, callers may acknowledge `{agent_id, name}` from the target and normalized command input. The event carries target and typed initiator provenance and is published after commit. |
| The same caller repeats the normalized current name | `UpdateAgent` | N/A | No success event | Succeed without any write or `updatedAt` change; callers may acknowledge the same `{agent_id, name}` shape from normalized command input, without a changed/no-op distinction. |
| The name, target, or persistence state is invalid | `UpdateAgent` | N/A | No success event; preserve ordinary safe command-failure event/rethrow semantics | Invalid trimmed-empty or over-120-character name, missing or non-`ACTIVE` target, and pre-commit storage failure cause no name write. Pre-commit failure rolls back; post-commit publication failure reports failure without claiming rollback or guaranteed event delivery. |
| Two callers rename the same Agent while credential, Permission, or lifecycle state changes | `UpdateAgent` | N/A | A fact for each real committed rename, subject to post-commit publication failure | Last committed name wins without expected old name or name revision, but no stale aggregate replacement may overwrite credential/Permission updates or restore revoked authority. A target no longer `ACTIVE` at the write must reject. |

## Rules and Failure Boundaries

- Use `AgentName::fromString()` for trimming, nonempty and maximum 120-character validation. No name-uniqueness
  policy or optimistic name-revision token is introduced. An unchanged normalized input remains a successful no-op.
- At the actual write, verify the current target remains `ACTIVE`. Persist only name and its real-change timestamp
  within one transaction and enforce the current authority fence; do not rewrite credential or Permission state.
  Repository/aggregate design may choose the safe name-only mechanism, not a generic stale whole-Agent replacement.
- Keep Common's void CommandBus/CommandHandler contracts. After successful synchronous dispatch, callers may
  acknowledge exactly `{agent_id, name}` from the command's target and `AgentName::fromString()`-normalized input.
  Real changes and no-ops share that acknowledgement; no `changed` field, result-bearing rename API or compensating
  read is required. It describes the successful request, not a fresh latest-state read after competing writes.
  No credential or Permission details are returned. A post-commit publication error causes a failure response,
  not a success acknowledgement, even if the rename already committed; a later read or retry can resolve current
  state but event delivery is not promised.
- The typed User/Agent initiator on the command and fact is **provenance only**. Package validation of Agent state
  does not authenticate or authorize callers. Consumers must protect all dispatch paths (including direct bus,
  workers and admin/self-service entry points) against unauthorized actor, target and scope combinations.
- Keep the package's ordinary command failure-event/rethrow behavior, not the credential-operation publication
  exception. No public profile-read Query or separate administrator endpoint is created by this TICKET.

## Acceptance Evidence

- [ ] Focused unit coverage of new/changed production classes proves validation, real and unchanged updates,
      active-state rejection, typed initiator provenance, input-derived acknowledgement after successful void dispatch
      and event ordering/failure.
- [ ] A few controlled package transaction/interleaving checks prove last-write-wins for names without lost
      credential/Permission changes or revoked-Agent resurrection, rollback before commit and accurate behavior
      after event-publication failure. Do not claim this qualifies an actual consumer database race.
- [ ] No-op has no write, timestamp change or success fact; invalid, missing and revoked targets produce no partial
      write. Command failures preserve ordinary safe failure evidence without leaking internal data through tools.
- [ ] Focused checks, `./bin/planning-check` and the canonical `./bin/build` pass with exact statement coverage
      when implementation is complete. Do not add a redundant conformance suite just to restate these cases.

## Dependencies and Exclusions

[WF-024](../wayfinder/tickets/WF-024-self-service-agent-profile-contract.md) is the accepted behavior source;
[ADR 0001](../adr/0001-domain-application-package-boundary.md),
[ADR 0005](../adr/0005-agent-direct-permission-assignment-revision.md),
[ADR 0010](../adr/0010-permission-eligibility-and-caller-authorization.md), and
[ADR 0011](../adr/0011-pre-v1-current-contract-only.md) retain current safety and consumer ownership. The generic
Domain/Application command can be planned independently of Fight Common's unpublished Tool API. Its result is used
by TICKET-00019, whose MCP tool behavior additionally depends on [TICKET-00006](00006-TICKET.md) and Common's
published Tool surface.

Consumer persistence adapters, actual writer-race qualification, transport and caller authorization, grants and
managed-policy migrations, administrator APIs, credential operations, Permission mutation, profile reads, MCP tools,
release and deployment are out of scope. No production Adapter layer, backward-compatibility route or legacy API is
introduced.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 69 | [TASK-00069](../tasks/00069-TASK.md) | Rename an Agent atomically with typed provenance | ready-for-agent |
## Progress

John subsequently simplified successful acknowledgement to `{agent_id, name}` from normalized command input;
real/no-op effects remain distinct internally, without a `changed` field or result-bearing command invocation.

John approved this as the first of two cohesive EPIC-00011 requirement areas and subsequently approved one complete
implementation slice, [TASK-00069](../tasks/00069-TASK.md). TASK planning for TICKET-00018 is complete; separate
execution authorization and checkout selection are still required. TICKET-00019 decomposition, consumer
qualification, publication and release remain separate operations.
