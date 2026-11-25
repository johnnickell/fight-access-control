# Decide what to keep from the static type-contract suite

**Labels:** `wayfinder:research`
**Mode:** AFK
**Status:** Closed
**Gate:** —
**Map:** [Pre-v1 documentation and developer tooling](../../archive/maps/pre-v1-maintenance-map.md)
**Depends on:** WF-037

## Question

What useful caller guarantees do the PHPStan fixtures prove, what is their cost, and can we keep that proof without a top-level `type-contracts/` directory?

## Must decide

- Separate fixture placement from proof retention. Compare keeping it as-is, relocating it outside normal PHPUnit discovery, or consolidating checks with equivalent exact positive and negative evidence.
- Identify which fixtures protect unique production API contracts and whether process setup dominates measured cost. Retain inference precision and rejection diagnostics, not just a nonzero analyzer exit.
- Trace the manifest, analyzer config, syntax/style gate, cache inputs, qualification helper and docs links. Keep the fixtures out of published runtime autoloading and product coverage.

## Required evidence

Use the [fixture contract](../../../../docs/type-contracts.md), [case manifest](../../../../etc/phpstan/type-contracts/cases.json), [quality gate](../../../../bin/quality) and WF-037 timing. Show which guarantees ordinary PHPStan and runtime tests cover and which require explicit static probes.

## Resolution boundary

Recommend location and the least expensive equivalent proof. Do not delete the suite, move files or choose the general tooling runtime in this decision.

## Resolution

Keep all 52 required cases. Recommend moving fixture inputs and their manifest to `etc/phpstan/type-contracts/`,
outside Composer autoloading, PHPUnit and ordinary PHPStan inputs. Preserve the current per-file runner behavior
and exact proof for the relocation. Keep batching optional until matched measurements demonstrate equivalent proof
and a meaningful saving. Hosted timing was 39–48s; startup share and batching savings are unknown. See the
[research note](../../research/WF-038-static-type-contracts.md) for migration touchpoints and equivalence criteria.
