# Type-contract fixtures

Run `./bin/type-contracts` for focused iteration. The required check also runs inside `bin/quality`, through
canonical `./bin/build` and the existing CI entrypoint. It uses the selected package runtime, installed Composer
autoloading and actual PHPStan executable. It never accepts a previous fixture-run result. Normal build reuse
still applies to an unchanged successful full gate: the existing build fingerprint includes the runner, fixtures,
manifest, analyzer configuration and installed dependencies.

## Declaring a contract

Add PHP fixtures under `type-contracts/` and register each file exactly once in
[`type-contracts/cases.json`](../type-contracts/cases.json). Each case declares a unique `id`, `kind` (`positive`
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
default timeout is 120 seconds; focused runs can set `--timeout SECONDS`.

The fixture configuration [`phpstan-type-contracts.neon`](../phpstan-type-contracts.neon) includes the ordinary
project configuration. Explicit file arguments select only each fixture; the ordinary source/caller analysis is
still run separately and unchanged. Fixture level 8 is needed to reject passing nullable repository output to a
non-null parameter (not checked at the existing production level 6). This is a demonstrated fixture requirement,
not a claim that level 8 is sufficient or approved for all production boundaries.

## Isolation and qualification

The directory is outside ordinary PHPStan's `openapi/src/tests/scripts` inputs and outside PHPUnit's explicit
suite directories and `src` coverage scope. No ignoreErrors, baseline or production exclusion is added. PHP syntax
and PHPCS checks include fixtures. Intentionally invalid static uses do not become runtime tests or coverage credit.

When changing the runner, qualify its failure handling separately:

```sh
./bin/exec python3 scripts/qualify_type_contracts.py
```

This explicit tooling qualification runs real PHPStan against temporary fixture mutations, including valid-case
regression/lost precision, accepted invalid use, extra errors and missing/empty inputs. It separately challenges
report matching, missing executables, process signals and timeout handling. It is not in default PHPUnit, coverage,
`bin/quality` or ordinary CI. Save its complete output and actual exit under the owning TASK's ignored run evidence.
A qualification pass does not replace the required product fixture check or canonical build.

For isolated probes only, `./bin/type-contracts --suite /absolute/container/path` selects a copied suite with its own
`cases.json`; it still uses this checkout's analyzer/config/autoloading. Canonical `bin/quality` supplies no override.
Temporary probes are not accepted replacements for the tracked product cases.

## Current proof and remaining ownership

The initial two cases assert six positive types and six incompatible uses: UserId versus RoleId, UserView Role
lists and canonical serialized shape, nullable UserRepository lookup, FeatureReferences names and typed array-map
callbacks. Expectations come from those current package declarations. No production API or business behavior changes.
Static collection/callback inference does not validate untrusted values, enforce PHP generics at runtime, establish
request identity or prove business effects.

TASK-00128/00129/00130 may add real dispatch cases using this manifest/runner; TASK-00136 owns real Event cases.
Those owners must prove their actual payload/result/registration relationships through qualified Common contracts,
minimum/ordinary dependency lanes and applicable runtime composition. Local sinks/probes, dummy handlers or direct
handler calls cannot stand in for an unqualified bus relationship. These initial cases claim none of that proof.
Native transactional results remain `mixed`; a matching local `@var` for a known owned callback result (including
tuples/unions) remains sufficient. No generic UoW supplier or redundant runtime check is required.

TASK-00150 owns exhaustive declaration/contract inventory. TASK-00152 must reconcile that inventory with accepted
correction slices, establish sufficient whole-library rules and fixtures, justify genuine dynamic boundaries and
trace current caller/runtime/supplier evidence. Representative cases and production PHPStan level 6 do not complete
that audit. Newly discovered source defects return to their semantic owner; never hide them in fixture expectations.
