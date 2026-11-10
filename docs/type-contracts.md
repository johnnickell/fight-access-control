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

TASK-00132 adds twelve exact paged-handler/container-element probes in `paged-query-positive.php`.
These cover all six ResultSet<View> contracts and actual ArrayList element inference, not generic bus inference.
Runtime routing separately qualifies strict incoming/mapped construction and request/scope validation; see
[paged Query contracts](paged-queries.md). Existing Domain negative fixtures retain incompatible element proof.

TASK-00146 adds eleven exact native HTTP probes and eight rejected incompatible uses: PSR request/handler/result,
Symfony event tuple/callback/request, Laravel native next/response, CI4 optional denial/context, null after hook and
native responder/application result. Wrong framework requests, copied context as authority, wrong factory inputs,
non-native next/results and unchecked nullable contexts reject. All seven owned production boundaries receive
focused level-8 analysis; dynamic native route/action/filter metadata retains runtime validation. This is not
generic bus typing or real consumer bootstrap/sink qualification; see [ordinary HTTP contracts](ordinary-http-failures.md).

TASK-00144 adds 17 positive worker/port/result probes and five rejected incompatible uses: void revocation,
delivery/maintenance enums, bounded recovery maps, trusted next-run time, optional receipt, sensitive invocation,
rewrap material and independent authority/cleanup ports. The debug shape is exactly redacted. The final bus probe
intentionally asserts current `mixed`, not generic Query-result inference; the worker validates its one dynamic
result boundary at runtime. Configured Common routing and safe-error/public behavior have separate runtime proof.

TASK-00131 adds six exact non-null direct-handler result probes in `required-resource-positive.php`.
These assert the declared Views, not generic inference across Common's current mixed QueryBus. Runtime routing,
identity/failure semantics and preserved nullable repositories have separate [required-resource proof](required-resource-queries.md).

The initial two foundation cases assert six positive types and six incompatible uses: UserId versus RoleId, UserView Role
lists and canonical serialized shape, nullable UserRepository lookup, FeatureReferences names and typed array-map
callbacks. Expectations come from those current package declarations. No production API or business behavior changes.
Static collection/callback inference does not validate untrusted values, enforce PHP generics at runtime, establish
request identity or prove business effects.

TASK-00128/00129/00130 retain generic dispatch qualification using this manifest/runner and actual supported Common
contracts. TASK-00136 has a scoped operator amendment accepting static eventRegistration() plus correct local Event
payload annotations; generic upstream Event inference is not required. Its 14 positive probes explicitly report
Common's actual Payload return and string registration shape, three EventMessage/void callbacks, the native Clock
instant and all eight locally annotated payload contracts. Five incompatible uses reject a Command as an Event,
CommandMessage as EventMessage, an unrefined payload getter, nullable restoration generation and cross-purpose ID.
These local annotations do not prove registration inference or validate untrusted payloads. Every actual mapping and
real dispatcher/bus behavior has separate [runtime composition proof](delivery-event-contracts.md), including
minimum/ordinary lanes. Dummy/direct handlers cannot substitute for those real production routes.
TASK-00117 adds two Domain cases: 21 exact positive types and nine incompatible uses covering Permission/Role
identities, nullable repositories and typed batches/pages, Role revisions/membership, principal lists, policy definitions
and plan items, exact plan/Event/message shapes and typed callbacks. The owned Domain source is also checked at level 8
during that slice's focused verification; this does not change the ordinary whole-library level. Runtime tests separately
exercise decoded-input validation, round trips, dynamic principal snapshots and existing authority/reference fences.
No bus/Event dispatch generic or real persistence qualification is claimed by these Domain fixtures.

TASK-00120 adds two Feature Domain cases: 23 exact positive types and seven rejected incompatible uses cover
identities/status, both nullable lookups, typed repository pages, every Command/Query/Event output, View and
preparation shapes/lists, and typed mapping callbacks. Runtime regressions separately prove decoded-input type
validation, named variadic list projection, positive View revisions and overflow-safe Feature transitions.
Focused level-8 analysis covers the complete Feature Domain, without changing the whole-library analysis level
or claiming bus generics, real persistence or independent acceptance.

TASK-00119 adds two Agent operation Domain cases: 21 positive types and eight incompatible uses for exact
Command/Query/Event/policy/key/issuance payloads, confirmed-versus-indeterminate serialized state, nullable
read/write lookups, material/attempt optionality, typed work lists and callbacks, and the independent read-port
boundary. See [current operation contracts](agent-operation-type-contracts.md). These do not establish generic
bus inference or actual consumer persistence; runtime recovery/fence/serialization tests remain separate.

TASK-00118 adds Agent identity/administration cases with 31 exact positive types and 11 incompatible uses:
typed Permission/principal lists, both nullable repository lookups, typed pages, the lazy rename clock and nullable
change time, all owned message/View/provenance shapes, typed callbacks and extending aggregate successors.
Focused level-8 analysis covers the owned Agent Domain source separately from the ordinary whole-library level.
Runtime regressions prove decoded types, explicit event instants, native-array constructor validation, revision
exhaustion/no-ops and subtype preservation. Existing authentication/administration/recovery/Tool tests retain
behavioral fence/redaction proof. This adds neither bus generic qualification nor actual consumer persistence proof.

