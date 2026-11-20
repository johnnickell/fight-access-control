# Find the first safe quality-gate speedups

**Labels:** `wayfinder:research`
**Mode:** AFK
**Status:** Closed
**Gate:** —
**Map:** [Pre-v1 documentation and developer tooling](../pre-v1-maintenance-map.md)
**Depends on:** —

## Question

Which intermittent failures, redundant proofs or repeated setup account for meaningful wall time, and which reductions are safe?

## Must decide

- Start by classifying observed intermittent failures by product, test or infrastructure cause. Recommend deterministic replacements for unique coverage, not retry masking or blind deletions.
- Measure dependency prep, static contract checks, PHPUnit discovery and execution, coverage and other gate phases on comparable inputs. Distinguish clean/warm runs and elapsed job time from scheduling or billing delay.
- Rank candidates by measured cost, flake evidence and the behavior they protect. Remove or consolidate only where specific equivalent proof remains; otherwise repair or retain.

## Required evidence

Use the [baseline](../research/pre-v1-maintenance-baseline.md), [Testing](../../../docs/engineering/standards/Testing.md), and the [single-gate ADR](../../adr/0002-single-quality-gate.md). Capture counts and timings by suite/phase, qualified exit results, and concrete examples of protected behavior and retained proof. Preserve required statement coverage, Unit-only line coverage and OpenAPI schema protection.

## Resolution boundary

Recommend a measurable target and candidate list, not deletions, CI edits, or a new parallel build path. Pass static check timing to WF-038.

## Resolution

The [measured baseline](../research/WF-037-quality-gate-baseline.md) puts the median hosted shared gate at 9:16.
PHPUnit with coverage took 6:01–6:58. Static type-contract fixtures took 39–48s across 52 cases. Four sampled
failed runs stopped at stale planning views, not flaky PHPUnit tests. Start reliability work with the planning
mismatch and deterministic setup for timing-sensitive tests. No test is cleared for deletion.

Prioritize profiling PHPUnit for speed, with a provisional target of a 15% lower median gate time under matched
conditions and no loss of proof. Continue to WF-038 for the static fixtures; this research establishes neither an
implementation handoff nor a measured improvement.
