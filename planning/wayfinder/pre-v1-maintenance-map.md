# Wayfinder Map: Pre-v1 documentation and developer tooling

**Label:** `wayfinder:map`
**Status:** Closed

> This map indexes decisions. The linked records own the questions and their eventual resolutions.

## Destination

Plan a slimmer consumer README and documentation site in the style of Fight Common, refreshed automatically when
changes land on `main`; a faster, more reliable quality gate; and simpler PHP-first maintainer tooling with
host-invoked `bin/` commands. Prefer Fight Common Domain helpers wherever they preserve the local contract. Remove `CONTEXT.historical.md` from the working tree without losing still-current
requirements or obscuring historical evidence.

**Done** = the linked decisions are closed, remaining fog is resolved or excluded, and approved planning
handoffs are linked. Implementation success means a verified main-branch docs refresh, measured timing and
reliability evidence without weaker protection, and qualified command and fixture layout changes. Closing the
map does not establish release readiness or authorize a tag.

## Decisions so far

- [WF-036](tickets/WF-036-documentation-and-publication-boundary.md) settled the docs model: a short README
  and one consumer-first MkDocs site sourced from `docs/`. Validate incoming changes before merge; publish
  to GitHub Pages automatically from `main` only. Link to tagged docs for version-specific guidance and keep
  the `develop` integration policy intact.
- [WF-037](tickets/WF-037-quality-gate-speed-and-reliability.md) found that PHPUnit with coverage dominates
  runtime, while the sampled failures stopped at stale planning views. No test is cleared for deletion. Preserve
  exact statement, Unit-only line and shipped OpenAPI proof. See the [timing baseline](research/WF-037-quality-gate-baseline.md).
- [WF-038](tickets/WF-038-static-type-contract-proof.md) recommends moving required static fixtures to
  `etc/phpstan/type-contracts/` with no proof or runner behavior change for the move. Batch analysis only if
  later qualification shows equivalent proof and a measurable saving.
- [WF-039](tickets/WF-039-host-and-composer-tooling-split.md) settled the tooling split: keep `bin/` host-invoked
  and preserve offline/bootstrap commands. Use dev-only Composer autoloading without a new console dependency.
  Port the contract inventory first, then the coverage checker and, after fixture relocation, the static runner.
- Retire superseded notes to Git history, not a new archive. Remove `CONTEXT.historical.md` from the working
  tree after migrating any current material and repairing references. Preserve access to evidence from
  [TASK-00184](../tasks/00184-TASK.md) where it is still cited.
- [WF-040](tickets/WF-040-prefer-common-domain-helpers.md) scoped the first helper pass to 12 equivalent
  production email calls on Common v1.5.1. Add the preference to the PHP standard and keep non-equivalent factories.
- Current source observations and limits are in the [baseline research note](research/pre-v1-maintenance-baseline.md).

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-036 | [Choose the reader-facing docs and publication boundary](tickets/WF-036-documentation-and-publication-boundary.md) | Grilling | HITL | **Closed** | — |
| WF-037 | [Find the first safe quality-gate speedups](tickets/WF-037-quality-gate-speed-and-reliability.md) | Research | AFK | **Closed** | — |
| WF-038 | [Decide what to keep from the static type-contract suite](tickets/WF-038-static-type-contract-proof.md) | Research | AFK | **Closed** | WF-037 |
| WF-039 | [Choose the host and Composer tooling split](tickets/WF-039-host-and-composer-tooling-split.md) | Grilling | HITL | **Closed** | WF-038 |
| WF-040 | [Apply the Fight Common Domain helper preference](tickets/WF-040-prefer-common-domain-helpers.md) | Research | AFK | **Closed** | — |

## Blocking relationships

WF-037 provides the measured cost and reliability baseline for WF-038. WF-038 precedes WF-039 so the
migration does not assume the current runner or directory layout survives. WF-040 remains independent.

## Frontier

None. All five decisions are closed, and the approved EPIC is linked below.

## EPIC handoff

[EPIC-00019 — Pre-v1 documentation and developer tooling](../epics/00019-EPIC.md) records the approved scope,
including the Fight logo and Fight Common as the site's visual benchmark. The five approved TICKETs are written;
the EPIC owns the later amendments to Python and helper scope. All five TICKETs have approved TASK plans.
Map closure does not imply implementation, publication, or release readiness.

The linked EPIC records completion of the approved implementation scope.

## Downstream planning details

- Final page taxonomy and keep, merge, move or retire decisions by page; path and anchor migration inventory.
- Confirmed test flakes, any truly redundant cases, and achievable savings under controlled before/after runs.
- Exact dev-only PSR-4 namespace, inventory dispositions and qualification details for the expanded tooling scope.

## Out of scope

- Implementing, deploying, or changing repository settings in this Wayfinder session; tagging or publishing v1.0.0.
- Changing Fight Common, branch or release policy, package behavior, supported frameworks, or coverage requirements.
- Reopening completed planning work, weakening checks to make CI look faster, or rewriting Git history.
