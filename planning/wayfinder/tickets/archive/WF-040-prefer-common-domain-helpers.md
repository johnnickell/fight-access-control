# Apply the Fight Common Domain helper preference

**Labels:** `wayfinder:research`
**Mode:** AFK
**Status:** Closed
**Gate:** —
**Map:** [Pre-v1 documentation and developer tooling](../../archive/maps/pre-v1-maintenance-map.md)
**Depends on:** —

## Question

Where can AccessControl use Fight Common's `src/Domain/functions.php` helpers without changing behavior or losing static type precision, and where should we document that preference for future changes?

## Must decide

- Identify equivalent call sites on the minimum supported Fight Common version. Prefer named imports (`use function`) where the helper is a drop-in replacement; do not copy helpers locally.
- Record exceptions for precise collection generics, AccessControl-specific identifiers, or differences in validation, exceptions, or construction semantics. Do not substitute a generated UUID for a parsed identifier.
- Recommend a bounded migration and the owning PHP guidance for an ongoing preference, not a blanket lint rule that forces non-equivalent substitutions.

## Required evidence

Use the [baseline](../../research/pre-v1-maintenance-baseline.md), [PHP standard](../../../../docs/engineering/standards/PHP.md), current call sites and the released helper implementations and annotations. Verify changed sites against ordinary static analysis, applicable type-contract probes and existing behavioral coverage at implementation.

## Resolution boundary

Identify eligible groups and the guidance change. No source substitutions, dependency bump, or rule enforcement in Wayfinder.

## Resolution

Prefer the released Common helpers with explicit function imports when behavior and static types match. Start with
12 production calls to `EmailAddress::fromString()` using Common's `email()`; preserve input, exception handling
and order. Keep typed collection factories, parsed and domain-specific identifiers, random UUID generation and
string serialization as-is. Record the preference and exceptions in the project PHP standard, not a blanket lint
rule. The [research note](../../research/WF-040-common-domain-helpers.md) records minimum-version evidence and the
implementation verification boundary.
