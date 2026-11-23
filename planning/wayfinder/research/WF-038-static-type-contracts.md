# WF-038: Keep caller proof, move the fixtures out of the root

## Short answer

Retain the required PHPStan fixture suite and put its inputs under `etc/phpstan/type-contracts/`, outside runtime autoloading and PHPUnit discovery. Start with a layout change that preserves the current per-file runner. Treat batching as an optional optimization, not a prerequisite for relocation or a proven speedup.

## What the suite adds

The [manifest](../../../etc/phpstan/type-contracts/cases.json) declares 52 cases: 27 positive with 399 exact type probes and 25 negative with 168 expected rejections. For example, [identity/shape rejection fixtures](../../../etc/phpstan/type-contracts/negative.php) check that callers cannot pass a `RoleId` where a `UserId` is expected, treat a nullable result as nonnullable, or treat serialized IDs as typed objects. [Query caller probes](../../../etc/phpstan/type-contracts/query-dispatch-positive.php) check inferred result types at handler and dispatch boundaries; where the real caller uses a local type annotation, a probe asserts that annotated pattern, not automatic generic inference by the bus.

[Ordinary PHPStan](../../../phpstan.neon.dist) checks real `src/` and `openapi/` code and supplies the same caller rules. It can detect bad calls in that code but does not exercise deliberately invalid caller examples or assert exact types in otherwise valid calls. [PHPUnit](../../../phpunit.xml.dist) checks executable behavior and covers `src/`; runtime success does not prove a static generic, callback or array-shape guarantee. Leave the fixture proof required under [TASK-00151](../../tasks/00151-TASK.md). No probe group was demonstrated redundant.

## Placement and migration

| Option | Assessment |
|---|---|
| Keep the root directory | Lowest immediate risk; does not simplify root layout. |
| Move fixtures and `cases.json` into `etc/phpstan/type-contracts/` | Recommended. This is static analyzer input, not executable test code or PHP tooling to autoload. Leave the analyzer config location unchanged initially to avoid changing relative includes or cache resolution in the same move. |
| Fold into normal PHPStan or PHPUnit | Not equivalent. Deliberately invalid cases and positive `dumpType()` diagnostics should fail ordinary analysis and should never run as PHP. |
| Batch invocations under the same fixture check | Possible speed candidate; not proven equivalent or cheaper. Keep it separate from the layout change. |

Update the runner default path and qualification helper (now [`check_type_contracts.php`](../../../scripts/check_type_contracts.php) and [`qualify_type_contracts.php`](../../../scripts/qualify_type_contracts.php)), [quality gate](../../../bin/quality) lint discovery and [PHPCS inputs](../../../phpcs.xml). Keep `./bin/type-contracts` and `--suite` behavior. Repair direct links and path references in [fixture docs](../../../docs/type-contracts.md), [issuance dispatch composition](../../../docs/agent-integration.md#public-composition-map) and [query docs](../../../docs/query-dispatch-contracts.md), plus any new references at implementation time. Preserve the separate cache in [fixture config](../../../phpstan-type-contracts.neon); the [build fingerprint](../../../scripts/build_cache.php) includes `etc/` under current exclusions but must still cover moved fixtures, manifest, runner and config. Do not add fixtures to Composer autoloads, normal PHPStan paths, PHPUnit discovery, or coverage. No manifest diagnostic line should change for a pure move.

## Cost and batching qualification

[WF-037](WF-037-quality-gate-baseline.md) measured 39–48s hosted for the static fixtures. The runner launches PHPStan 52 times; that makes startup overhead plausible, but the logs do not split startup, file analysis and cache effects. A local runtime preflight timed out and the original runtime identity check then reported an unknown/foreign project container to retain. No fresh comparison ran; do not bypass that hold or quote a speedup.

If implemented later, compare per-file and batched analysis on identical resolved dependencies and analyzer settings, with distinct cold and warm cache series. Require identical case-to-file binding, diagnostic multiplicities, lines, identifiers and full messages; require exit 1 with complete JSON, correct totals and no unrelated files, global errors or unexpected stderr. A batch's single exit code is insufficient: match every expected case and ensure interrupted, timed-out or partial runs fail. Qualify the changed runner separately with its owning tool, including unaffected-case diagnostics when one case changes. Keep per-file mode as the baseline until a lower-cost batch demonstrates equivalent proof and measurable benefit.
