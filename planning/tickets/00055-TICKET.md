---
id: TICKET-00055
epic: EPIC-00019
title: Make the quality gate faster and more reliable
status: ready-for-agent
---

# Make the quality gate faster and more reliable

## Problem and Outcome

Maintainers need a reliable, faster verdict from the [single quality gate](../adr/0002-single-quality-gate.md). Start with the [WF-037 baseline](../wayfinder/research/WF-037-quality-gate-baseline.md): sampled failures stopped at stale planning views, while coverage-enabled PHPUnit consumed most execution time.

## Use Cases and Contracts

- On a stale-view failure, a maintainer can trace the tested revision and diagnose the authored/generated mismatch. The check stays read-only and fails visibly rather than rewriting stale views in CI.
- When profiling a candidate, a maintainer measures phase timings and PHPUnit overhead under matched conditions and distinguishes timing-sensitive setup from a confirmed flake. Repair setup or consolidate a test only when the unique behavior still has equivalent proof.
- The existing gate command and nonzero failure behavior remain authoritative locally and in CI. No new package Commands, Queries, Events, permissions or persistence apply.

## Sequencing and exclusions

Coordinate baseline and candidate runs with [fixture relocation](00056-TICKET.md) and [tooling ports](00057-TICKET.md) so measured changes can be attributed. Do not silently add retries, skips, or a second quality gate. No test is preapproved for deletion.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 515 | [TASK-00192](../tasks/00192-TASK.md) | Make stale planning failures diagnosable | done |
| 516 | [TASK-00193](../tasks/00193-TASK.md) | Reduce PHPUnit overhead without weakening coverage | done |
| 517 | [TASK-00194](../tasks/00194-TASK.md) | Replace timing-sensitive test setup where proof is equivalent | ready-for-agent |
## Acceptance Evidence

- [ ] Preserve exact statement coverage, Unit-only line coverage, shipped OpenAPI coverage and unique behavior checks.
- [ ] Compare matched before/after runs, report cache state, timings and any unachieved savings. A 15% lower median shared-gate time is a provisional target, not a release gate.
- [ ] Show the resolved planning failure cause or its precise evidence gap, and show that any timing-sensitive setup changes retain equivalent proof.
