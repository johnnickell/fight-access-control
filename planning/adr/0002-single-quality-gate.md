# ADR 0002: Single Executable Quality Gate

- Status: accepted
- Date: 2026-08-16

## Decision

`./bin/quality` is the sole ordered definition of the complete quality gate. It runs Composer validation, PHP
syntax, planning integrity, coding style, static analysis, architecture, Rector dry-run, PHPUnit, exact
coverage, documentation, and production-autoload checks in fail-fast order.

Coding style has one executable authority: `php vendor/bin/phpcs` with the local lean ruleset composed from
published PSR-12 and Slevomat rules. It covers strict types, layout, naming, spacing, arrays, and documentation
presence. It does not copy Fight Common's custom sniffs or claim semantic parity with an unexported standard.
When a compatible Fight Common release exports a consumer standard, this repository will consume that standard
and remove overlapping local configuration rather than maintain a parallel implementation.

`./bin/build` is the canonical noninteractive local entry point. It prepares the isolated container and tracked
dependency resolution, then delegates once to `./bin/quality`. Hosted CI may prepare a latest-compatible
resolution, but it delegates to the same quality script instead of maintaining a second checklist.

The original opt-in `.githooks/pre-commit` delegated once to `./bin/build`; the amendment below removes that
invocation. There is no pre-push gate.

### Amendment — remove redundant commit-time builds

John explicitly requested removal of the repository pre-commit hook while TASK-00061 was in progress. The
implementation and review workflows already require build evidence, so committing must not rerun the build solely
because a commit is being created. Remove `.githooks/pre-commit` and the obsolete clone-local `core.hooksPath`
setting when it points exactly to `.githooks`; preserve independently managed hooks.

`./bin/build` remains the mandatory local completion gate, and `./bin/quality` remains its single ordered
implementation. This changes invocation timing, not verification requirements, failure handling, independent review,
or publication authority. Historical hook-run receipts remain valid evidence of their recorded executions.

## Consequences

Every submit path exercises one reviewable gate definition. Focused wrappers remain iteration tools and do not
become alternate completion contracts.

The style gate may be less prescriptive than Fight Common until the shared package exports a compatible consumer
standard, but ownership remains explicit and drift cannot arise from copied custom checkers.

## Rejected Alternatives

Duplicating the gate in CI or a hook was rejected because equivalent command lists can drift. A pre-push gate
was rejected because it repeats long-running local work at a network-sensitive boundary.

Copying or reimplementing Fight Common's custom semantic documentation sniffs was rejected because Fight Common
1.1 does not export them as a supported consumer contract. Such a copy would establish a second authority that
must drift independently.
