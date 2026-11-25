---
id: TICKET-00056
epic: EPIC-00019
title: Relocate static type-contract fixtures without losing proof
status: done
---

# Relocate static type-contract fixtures without losing proof

## Problem and Outcome

Move the static fixtures out of the root without weakening [required caller proof](../../wayfinder/research/WF-038-static-type-contracts.md). Fixture inputs and the manifest belong under `etc/phpstan/type-contracts/`.

## Use Cases and Contracts

- A maintainer runs `./bin/type-contracts`, optionally against an explicit suite, and sees the same per-file positive inference and negative rejection verdicts. Unexpected, missing or partial diagnostics fail closed.
- The shared gate includes the moved fixtures in its required check and syntax/style inputs; build reuse still binds fixtures, manifest, runner and config. Keep them out of normal PHPStan analysis, PHPUnit discovery, coverage and Composer autoloading.
- This changes tooling paths, not package Commands, Queries, Events, permissions or runtime data.

## Sequencing and exclusions

Relocation precedes the static-runner port in [TICKET-00057](00057-TICKET.md). Coordinate documentation references with [TICKET-00054](00054-TICKET.md). No batching in the move. Later batching is optional and must prove exact equivalent results and measurable savings.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 518 | [TASK-00195](../../tasks/archive/00195-TASK.md) | Move the PHPStan type-contract fixtures without changing what they prove | done |
## Acceptance Evidence

- [ ] Verify all 52 cases with unchanged expected file binding, lines, identifiers, messages and multiplicity. Qualify failures and timeouts through the owning checks.
- [ ] Verify focused and shared-gate invocation, build inputs, syntax/style scope and repaired links.
