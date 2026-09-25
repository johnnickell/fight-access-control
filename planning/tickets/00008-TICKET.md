---
id: TICKET-00008
epic: EPIC-00007
title: Use Supported Transactional Unit of Work Contracts
status: ready-for-agent
---

# Use Supported Transactional Unit of Work Contracts

## Problem and Outcome

Fight AccessControl already executes atomic operations through Fight Common's current `commitTransactional()`
behavior, but its maintained public constructors and supporting tests still type against the deprecated
`Fight\Common\Application\Repository\UnitOfWork` interface. Consumers must therefore implement a deprecated
contract even though AccessControl does not use its legacy standalone `commit()` method. Leaving that dependency in
place carries avoidable migration debt into later pre-1.0 releases and obscures the package's actual transaction
requirement.

Require `TransactionalUnitOfWork` throughout the maintained package surface while preserving every established
atomic boundary, rollback guarantee, result, and event-ordering rule. Complete a one-time audit of production code,
tests, configuration, and current consumer guidance against the Fight Common 1.2 deprecation inventory so the
package no longer requires another deprecated Common API. Document the constructor contract change for consumers
without retaining a bridge to the deprecated interface.

## Use Cases

| Actor and trigger | Commands | Queries | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| A consumer composes an AccessControl command handler or security service | Existing package Commands and secret-bearing service methods | N/A | Existing success and failure Events | The consumer supplies `TransactionalUnitOfWork`; the complete existing mutation and persistence operation remains inside one transactional callback. |
| A command or service transaction succeeds | Existing package Commands and service methods | N/A | Existing purpose-specific success Events | Repository changes commit atomically, the callback result is retained, and any established success publication occurs only after commit. |
| A transaction or transactional dependency fails | Existing package Commands and service methods | N/A | Existing failure Events where already defined | Partial changes roll back, no success Event is published, existing safe failure evidence is retained, and the original failure is rethrown unchanged. |
| A signed Agent request crosses nonce consumption and authority fencing | N/A | Existing principal resolution | Existing diagnostic behavior; no new Event | Nonce consumption and current-authority checks retain their established transactional and request-scoped behavior through the supported contract. |
| A package maintainer prepares the next pre-1.0 release | N/A | N/A | N/A | Maintained code and guidance contain no dependency on a Fight Common 1.2 deprecated API, and consumers receive migration guidance for the constructor type change. |

No new Command, Query, or Event is introduced. Existing message serialization, handler registration, and observable
business outcomes remain unchanged.

## Transaction, Compatibility, and Failure Rules

- Public and internal constructor dependencies that currently require Common `UnitOfWork` require
  `TransactionalUnitOfWork` instead. Test doubles and focused anonymous fixtures implement the supported interface.
- Existing `commitTransactional()` callback boundaries are preserved. Reads, validation, aggregate mutation,
  repository persistence, reference fencing, audit work, and callback return values must not move across a
  transaction boundary merely to complete the type migration.
- Existing post-commit success publication remains post-commit. Transaction failures publish no success Event;
  handlers and services retain their current safe failure publication and identical-throwable rethrow behavior.
- Agent nonce consumption, credential and Permission-assignment fencing, and request-local principal caching retain
  their existing ordering and outcomes.
- The constructor type change is an accepted breaking pre-1.0 consumer composition change. Migration guidance tells
  consumers to provide the narrow supported transaction contract; AccessControl does not add a dual-interface
  adapter, alias, or compatibility layer.
- Current repository and consumer guidance uses supported transaction terminology where it describes composition or
  implementation obligations. Historical planning records remain unchanged.
- The authoritative Fight Common 1.2 deprecated declarations are compared once with AccessControl's maintained
  production code, tests, configuration, and current consumer documentation. Any additional maintained dependency
  discovered by that audit is replaced with its supported Common contract in this requirement.

## Validation, Permissions, and Security

- No new external input or business validation is introduced. Existing validation order and rejection behavior must
  remain unchanged.
- No Permission, Role, authorization rule, authentication policy, or principal shape changes. Existing actor checks
  still run at their established point inside or outside the transaction.
- No secret handling, serialization, diagnostic, or caller-facing failure contract changes. Transaction migration
  must not expose credentials, signatures, nonces, ciphertext, provider details, or arbitrary exception messages.
- A deprecated API finding fails acceptance; it does not create a runtime user-facing error or new Domain exception.

## Acceptance Evidence

- [ ] Every maintained production dependency on Common `UnitOfWork` uses `TransactionalUnitOfWork`, and no production
      code invokes or requires the deprecated standalone `commit()` journey.
- [ ] Test doubles, fixtures, type assertions, configuration, and current consumer guidance use the supported
      transaction contract without a deprecated compatibility bridge.
- [ ] Focused behavioral tests prove successful commits, rollback on transactional failure, callback result
      propagation, post-commit success publication, existing failure evidence, and unchanged exception identity for
      representative command, authentication-service, Agent-service, and nonce-consumption paths.
- [ ] Existing transaction-sensitive suites continue to prove authorization/reference fencing, audit durability,
      request-local principal behavior, and no partial persistence.
- [ ] A recorded one-time comparison against Fight Common 1.2's deprecated declarations finds no deprecated Common
      API dependency in maintained production code, tests, configuration, or current consumer documentation.
- [ ] Consumer migration guidance and `CHANGELOG.md` identify the public constructor type change as a breaking
      pre-1.0 compatibility change and describe the supported replacement without claiming a tag or publication.
- [ ] `./bin/planning-check` and the canonical `./bin/build` pass with exact production statement coverage.

## Dependencies and Sequencing

- Fight Common `1.2.0` is installed and supplies `TransactionalUnitOfWork` as the supported narrow contract.
- TASK-00037 is accepted. This requirement is the next implementation priority so TASK-00038 builds its overlapping
  credential-delivery handlers directly on the supported transaction contract rather than introducing more
  deprecated dependencies.
- TASK-00038 depends on this migration, and TASK-00039 remains the later release-qualification owner. The resulting
  sequence must complete before the separately authorized planned `0.3.0` tag.

## Exclusions

- A permanent deprecation scanner, new recurring quality-gate step, or repository-wide historical-text rewrite.
- Changes to Commands, Queries, Events, aggregate behavior, authorization policy, persistence schemas, or public
  result shapes.
- New transaction abstractions, transaction nesting policy, outbox behavior, or redesign of established atomic
  boundaries.
- Production persistence adapters, framework service-container wiring, database migrations, or consumer repository
  implementation.
- A deprecated `UnitOfWork` bridge, compatibility alias, package-owned adapter, or support for the standalone
  `commit()` method.
- Release certification, tag creation, publication, consumer upgrade execution, or deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 40 | [TASK-00040](../tasks/00040-TASK.md) | Remove deprecated Fight Common contract dependencies | ready-for-agent |