TASK-00135 adds preparation/preview cases with eight exact positive types and three rejected incompatible uses:
non-null direct-handler/planner results, issue/item lists and serialized shapes. Existing Feature/Permission/Role
fixtures retain definition, action enum, message and callback proof. Real configured routing/safe-error tests are
separate runtime proof; Common's current mixed QueryBus still does not establish generic result inference.

TASK-00134 adds four exact handler-result probes and two rejected collection/count uses, and updates operation
repository work probes to `AgentDeliveryWorkView`. Public work handler results remain `list<AgentOperationView>`.
These fixtures qualify direct native/static contracts, not the separately owned generic bus relationship;
real configured routing and safe-error composition have independent runtime tests.

TASK-00133 adds credential Query cases: eight exact positive types and three incompatible uses prove both status
unions, explicit absence narrowing/payload, invitation origin, precise due/expired lists and unchanged nullable
repository lookup. Runtime tests separately prove actual tagged Common routing and safe errors in minimum/ordinary
compositions. This does not claim generic bus inference or consumer persistence qualification.

TASK-00138 adds eleven exact provider/generator/cipher/invocation/result probes and four rejected incompatible
uses for human credential workers. All 24 owned Application declarations also receive focused level-8 analysis.
The runtime suite separately exercises all eleven real Command routes and decoded messages, intentional safe
failure categories, internal failures, time/claim boundaries and existing family recovery/expiry guarantees.
These fixtures establish neither generic Command/Event inference nor consumer persistence/provider qualification.

TASK-00137 adds ten exact callback probes for all eight void User lifecycle handlers and both target-specific
UserId authorization ports, plus four diagnostics rejecting wrong-purpose identities and use of void as a result.
Owned handler analysis checks the local callback-result annotations separately. These fixtures neither qualify
Common bus/Event generics nor replace runtime identity, failure, transaction and authorization proof.

TASK-00141 adds eight exact probes for all four void administration handlers, the coordinator's three nullable
purpose-specific Event results and the repository's lazy rename callback, plus five diagnostics rejecting
wrong-purpose actors/list members, unhandled nullable Events and use of void as an Agent result. These local
contracts do not claim generic bus inference. Real tagged Common routing/safe-error tests are separate runtime proof.

TASK-00140 adds seven exact void-handler callback probes and nine rejected incompatible uses for all five Role
and both User membership handlers. These reject consuming void results, wrong-purpose Permission identities and
string revisions. Focused level-8 analysis also checks every owned handler and its known local Event/Event-null
transaction-result annotation. Real seven-route serialized dispatch and safe HTTP error composition remain separate
runtime proof; no generic bus/Event inference or actual consumer persistence qualification is claimed.

TASK-00143 adds nine exact positive contracts for both void issuance callbacks, generator/ciphers, all three
operation authorization methods and the non-null outcome Query, plus four rejected void/result/identity/tuple uses.
Real registered Command/Query dispatch and Common safe HTTP middleware are separate runtime proof; this does not
qualify generic bus inference, production crypto or consumer persistence.

TASK-00142 adds ten exact probes for all six void Feature/policy handler callbacks, Boolean availability,
scoped discovery/references and merged name lists, plus eight rejected incompatible uses. Existing policy/Feature
fixtures retain plan/action/definition/message/callback precision. Owned level-8 analysis separately checks the
actual local Event/null/list annotations. Real Common execute/decoded dispatch and relevant safe errors are runtime
proof, not generic bus/Event inference or consumer scanner/persistence qualification.

TASK-00145 adds 16 exact authority/admission/continuation probes and four rejected incompatible identity/provider/
provenance uses. These cover distinct User/Agent snapshots, exact Permission definitions/lists, selected context,
validated declaration lists, original continuation claims, Boolean nonce/crypto/scope ports and the synchronous void
bounded-action callback. Dynamic declaration/continuation configuration remains validated at its owning boundary,
not falsely narrowed by an annotation. Owned level-8 analysis checks all 23 current Application declarations;
real admission/continuation and relevant Common safe-error/protocol composition remain separate runtime proof.
No generic bus/Event or real consumer crypto/persistence/fence/handoff qualification is claimed.

TASK-00148 adds 14 exact Resource/Skill probes and eight rejected incompatible uses: exact provider/revision and
Permission lists, optional requirements, Boolean availability, typed mapping callbacks and cross-family metadata.
The descriptor export and arbitrary StrictJson property deliberately retain their actual dynamic types; runtime
owner validation, not a fixture annotation, narrows them. Focused level-8 analysis covers all six family declarations.
Actual Common discovery/read/responder and request-scope proof remain separate from static inference and do not
qualify real consumer storage, parser, crypto, bootstrap or diagnostic sinks.

Native transactional results remain `mixed`; a matching local `@var` for a known owned callback result (including
tuples/unions) remains sufficient. No generic UoW supplier or redundant runtime check is required.

TASK-00150 owns exhaustive declaration/contract inventory. TASK-00152 must reconcile that inventory with accepted
correction slices, establish sufficient whole-library rules and fixtures, justify genuine dynamic boundaries and
trace current caller/runtime/supplier evidence. Representative cases and production PHPStan level 6 do not complete
that audit. Newly discovered source defects return to their semantic owner; never hide them in fixture expectations.
