# Type-contract fixtures

Run `./bin/type-contracts` for focused iteration. The required check also runs inside `bin/quality`, through
canonical `./bin/build` and the existing CI entrypoint. It uses the selected package runtime, installed Composer
autoloading and actual PHPStan executable. It never accepts a previous fixture-run result. Normal build reuse
still applies to an unchanged successful full gate: the existing build fingerprint includes the runner, fixtures,
manifest, analyzer configuration and installed dependencies.

## Declaring a contract

Add PHP fixtures under `etc/phpstan/type-contracts/` and register each file exactly once in
[`cases.json`](../etc/phpstan/type-contracts/cases.json). Each case declares a unique `id`, `kind` (`positive`
or `negative`), relative PHP `file` and nonempty `diagnostics` list. Each diagnostic specifies its exact `line`,
PHPStan `identifier` and full `message`. All PHP files in that directory must be registered, and the suite must
contain both kinds. Empty/missing files or declarations fail. Keep these fixtures static: the runner analyzes
but never executes their PHP.

- **Positive:** use PHPStan's built-in `PHPStan\dumpType()` at each inference boundary. Declare only
  `phpstan.dumpType` diagnostics with the exact useful inferred type. A merely error-free expression is not a
  precision assertion. Lost precision, missing probes and unrelated diagnostics fail.
- **Negative:** exercise an incompatible use of the real contract and declare its actual rejection diagnostic.
  Do not use a blanket error count, broad message pattern or arbitrary nonzero exit as acceptance. Every diagnostic
  is matched by fixture file, line, identifier and full message, including duplicate counts; extra or missing errors
  fail. Changes to diagnostics after a tool update require inspecting the actual contract, not blindly refreshing
  expectations to make the gate green.

Both modes deliberately require analyzer **exit 1**: positive probes are themselves PHPStan diagnostics, and negative
cases must be rejected. The runner additionally checks complete JSON, exact file/error totals and no global errors
or unexpected stderr. Exit 0, other exits, signals, timeouts, missing analyzer/dependencies, malformed/partial output
and unexpected diagnostics fail. It prints overall success only after every declared case passes. The per-process
default timeout is 120 seconds; focused runs can set `--timeout SECONDS`. On timeout or SIGINT, the runner stops
PHPStan and its process group and reports failure (exit 1). The prepared PHP tooling runtime supplies `pcntl`
for SIGINT handling; it is not a library or host dependency.

The fixture configuration [`phpstan-type-contracts.neon`](../phpstan-type-contracts.neon) inherits the ordinary
production/caller rules in [`phpstan.neon.dist`](../phpstan.neon.dist). Explicit file arguments select each fixture;
there is no stricter fixture-only level. TASK-00152 activates level 8 across every `src` and `openapi` file and as
the default for ordinary caller checks: nullable arguments and partially incompatible union uses must reject,
alongside the existing element/shape/callback rules. A concrete unchecked nullable repository-to-View call established
the prior enforcement gap. Level 8 is selected for those checks, not as semantic certification or a numeric target.

Ordinary PHPStan analyzes only `src/` and shipped `openapi/` metadata, not `tests/` or `scripts/`, under the
[project's explicit scope decision](../planning/agents/project-profile.md#tests-and-delivery). The required static
fixtures qualify production API inference and incompatible-use rejection; they do not test the tests or checker.
The shared [`phpstan-base.neon`](../phpstan-base.neon) retains framework symbol discovery and bleeding-edge rules;
separate cache directories isolate production and fixture jobs. Symbol discovery does not add analysis inputs.
Known mixed bus/UoW contracts and permitted truthful local annotations remain unchanged. Runtime validation still
owns genuinely dynamic inputs; static analysis does not prove permissions, identity, transaction outcomes or
external consumer behavior.

## Isolation and qualification

The directory is outside ordinary PHPStan's `openapi/` and `src/` inputs and outside PHPUnit's explicit
suite directories and `src` coverage scope. No ignoreErrors, baseline or production exclusion is added. PHP syntax
and PHPCS checks include fixtures. Intentionally invalid static uses do not become runtime tests or coverage credit.

When changing the runner, qualify its failure handling separately:

```sh
./bin/exec php scripts/qualify_type_contracts.php
```

This standalone qualification uses synthetic tool output and temporary declarations to check exact report
matching, malformed or incomplete output, invalid suite inputs, process failures, timeout and SIGINT cleanup. It does
not execute fixtures. It is outside default PHPUnit, coverage, `bin/quality` and ordinary CI. Save its output
and exit status under the owning TASK's ignored run evidence. Run `./bin/type-contracts` separately for the
full tracked suite against PHPStan; neither focused command replaces the canonical build.

For isolated probes only, `./bin/type-contracts --suite /absolute/container/path` selects a copied suite with its own
`cases.json`; it still uses this checkout's analyzer/config/autoloading. Canonical `bin/quality` supplies no override.
Temporary probes are not accepted replacements for the tracked product cases.

## Current proof and remaining ownership

Static fixtures check current declarations and known caller annotations, not runtime validation, ownership or
consumer qualification. Use the manifest above for the maintained cases, not a separate per-TASK probe count.
Common QueryBus and native transactional results remain `mixed`; a matching local `@var` for a known owned callback
result (including tuples/unions) is sufficient. Keep actual dynamic boundaries dynamic and validate input in its
runtime owner. Static Event registration plus correct local payload annotations does not establish generic Event
inference. Use the [Query dispatch contract](query-dispatch-contracts.md) and
[delivery Event composition](credential-delivery.md#human-delivery-event-composition) for runtime wiring.

Use the [structural inventory procedure](engineering/contract-inventory.md) when changing declarations. Return
source defects to their semantic owner; never hide them in fixture expectations.
