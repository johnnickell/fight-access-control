---
id: TICKET-00058
epic: EPIC-00019
title: Adopt equivalent Fight Common Domain helpers
status: done
---

# Adopt equivalent Fight Common Domain helpers

## Problem and Outcome

Prefer released Fight Common helpers over equivalent local factory calls. Begin with the 12 production `email()` candidates from [WF-040](../wayfinder/research/WF-040-common-domain-helpers.md), and assess production callers for `string()`, `mb_string()`, `array_list()` and `hash_set()`.

## Use Cases and Contracts

- A maintainer substitutes a helper via an explicit function import where the minimum supported Common v1.5.1 preserves the result type, exceptions, evaluation order and behavior. Keep type imports still needed in declarations.
- For typed collections, pass the element class as the second argument. That preserves runtime enforcement but does not by itself guarantee PHPStan inference. Verify the inferred element type and construction behavior at each changed caller; retain the existing factory if substitution widens the type to `mixed` or changes behavior.
- Record the preference and exceptions in the local PHP standard. No new Commands, Queries, Events or permissions; existing hydration and failure behavior are unchanged.

## Sequencing and exclusions

Independent of the other requirements. No dependency bump, blanket lint rule, test-only rewrite or change to non-equivalent UUID or serialization behavior. Do not manufacture call sites for helpers with no equivalent use.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 526 | [TASK-00203](../tasks/00203-TASK.md) | Adopt equivalent Fight Common Domain helpers | done |
## Acceptance Evidence

- [x] Record candidates and justified exceptions for all five helpers against Common v1.5.1.
- [x] Verify ordinary analysis, affected type-contract probes and existing behavior at changed sites, including hydration, authentication failure and typed collection precision where applicable.
