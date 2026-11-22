---
id: TICKET-00057
epic: EPIC-00019
title: Migrate repository Python tooling to PHP or document exceptions
status: ready-for-agent
---

# Migrate repository Python tooling to PHP or document exceptions

## Problem and Outcome

Assess every repository-owned Python script or module, including host orchestration, offline tooling, CI, release and qualification helpers. Port each to PHP or record a specific justified exception and who owns it. Vendor, generated, external and ignored scratch code are not repository-owned tooling.

## Use Cases and Contracts

- A maintainer traces entrypoints, imports and callers; each inventory row has a migrated PHP destination or a documented reason and retained prerequisites. Account for Python embedded in repository-owned automation as well as standalone files.
- Keep `bin/` host-facing and lifecycle, worktree selection and build-cache orchestration on the host. Planning, archiving, release-docs preflight and standalone link checks keep their offline, Docker-free and `vendor/`-free operation. A host port must not silently introduce a host runtime requirement: qualify and explicitly resolve any new prerequisite or document an exception.
- Prepared-environment tooling uses small PHP entrypoints and a dev-only Composer namespace without a new console dependency. Host wrappers delegate to the selected container where suitable; the shared gate calls those entrypoints in its prepared container or hosted environment. No nested Docker or duplicate preparation.
- Existing CLI flags, output contracts, exits, cache evidence, guarded teardown, release prerequisites and coverage/static proof stay intact. For the Unit coverage checker only, [TASK-00197](../tasks/00197-TASK.md) defines the current native artifact and coverage contract instead of requiring Python parity or comparative benchmarking. No new application Commands, Queries, Events or permissions apply; existing host and release authority remain unchanged.

## Sequencing and exclusions

Start with the contract inventory, then coverage checker. Port the static fixture runner after [TICKET-00056](00056-TICKET.md), keeping exact per-file diagnostics and separate qualification. Inventory the rest up front and sequence ports by dependencies and operational risk. Coordinate timing with [TICKET-00055](00055-TICKET.md). No runtime autoloading, unrelated redesign, or forced port at the expense of bootstrap behavior.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 519 | [TASK-00196](../tasks/00196-TASK.md) | Inventory repository Python and port the structural contract inventory | done |
| 520 | [TASK-00197](../tasks/00197-TASK.md) | Port the Unit coverage checker to PHP | done |
| 521 | [TASK-00198](../tasks/00198-TASK.md) | Port the static fixture runner and its qualification helper to PHP | ready-for-agent |
| 522 | [TASK-00199](../tasks/00199-TASK.md) | Resolve Python planning and archive tooling | ready-for-agent |
| 523 | [TASK-00200](../tasks/00200-TASK.md) | Resolve Python release-docs preflight tooling | ready-for-agent |
| 524 | [TASK-00201](../tasks/00201-TASK.md) | Resolve Python host build runtime and worktree tooling | ready-for-agent |
| 525 | [TASK-00202](../tasks/00202-TASK.md) | Resolve Python documentation diagram generators | ready-for-agent |
## Acceptance Evidence

- [ ] Reconcile the full Python inventory with PHP replacements and documented exceptions. Check that removed scripts no longer have callers.
- [ ] Qualify existing success and failure behavior on clean checkout, hosted gate, linked worktree and release paths as applicable, including execution before Docker or `vendor/` is available.
- [ ] Directly qualify the changed tooling and shared gate without adding tooling tests to the product suite.
