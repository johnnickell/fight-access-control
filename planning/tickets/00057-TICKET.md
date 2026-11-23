---
id: TICKET-00057
epic: EPIC-00019
title: Migrate repository Python tooling to PHP
status: done
---

# Migrate repository Python tooling to PHP

## Problem and Outcome

Port repository-owned Python tooling to PHP or remove obsolete code, including host tooling, CI, release, qualification and diagram generation. The [consumer docs and GitHub Pages pipeline](../wayfinder/tickets/WF-039-host-and-composer-tooling-split.md#approved-exception--consumer-docs-and-pages) is the only Python exception: retain MkDocs, its Python hook and the existing Python/uv build and preview tooling. Vendor, generated, external and ignored scratch code are not repository-owned tooling.

## Use Cases and Contracts

- A maintainer traces entrypoints, imports and callers; each inventory row has a PHP replacement, verified removal or a disposition within the approved consumer docs exception. Account for Python embedded in repository-owned automation as well as standalone files.
- Per the [WF-039 amendment](../wayfinder/tickets/WF-039-host-and-composer-tooling-split.md#approved-amendment--no-python), host-facing `bin/*` commands in the migration use Docker for tooling. The retained docs pipeline keeps its existing runtime. `scripts/*` must not require Docker and may use Composer. Preserve offline operation with the runtime prepared; do not require host PHP for normal wrapper use.
- Prepared-environment tooling uses small PHP entrypoints and a dev-only Composer namespace without a new console dependency. Host wrappers delegate to the selected container where suitable; the shared gate calls those entrypoints in its prepared container or hosted environment. No nested Docker or duplicate preparation.
- Existing CLI flags, output contracts, exits, cache evidence, guarded teardown, release prerequisites and coverage/static proof stay intact. For the Unit coverage checker only, [TASK-00197](../tasks/00197-TASK.md) defines the current native artifact and coverage contract instead of requiring Python parity or comparative benchmarking. No new application Commands, Queries, Events or permissions apply; existing host and release authority remain unchanged.

## Sequencing and exclusions

Start with the contract inventory, then coverage checker. Port the static fixture runner after [TICKET-00056](00056-TICKET.md), keeping exact per-file diagnostics and separate qualification. Inventory the rest up front and sequence ports by dependencies and operational risk. Coordinate timing with [TICKET-00055](00055-TICKET.md). No production autoloading or unrelated redesign. The exception-only completions for TASK-00199 and TASK-00200 were reopened. TASK-00199 and TASK-00200 are now accepted for their PHP ports. TASK-00201 is accepted for the host build and lifecycle port. TASK-00202 still owns the documentation diagram generators. The retained consumer docs and Pages pipeline needs no migration TASK and does not block this TICKET's completion. Preserve its existing validation, publication and deployment rules.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 519 | [TASK-00196](../tasks/00196-TASK.md) | Inventory repository Python and port the structural contract inventory | done |
| 520 | [TASK-00197](../tasks/00197-TASK.md) | Port the Unit coverage checker to PHP | done |
| 521 | [TASK-00198](../tasks/00198-TASK.md) | Port the static fixture runner and its qualification helper to PHP | done |
| 522 | [TASK-00199](../tasks/00199-TASK.md) | Resolve Python planning and archive tooling | done |
| 523 | [TASK-00200](../tasks/00200-TASK.md) | Resolve Python release-docs preflight tooling | done |
| 524 | [TASK-00201](../tasks/00201-TASK.md) | Resolve Python host build runtime and worktree tooling | done |
| 525 | [TASK-00202](../tasks/00202-TASK.md) | Resolve Python documentation diagram generators | done |
## Acceptance Evidence

- [ ] Reconcile the inventory with PHP replacements, verified removals and the approved consumer docs exception. Check tracked files, automation, dependency manifests and documented commands for Python tooling or requirements outside that exception.
- [ ] Qualify existing success and failure behavior on clean checkout, hosted gate, linked worktree and release paths as applicable, including execution before Docker or `vendor/` is available.
- [ ] Directly qualify the changed tooling and shared gate without adding tooling tests to the product suite.
