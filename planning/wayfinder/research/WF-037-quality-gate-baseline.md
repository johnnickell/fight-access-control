# WF-037: Quality-gate timing and reliability baseline

## Short answer

PHPUnit with coverage is the largest cost. The four sampled failed CI runs never reached it: stale generated planning views stopped them first. No test flake or safe deletion was demonstrated. First investigate why planning views were stale on the tested revisions and make timing-sensitive test setup deterministic where equivalent proof is possible. For wall time, prioritize profiling PHPUnit over Composer or coverage post-processing.

## Hosted baseline

Three successful first attempts of the [Tests workflow](../../../.github/workflows/tests.yml) used the same reported PHP 8.5.13/Xdebug 3.5.3/PHPUnit 13.4.8 and test counts. They are comparable observations, not repeated measurements of the same revision or a controlled cold/warm experiment. Phase figures are rounded from hosted log timestamps except PHPUnit's own timer. Queue time is excluded from job time. Raw logs are ignored under `.runs/wayfinder/WF-037/`.

| Action run / revision | Job | Shared gate | Composer update | Syntax / PHPCS | PHPStan production / fixtures | PHPUnit discovery + launch / timed execution | Coverage verification |
|---|---:|---:|---:|---:|---:|---:|---:|
| [44683648333](https://github.com/johnnickell/fight-access-control/actions/runs/44683648333), `51bb5ed8` | 9:31 | 9:16 | 6s | 47s / 48s | 4s / 43s | 9s / 6:22 | ~1s |
| [44677332888](https://github.com/johnnickell/fight-access-control/actions/runs/44677332888), `62586825` | 9:02 | 8:37 | 4s | 35s / 48s | 4s / 39s | 9s / 6:01 | ~1s |
| [44657122554](https://github.com/johnnickell/fight-access-control/actions/runs/44657122554), `7891cf47` | 10:19 | 10:04 | 4s | 42s / 55s | 5s / 48s | 10s / 6:58 | ~1s |

PHPUnit's timer includes coverage work, not just test bodies. The gate performs one execution with coverage; discovery is separate. Each run reported 9,419 Unit, 847 Integration, and 7 Functional cases with zero skips, and exact statement and Unit-only line results of 11,959/11,959. Summed per-test time including setup and teardown ranged 243–283s Unit, 56–63s Integration, and 25–26s Functional. These sums do not account for all PHPUnit wall time. The 52 static fixture cases matched 567 diagnostics in 39–48s; that is the baseline for WF-038. The saved local receipt `20261120T045459Z-79114` records exit 0, 20:46 in the full gate excluding preparation, including PHPUnit's 14:41. Its source fingerprint is not a hosted SHA; do not pool that time with hosted runs.

## Reliability and ranked candidates

1. **Observed failures: planning, not tests.** [43060865089](https://github.com/johnnickell/fight-access-control/actions/runs/43060865089), [43059175690](https://github.com/johnnickell/fight-access-control/actions/runs/43059175690), [42936530080](https://github.com/johnnickell/fight-access-control/actions/runs/42936530080), and [42858898189](https://github.com/johnnickell/fight-access-control/actions/runs/42858898189) exited 1 on stale TASK index/Board views before PHPUnit. Investigate the authored/generated view mismatch against the exact tested tree, including the PR merge result where applicable. The logs establish gate rejection, not its upstream cause or a current defect. Keep the read-only validator; do not rewrite views in CI to conceal stale inputs.
2. **Timing-sensitive setup, not proven flakes.** The [native Task disconnect test](../../../tests/Adapter/Mcp/Task/Agent/AgentTaskNativeTest.php#L19) protects a durable commit across disconnect followed by independent completion and a fresh guarded read. Try replacing its fixture's [300ms wait](../../../tests/Adapter/Mcp/Task/Agent/native-server.php#L45) with a bounded after-commit handshake while retaining actual HTTP and disconnect. The [Subscription lease expiry test](../../../tests/Adapter/Mcp/Subscription/Agent/AgentSubscriptionAdmissionTest.php#L542) waits 6s but uniquely checks no healthy completion after expiry. Remove the wait only if the actual lease clock can be controlled without losing that proof; do not assume the authentication clock controls it. Preserve [native early delivery](../../../tests/Adapter/Mcp/Subscription/Agent/AgentSubscriptionNativeTest.php#L30) while considering a handshake instead of elapsed-time ordering. No replacement has been validated.
3. **Largest speed opportunity: PHPUnit.** Profile Unit setup and the difference between per-case time and coverage-enabled execution before removing assertions or changing execution strategy. Audit duplicate cases by behavior and retained proof, not count or filename; no candidate is cleared for deletion. Explore reducing coverage and harness overhead only while preserving attribution and exact results. Use WF-038 for static fixtures and inspect syntax/style costs next. The sampled Composer and post-processing times make them low priorities absent contrary clean-build evidence.

## Proposed benchmark for implementation planning

Target at least **15% lower median shared-gate wall time** on matched hosted runs, provisionally about **7:52** against the observed 9:16 median. Establish comparable before/after repetitions (at least three each), same runner class and resolved dependencies, and report cache state separately. This is a target, not a promised saving or a release gate. Keep exact statement coverage, Unit-only line coverage, OpenAPI coverage and unique concurrency/transport proof. Repeat timing-sensitive cases under controlled setup and preserve exit results; no automatic retries or newly tolerated skips. No tests or workflows were changed to gather this baseline.

## Sources

- [Canonical quality gate](../../../bin/quality), [Testing standard](../../../docs/engineering/standards/Testing.md), and [Single-gate ADR](../../adr/0002-single-quality-gate.md).
- The exact hosted runs linked above and the existing local receipt (not a fresh local run).
