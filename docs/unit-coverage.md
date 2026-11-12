# Unit coverage and suite classification

`./bin/build` runs PHPUnit once. The same execution retains the existing exact all-suite statement gate and
separately enforces 100% **Unit-attributed executable lines** over every `src/` PHP file, including unexecuted
files. There are no source exclusions. Integration or Functional hits cannot fill a Unit gap.

## Attribution and maintenance

[The classification manifest](../etc/test-categories.json) assigns every runnable class a category and reason.
Explicit method entries handle mixed classes. These are judgments about actual setup, collaborators and behavior,
not directory names, execution speed or `CoversClass`. Unknown classes and stale class/method entries fail the check.
When adding or changing tests, review their actual boundaries and update the manifest deliberately.

- **Unit:** controlled collaborators and in-memory values/repositories, without real storage, network or tested
  worker-process effects. Direct adapter tests use controlled native interfaces rather than real framework pipelines.
- **Integration:** real database/transaction, framework routing/kernel or generated-schema boundaries.
- **Functional:** critical native HTTP/worker journeys and smoke behavior.

The isolated CodeIgniter tests load native class definitions but do not bootstrap an application, discover service
files, open a cache or invoke a real router. Consumer-owned test service configuration and injected collaborators
keep those boundaries controlled. PHPUnit subprocess isolation protects global framework configuration; it is not
worker-process behavioral proof. Existing native pipeline, SQLite, security and process tests remain separate.

## Evidence and enforcement

[The checker](../scripts/check_unit_coverage.py) consumes PHPUnit/php-code-coverage's serialized per-test line hit
maps. Only test IDs classified Unit count toward the executable line denominator. It verifies that the report
contains exactly the current source file set, including files with no executable lines. Clover's all-suite metrics
remain the input to `bin/coverage`; they are not relabeled Unit evidence.

The report names the native driver/version and serialization format; unsupported formats fail. Normal execution
collects line coverage. When a supplied report contains branch/path data, the checker reports Unit-attributed
branch blocks separately. A line-only report explicitly says branch data was not collected; this is not a branch
pass or a claim that Xdebug lacks support. There is no invented 100% branch requirement.

Retain separately measured branch evidence and its exact scope. With PHP 8.5.12 / Xdebug 3.5.3, full-suite
`--path-coverage` failed natively; a bounded multi-case generator class also reported heap corruption, while the
same class passed line-only. A single generator case passed both modes, so the exact native root cause remains
unproven. Isolated adapter/Application branch probes succeeded; they are scoped measurements, not complete-library
branch certification. No product test or source is excluded from the line gate to conceal this limitation.
Use the supported PHPUnit `--path-coverage` option for explicit focused qualification, with a separate raw report
and sufficient memory (the current focused probe used 1536 MiB). Do not replace a failed full measurement with an
unlabeled warm rerun, install a different driver implicitly or infer missing branches from line totals.

The native TeamCity event log supplies expanded runnable/data-provider counts, skipped counts and measured
per-category test durations, including setup/teardown. Discovery, report writing and runner/coverage work outside
those intervals are not category time; retain PHPUnit's total runtime and the native build receipt separately.
The checker reconciles these counts with PHPUnit's expanded test enumeration. It does not use JUnit's DOM-based logger. The approximate
1000:100:10 suite balance is review guidance, not a quota; no category ratio is an executable pass condition.

Normal output is under ignored `var/reports/coverage/`:

- `clover.xml`: the existing all-suite statement evidence;
- `coverage.php`: trusted locally generated per-test raw coverage;
- `tests.xml` and `teamcity.log`: expanded enumeration and measured execution;
- `unit.json`: exact per-file Unit lines/gaps, explicit branch collection status and category counts/runtime.

Run `python3 scripts/check_unit_coverage.py` inside the selected PHP runtime to recheck these artifacts. Their
source freshness still requires the native build receipt and its input bridge, not a standalone checker exit.
`--inspect` permits a focused subset and reports evidence without awarding a coverage pass. Do not load an
untrusted PHP coverage file.
The normal build receipt already retains `var/reports`; no private scratch, second suite execution, dependency
installation or product tests of the checker are required. Qualify checker changes directly with its owning command.
The [Testing standard](engineering/standards/Testing.md) remains the governing requirement.
