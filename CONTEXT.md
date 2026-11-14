# Fight AccessControl Context

## Optional Feature setting audit — TASK-00159 Work

Both setting handlers support optional final nullable audit storage and require the existing Application Clock
before the Unit of Work. Both Commands accept optional direct typed originating actor IDs with canonical nullable
kind/ID fields. Enabled real changes admit provenance before persistence and explicitly record bounded typed Feature
before/after revision, status and Permission relationships in the same transaction, with actual message correlation
and trusted outcome time. Validated no-change remains unaudited; full-state/reference fences and ordinary failure
semantics remain. See [current composition](docs/feature-management.md#optional-atomic-setting-audit-unreleased).
Independent Review and nonvisual QA remain required; this is not consumer database qualification or delivery.

## Direct Agent Permission audit — TASK-00157 accepted checkpoint

The three direct Permission handlers now support optional last nullable audit storage. Each owns one transaction
containing authoritative assignment mutation and explicit evidence insertion; the internal coordinator stages work
and exposes separate typed outcome facts without committing or recording evidence. Actual assignment revisions,
changed relationships, transition time and message correlation are retained without Agent/credential snapshots.
Validated no-ops remain unaudited, and current eligibility/reference/cohort/CAS and ordinary publication behavior
remain. See [current composition](docs/agent-administration-contracts.md#optional-direct-permission-audit-composition-unreleased).
The Work gate passes 9,553 tests / 94,168 assertions and exact 11,137/11,137 all-suite statements and Unit-only lines
across 544 source files without exclusions. Minimum composition passes 347 tests / 2,913 assertions; its 43 advisories
across 13 packages are retained separately from the advisory-free ordinary resolution. Independent Review ACCEPT and
nonvisual QA PASS cover candidate `b414263f6646700fb5605f3499f30c2735f636f7` (62 scenarios / 1,013 checks).
TASK-00157 is done; unfinished siblings keep its parents open. Publication/final delivery verification remain pending
at this administrative checkpoint. Canonical `.runs/handoffs/TASK-00157/land.md` owns subsequent delivery/retirement
results; `.runs/handoffs/TASK-00157/work.md` owns complete execution evidence and limits. Consumer mapping/connection
qualification, merge, release and deployment remain separate.

## Optional User Role membership audit — TASK-00155 accepted checkpoint

Both membership handlers now accept optional final nullable audit storage. Enabled `AuditUserRoleOutcome` joins
membership persistence in the same transaction, with the required originating User actor, real assignment-counter
transition, typed Role and observed complete Role reference revision, trusted outcome time and message correlation.
No generic User revision, Role/authentication mutation or new caller precondition is invented. Disabled composition
makes no durability promise; validated retries add no record. Existing User-state, managed Super Admin, reference/CAS,
rollback, uncertain-commit and ordinary publication-failure contracts remain. See
[current composition](docs/role-membership-contracts.md#optional-user-membership-audit-composition).
Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`819e42fa58c506a37ca78af6be5f1684f57ddde4`; QA passed 43 scenarios / 466 checks without findings. TASK-00155 is done;
unfinished siblings keep TICKET-00045/EPIC-00017 open. Publication/final-head verification remain pending at this
checkpoint; canonical `.runs/handoffs/TASK-00155/land.md` owns delivery and retirement outcomes. Package fixtures do
not qualify consumer storage/schema, connection/locks, entry/target authority, last-admin policy or deployment.

## Synchronous optional atomic audit — TASK-00127 accepted checkpoint

The four direct User capabilities and RevokeSessionHandler now accept optional final nullable audit storage.
[Current composition](docs/user-authentication.md#optional-atomic-audit) covers activation, login, refresh, logout,
password change/reset, email confirmation and self/admin session management. Enabled evidence joins the owning
transaction; disabled composition makes no durable-audit promise. Authentication facts retain actual User and
session revisions plus activation's consumed grant. Terminal replay records its committed revocation before
outward refusal, while benign conflicts and concurrent helper no-change produce no duplicate record. Existing
credential, recovery, CAS, authorization and post-commit failure contracts remain. Explicit provenance is not a grant.
Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`41d03e5fe030afe7b7158fc254d5f3a43bdf8c6d` (116 scenarios / 655 checks). Land's behavior-preserving merge of
TASK-00125's current reset-handler base passes a fresh complete gate: 9,230 tests / 92,740 assertions and exact
10,934/10,934 statements and Unit-only lines across 543 source files, without exclusions. TASK-00127 is done;
unfinished siblings keep its parents open. Publication/final verification and owned retirement remain pending
at this checkpoint; canonical `.runs/handoffs/TASK-00127/land.md` owns subsequent outcomes. Sensitive non-CQRS
contracts do not guarantee blanket native PHP serialization guards. Real consumer connection, storage, race,
cryptography, transport and deployment qualification remain separate.

## Scoped execution and timeline composition — TASK-00089 accepted checkpoint

[Scoped execution reads](docs/agent-execution-reads.md) now compose inward `AgentExecutionReadAdmission` and
consumer `AgentExecutionScope` with real Common summary/list/get/phase/activity handlers/readers and the existing
mixed-Tool binding. Conjunctive direct read Permissions precede consumer relationship grants; independent reference
Permissions can only remove reference grants, never widen structural/activity scope or grant body bytes.
Direct Queries retain the same admission. Ordinary requests reuse one principal/nonce; later requests reauthenticate.
Common's invocation-local effective-access obligation remains consumer-owned, not an atomic snapshot or race guarantee.

The current package floor is **Common ^1.5.1**, released source `c73690164a9f112dbf14d774c56a7c01519b88aa`.
The earlier v1.5.0 foundation/feature minima below retain their historical scope, not today's manifest floor.
TASK-00211's released correction resolves the original one-of-two activity-summary failure before SQL aggregation
and derived freshness/order/model projection. Controlled real-authority/SQLite Tool and direct-Query tests prove
count/partial usage, empty restrictions, SQL exclusion before decoding, scoped references and typed optional facts,
fresh pages, truthful absence/retention, safe faults/encoded overflow and unchanged business state/events/body reads.
Exact-minimum/ordinary dependency locks and canonical gate/coverage evidence belong to
`.runs/handoffs/TASK-00089/work.md`. No historical unfinished source was recovered; this implementation starts from
current develop ownership. Independent Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`baf3e01bc6540b887c4afcb33b25d6d09aeb0b1b` (seven QA scenarios / 70 checks). TASK-00089 and eligible
TICKET-00029 are done for package acceptance. Earlier full-gate evidence is retained with current closeout
checks; publication/final verification and owned retirement are pending at this checkpoint. Canonical
`.runs/handoffs/TASK-00089/land.md` owns subsequent delivery and cleanup outcomes.
Actual consumer relationship/storage/crypto/transport/sink and authority-writer concurrency qualification, the
combined unassigned EPIC release, adoption, merge and deployment remain separate.

## TASK-00152 operator-requested PHPStan scope amendment

John explicitly rejected PHPStan analysis of tests/scripts after the split production/test-tool passes were
explained. Ordinary analysis now covers only production `src/` and shipped `openapi/` metadata; the separate
tests/scripts invocation and its configuration are removed. Required production API type-contract fixtures,
meaningful PHPUnit behavior checks, all-src Unit-only line coverage and exact all-suite statements remain.
See the [governing scope decision](planning/agents/project-profile.md#tests-and-delivery). Independent Review v3
ACCEPT and behavioral QA v3 PASS cover amendment candidate `a0586cffd2a2a3d735476769414d5cf7de802f35`,
with seven affected scenarios passing and no findings. Earlier acceptance below retains its original subject.
The qualified full gate is retained with current planning/documentation checks; canonical Work evidence owns
its results and tested-content mapping. Same-PR publication and owned retirement are pending at this closeout
checkpoint; canonical `.runs/handoffs/TASK-00152/land.md` owns subsequent delivery and cleanup outcomes.

## TASK-00152 integrated contracts — independently accepted

Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`55cdb2325d11a6455e07dd0fdb8cfb7793737a88`, with R-01 resolved and 40 selected QA outcomes passing.
[TASK-00152](planning/tasks/00152-TASK.md) completes sufficient ordinary static enforcement, exhaustive current
contract/caller reconciliation and independent Unit-only line qualification. The accepted gate passes
9,072 tests / 91,391 assertions, 50 static cases / 564 diagnostics and 10,759/10,759 Unit-only executable
lines across all 540 src files without exclusions, separately from exact all-suite statements.
TICKET-00042 and EPIC-00016 close automatically with their terminal children. Existing slice reports,
qualified tagged compositions and source/evidence bridges retain their original subjects.

Land's recovery integrates only develop's container Git patch; restored local inputs require fresh normal
verification. Publication/final recovery verification and owned retirement are pending at this checkpoint;
canonical `.runs/handoffs/TASK-00152/land.md` owns subsequent outcomes. Full branch instrumentation failures
and scoped data remain separate, not complete-library certification. Historical minimum advisories and
consumer qualification limits remain; no merge, release or deployment is implied.

## Historical TASK-00152 approved Unit-only verification recovery

John approved additional isolated Unit tests, complete behavior-based suite classification, measured runtime and
bounded coverage enforcement. The [single canonical PHPUnit execution](docs/unit-coverage.md) retains exact all-suite
statements and separately attributes executable lines to Unit test IDs. Every src file is included; real framework,
storage, schema and native journey protection remains separate rather than filling Unit gaps. Scoped branch data
and the reproduced full-suite branch-instrumentation failure are reported separately, not called a branch pass.

The current base also adds independently accepted Task-live and invitation-audit contracts. Their current semantic,
caller and supplier bridge and the changed candidate's verification belong to the canonical Work handoff for
[TASK-00152](planning/tasks/00152-TASK.md). Original independent reports and earlier reconciliation retain their exact
historical subjects. Fresh independent Review and behavioral QA must assess this new test/enforcement work;
no self-acceptance, publication or consumer qualification is implied.

## Historical TASK-00152 integrated enforcement — earlier Work candidate

The approved bounded precision amendment corrects eight demonstrated static obligations in User, both Permission
constructor adapters and subscription-family composition, without changing their requirement ownership or historical
acceptance. Production/OpenAPI and ordinary caller analysis now reject nullable and incompatible union uses under
the same rules as required fixtures. At that historical checkpoint, test/script analysis retained its existing
rules separately; the later explicit scope amendment above removes that pass. See
[type-contract enforcement](docs/type-contracts.md). Engineer semantic/source/caller and tagged-dependency reconciliation
now has no pending primary or secondary concerns, including later actual capability and metadata amendments. This is
Work evidence, not whole-library acceptance: fresh independent Review/nonvisual QA remain required, and actual
consumer qualification is separate.
The canonical `.runs/handoffs/TASK-00152/work.md` owns exact candidate, checks, warnings and remaining scope.

## Optional complete Task live state — TASK-00102 accepted checkpoint

[Optional Task live composition](docs/agent-task-live.md) adds current original-bound Task signal and complete-state
read admissions, consumer `AgentTaskDisclosurePolicy`, and the required Common disclosure guard/action integration
through the existing continuation guard/fence. `AgentTaskServing::tasks()` exposes the identical ordinary capability;
optional Subscriptions attach to its existing registry. Complete state comes only from shared Common Task Queries,
never general notices/tracking/Mercure. Independent listening, Task signal, read and consumer field/visibility scope
remain mandatory. No opening snapshot, nonce replay, credential switch, new engine or production lock is introduced.

Common v1.5.0 source `06fb5cd3720af9cf8295198f74040d95b64c5155` remains sufficient. Controlled real-authority,
SQLite, shared read/live revision, guarded handoff and native independent-process fixtures own package proof;
canonical `.runs/handoffs/TASK-00102/work.md` records actual checks, dependency/runtime identities, interrupted-run
history and limits. Minimum qualification passes 43 Task tests / 450 assertions; the final ordinary full gate passes
8,957 tests / 90,902 assertions and exact 10,732/10,732 statements (78/78 touched production statements).
Minimum dependencies retain 43 advisories / 13 packages; ordinary resolution reports none.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`5059ea4b95736f3af84c15b24b6e6a5087ea6814`, without findings. QA passes ten scenario groups / 186 independent
driver checks plus 24 tests / 148 assertions. Review's fresh full gate passes 8,957 tests / 90,843 assertions,
exact 10,732/10,732 statements; normal Land build validates automatic reuse with fresh planning. The earlier
interrupted failure's lost assertion and exact timing cause remain unproven, despite successful later gates and
QA's deliberate six-second native producer delays without retries. TASK-00102 and eligible TICKET-00031 are done
for package acceptance; unfinished children keep EPIC-00014 open. Administrative closeout retains full-gate
evidence with current planning/documentation checks. Publication/final verification and owned retirement are
pending at this checkpoint; canonical `.runs/handoffs/TASK-00102/land.md` owns subsequent delivery outcomes.
Common's mandatory consumer all-writer guard obligations remain unchanged, not production synchronization or
atomic permission-check-and-send claims. Consumer storage/crypto/policy epochs/authority writers, proxies, workers
and client merge/receipt remain separate qualification. Ordinary polling/input/cancel require no live activation;
no adoption, merge, release or deployment is claimed.

## Current-subscriber inspection — accepted lifecycle-test repair

TASK-00095 is done for independently accepted package implementation and required QA after its bounded lifecycle-test correction.
A deterministic committed forward heartbeat between query-clock sampling and scanning proves truthful uncertain
freshness, not a production defect or the exact historical failure cause. Native worker assertions now send SIGKILL
immediately after acknowledgment, verify retained unclosed evidence and classify at controlled persisted observation
and expiry instants; recent as-of evidence never implies current connectivity. Production contracts and incoming
optional Prompt behavior are unchanged. Canonical `.runs/handoffs/TASK-00095/work.md` owns repair verification;
independent Review v3 ACCEPT and nonvisual qa-repair-01 PASS cover candidate
`b8df3471bdde41611b2c55059b39da61d674e2d9`, base `82499db9d9029679ce41c5502dcd67dbeeaf1b3a`.
QA's seven groups/154 checks and fresh complete gate pass (8,913 tests / 90,384 assertions,
exact 10,654/10,654 statements). Publication/final verification are pending at this closeout checkpoint;
canonical `.runs/handoffs/TASK-00095/land.md` owns the advancing-base bridge and delivery/resource results.
Minimum compatibility retains 43 advisories / 13 packages; ordinary none. Production consumer qualification,
other unfinished EPIC scope and the combined unassigned release remain separate. Historical acceptance below
retains its original subject, not repair acceptance.

### Historical accepted checkpoint

Independent Review v3 ACCEPT and nonvisual qa-02 PASS cover candidate
`4afcfc70b66c22042ad9349d5f2e524ec591cd16`. TASK-00095 is done for package acceptance and required verification:
QA's seven scenarios pass 7 tests / 149 assertions; its fresh complete gate passes 8,892 tests / 90,077 assertions
and exact 10,644/10,644 statements. Canonical Review/QA preserve subjects and qualification limits.
Publication and final delivery remain pending at administrative closeout; `.runs/handoffs/TASK-00095/land.md`
owns current delivery/resource results. Minimum compatibility has 43 advisories / 13 packages; ordinary none.
Other EPIC scope, the combined unassigned release and production consumer qualification remain separate.

### Historical Work checkpoint

[Subscriber inspection composition](docs/agent-subscriber-inspection.md) adds inward
`AgentSubscriberReadAdmission` and consumer `AgentSubscriberReadPolicy` over Common's actual summary/list admission.
Independent conjunctive direct Permissions precede trusted whole-registration metadata scope. Common owns actual
TASK-00093 registrations, complete-selection counts, bounded live scans/pages, freshness and safe failure wrapping.
Listen/read/publication grants, caller selectors, identities, role labels, request IDs and heartbeats confer no
inspection grant. Resolved ordinary requests retain their snapshot and one nonce; later requests reauthenticate.
No Tool, schema, store, SQL, lifecycle, heartbeat renewal, cleanup or business event is added.

Common ^1.5.0 remains sufficient, tagged source `06fb5cd3720af9cf8295198f74040d95b64c5155`. Controlled real
AccessControl/Common SQLite fixtures cover protected opening → direct/routed inspection, concealed whole rows and
counts, requested/accepted filters/references, changing scope/authority/pages, clean close, native worker SIGKILL,
late observation/renewal, cleanup and committed two-connection scan interleaving. These are live observations,
not guaranteed presence, historical absence, delivery/receipt or frozen browsing. The canonical
`.runs/handoffs/TASK-00095/work.md` owns exact minimum/ordinary dependency/runtime, gate/coverage results and warnings.
Independent Review and nonvisual behavioral QA remain pending at this Work checkpoint; TASK-00095 stays open.
Production consumer policy/storage/clock/process/concurrency/safe-sink qualification, adoption, release and deployment
remain separate and unclaimed.

## Complete guarded Task serving — TASK-00101 accepted checkpoint

[Slice E composition](docs/agent-task-authorization.md#complete-guarded-mcp-serving--slice-e) adds canonical
`AgentTaskServing` and request-scoped `AgentTaskRequestAdmission`. All four real Common serving handlers are
mandatory; the exact Tool registry receives real Agent Permission binding before complete Task activation.
The existing Common HTTP guard selects independently configured acknowledgment versus ongoing-state admissions
only after transport/protocol/mirror validation. Creation, Tool availability, input/cancel and producer/original
continuation grants remain separate. Common owns negotiation, original retained state, safe errors, encoded bounds
and lifecycle truth; consumers own submission/reconciliation, storage, scope and workers. No live output is enabled.

Controlled actual HTTP/SQLite fixtures cover commit then TCP disconnect, independent producer reconstruction and
fresh reconnect/get, complete input/confirmation/cancellation flow, denied ongoing operations after admitted start,
postcommit/response/submission faults and exact final encoded response bounds. Common v1.5.0 source
`06fb5cd3720af9cf8295198f74040d95b64c5155` remains sufficient; minimum/ordinary lane identities and actual checks
belong to canonical `.runs/handoffs/TASK-00101/work.md`. Independent Review v3 ACCEPT and complete nonvisual QA
PASS cover clean candidate `f979e009b6f8f4831d5ac405b5503b7b34fe363d`, without open findings: eight QA scenario
groups include independent actual HTTP/state and native disconnect/producer/reconnect proof. TASK-00101 is done;
At that slice-E checkpoint, TASK-00102 kept TICKET-00031 and EPIC-00014 open.
Earlier full gate retained: 8,898 tests / 90,048 assertions,
exact 10,678/10,678 statements (40/40 new adapters). Administrative planning/documentation checks qualify closeout.
Publication/final verification and retirement remain pending at this checkpoint; canonical
`.runs/handoffs/TASK-00101/land.md` owns subsequent delivery outcomes. Minimum-lane advisories remain disclosed,
not fixed; ordinary dependencies report none. This is not production crypto/storage/all-writer fencing,
external-effect, worker/client/proxy qualification, optional live-state acceptance, adoption, release or deployment.

## Optional Prompt catalog-change disclosure — TASK-00094 accepted checkpoint

[Prompt live composition](docs/agent-live-disclosure.md#optional-prompt-catalog-changes--task-00094) extends the
existing canonical Subscription admission with optional `AgentPromptPermissionCatalog` metadata eligibility. The
same immutable definitions serve ordinary Common Prompt discovery; current whole-catalog signal scope and configured
activity Permissions remain independent of list/get visibility. Original-bound listen/disclosure authority, current
Agent/credential/Permissions and consumer scope are rechecked at each queued/final signal check, without nonce replay,
credential switching or a send-spanning lock. Listening/emission invokes no Prompt body/argument validation, linked
read, business Query/effect or Tool/model execution. Later ordinary requests independently reauthorize.

Common v1.5.0 (`06fb5cd3720af9cf8295198f74040d95b64c5155`) remains sufficient. Common owns optional flags, actual
acknowledgment/standard frames, bounds, source/tracking and safe fault closure. Missing Prompt composition does not
block ordinary live families or subscription-free Prompt serving. Committed notices remain independently recoverable
after delivery failure, without complete-capture, stable-revision, receipt or exactly-once guarantees. Controlled
real-authority/SQLite/native HTTP tests and canonical `.runs/handoffs/TASK-00094/work.md` own actual minimum/ordinary
identities, runtime, checks, coverage, warnings and limitations. Independent Review ACCEPT and behavioral QA PASS
cover candidate `fb58cb81cae6bed995eb31f0a3b68123f0aa6c67`; TASK-00094 is done for package acceptance.
Publication and final delivery are pending at this closeout checkpoint; canonical `.runs/handoffs/TASK-00094/land.md`
owns subsequent delivery and retirement results. Consumer capture/redaction/policy/history/crypto/storage,
production concurrency/authority writers and deployed buffering/client receipt remain unqualified. No adoption,
merge, release or deployment is claimed.

## Live Tool, Resource and whole-Skill disclosure — TASK-00093 accepted checkpoint

[Live disclosure composition](docs/agent-live-disclosure.md) adds optional canonical Subscription adapters and
consumer `AgentSubscriptionScope`, reusing original-bound `AgentNoticeAuthority` at Common's opening and final
signal checks. Listen, independent whole-signal disclosure, family availability and current consumer scope remain
separate; one visible item does not authorize concealed catalog activity. Exact selected Resource/complete-Skill
member metadata is checked without content opening or Tool execution. Common owns source Queries, technical
tracking, standard frames, bounds and safe failure/closure. Source producer access does not grant recipient
disclosure or require the listener's public browse Permission; retained recovery independently uses TASK-00092.
No send-spanning lock, Task-state, Prompt, inspection, publication/Mercure or second runtime is introduced.

Common v1.5.0 (`06fb5cd3720af9cf8295198f74040d95b64c5155`) remains the sufficient minimum. Controlled real-package
fixtures exercise authority, routed DBAL SQLite/Sodium recovery/tracking, native HTTP pre-closure frames, queued
changes/faults and whole-Skill/member admission. The canonical `.runs/handoffs/TASK-00093/work.md` owns actual
dependency/runtime/check/coverage evidence and limitations. Work's full gate passes 8,752 tests / 87,604 assertions,
exact 10,625/10,625 global and 82/82 new adapter statements; minimum/ordinary focused lanes each pass 25 tests /
160 assertions. Independent Review v3 ACCEPT and complete nonvisual QA PASS cover candidate
`55b9ccb0b6ad6ef3533d99e99cae63ab98cb7657`: 16 QA groups / 130 checks. TASK-00093 is done;
unfinished siblings keep TICKET-00030 and EPIC-00014 open. Advanced-base integration, final verification,
publication and retirement outcomes belong to canonical `.runs/handoffs/TASK-00093/land.md`, pending at this
closeout checkpoint. Consumer policy/
history/crypto/storage, all authority writers, production concurrency and proxy/SAPI/client buffering remain unqualified. Neither acknowledgment nor emitted
bytes prove receipt/processing or exactly-once delivery. No adoption, release or deployment is claimed.

## Optional notice publication — TASK-00096 accepted checkpoint

[Explicit publication composition](docs/agent-notice-publication.md) adds inward `AgentNoticePublicationPolicy`
and consumer `AgentNoticePublicationScope` over Common v1.5.0's actual publication-policy seam. Each attempt
reuses original-bound `AgentNoticeAuthority` for fresh direct operation Permissions, then consumer topic/mode/
whole-audience/field disclosure. Common owns committed reconstruction, encoded bounds, statuses, diagnostics,
synchronous acknowledged-commit handoff and public/private Mercure adapters. No broadcaster, retry engine,
new grant, read lease, send-spanning lock or automatic registration is added. Private denial/fault/unsupported
output never falls back to public. Permission may change after the final check before sending.

Real authority, routed Common recording/inspection/recovery, SQLite and controlled-hub `Update` tests cover
safe projections, independent grants, changed current authority/scope, precommit failure, committed publication
failure/repeated attempts, recovery/retention truth and disabled output. Common ^1.5.0 remains sufficient;
optional Mercure ^0.7 is suggested, development-only for package tests. The canonical Work handoff owns exact
minimum/ordinary dependency/runtime/gate evidence and warnings. Independent Review v3 ACCEPT and complete nonvisual
QA PASS cover candidate `65034aff05c08adaca806f840aa4920c55c6a081`: 14 independent scenarios / 191 assertions plus
63 focused tests / 944 assertions, without blockers. TASK-00096 is done; unfinished siblings keep TICKET-00030 and
EPIC-00014 open. Behavior-preserving advanced-base integration, final verification/publication and retirement remain
pending at this closeout checkpoint; canonical `.runs/handoffs/TASK-00096/land.md` owns subsequent results and provenance.
Package fixtures do not qualify consumer policy, storage/crypto/authority races,
deployed hub/browser entitlement, receipt, MCP live-wire support, adoption, release or deployment.

## Cancellation admission — TASK-00100 accepted checkpoint

[Slice D Task composition](docs/agent-task-authorization.md#cancellation-requests-and-intent-observation--slice-d)
adds `AgentTaskCancellationAdmission` with independent configured cancellation/observation Agent authorities,
`AgentTaskCancellationScope` and the existing observation scope. Actual Common cancellation Commands/intent Queries
and `McpTaskCancellation` use these admissions; input/confirmation uses the independently protected intent port.
Common owns serialization, retained intent, committed-reader confirmation, safe repeats/terminal no-ops and bounded
acknowledgment. Neither cancellation Permission nor intent observation grants state/result access or worker control.
Ordinary requests retain their snapshot/nonce; original-bound protected continuation still rechecks current authority.

Common v1.5.0 (`06fb5cd3720af9cf8295198f74040d95b64c5155`) remains sufficient. Real routed SQLite fixtures exercise
both lock orders for cancellation/input/confirmation/terminal races, durable cross-process reads, rollback,
uncertain commit and postcommit/response loss. Intent preserves answers/consumption/terminal winners and proves no
stop, rollback or actual outcome. Controlled scope/fence/consumer-effect proof does not qualify production storage,
authority writers, cryptography, workers or exactly-once external effects. The canonical TASK-00100 Work handoff
owns dependency/runtime/check evidence. Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`cbc442a10b34ccace1cc20cdebbb1c409dca2026` (nine QA groups / 776 checks). TASK-00100 is done; TASK-00101/00102
keep its parents open. Canonical `.runs/handoffs/TASK-00100/land.md` owns advancing-base integration verification,
publication and retirement outcomes, pending at this closeout checkpoint. Complete MCP activation and live Task
output remain downstream, without a partial extension advertisement or live-delivery prerequisite.

## TASK-00147 protected profile Tool contracts — accepted checkpoint

The [current six-declaration/member dispositions](docs/agent-profile-tools.md#current-contract-dispositions-task-00147)
retain exact Common descriptor/Permission/request/nonce contracts, real routed profile/update handlers, safe exact-class
failure constants and normalized void acknowledgement. Dynamic ApplicationData name extraction now validates its
string instead of asserting mixed input; known Query result annotations follow TASK-00128's ratified amendment.
New behavioral/static checks distinguish schemas, actual cohort/name refusal, internal identity/subclass faults,
no-op persistence and committed publication failure. No extra workflow, result DTO, read or Event is introduced.
Canonical `.runs/handoffs/TASK-00147/work.md` owns verification and limitations. Independent Review v3 ACCEPT and
nonvisual QA PASS cover clean candidate `8d05c188ea86a3f0b2df5ae289ee60e5feea6251`, without blockers.
TASK-00147 and eligible TICKET-00041 are done; EPIC-00016 remains open for integrated enforcement. Earlier full gate
retained; current documentation/planning checks cover administrative closeout. Minimum advisories and the failed
supplementary older-analyzer check remain explicit limitations. Publication/final delivery checks are pending at
this checkpoint; canonical `.runs/handoffs/TASK-00147/land.md` owns subsequent bridge/delivery/resource outcomes.
This is not consumer, whole-library, approval, merge or release qualification.

## Durable Task input — TASK-00099 accepted checkpoint

The [slice C Task composition](docs/agent-task-authorization.md#durable-input-and-protected-continuation--slice-c)
adds inward `AgentTaskInputAdmission` with separate submission, protected answer-observation and group-continuation
requirements/scope. Independent `AgentTaskCancellationObservationAdmission` supplies Common input observation/acquisition's
intent port but always denies cancellation mutation. Issuance/retirement use independently configured existing producer
admission; public outstanding forms retain whole-state read admission. Common owns retained original contracts/arguments,
immutable caller/Task/group/expiry keys, active-subset validation, atomic answers, confirmations and durable single use.
Configured Common buses and actual `McpTaskInput` method preserve these checks without a second store/lifecycle or partial
Tasks advertisement. Original caller continuation uses TASK-00088's existing synchronized guard and ephemeral authority,
including independent intent requirements, never producer authority, cached answers or replayed nonces.

Common v1.5.0 (`06fb5cd3720af9cf8295198f74040d95b64c5155`) is the sufficient minimum. Exact-minimum and restored ordinary
feature lanes use real AccessControl authority/Common handlers and DBAL SQLite. Controlled scope/fence/effect fixtures
and actual SQLite race/fault proof do not qualify consumer crypto/storage/authority writers, worker lifetimes, external
effects or exactly-once execution. Consumption survives postcommit/response/consumer faults; uncertain commit needs
original-operation reconciliation before any dependent effect. Independent Review v3 ACCEPT and complete nonvisual
QA PASS cover clean candidate `92e29e30860cdfe538b45f3d90cf2077bc6b5968`: 11 QA groups / 217 checks.
TASK-00099 is done; unfinished siblings keep TICKET-00031 and EPIC-00014 open. Its canonical
`.runs/handoffs/TASK-00099/work.md` owns original checks, dependency/runtime identities and limits; canonical
`.runs/handoffs/TASK-00099/land.md` owns advanced-base integration, current verification and delivery/retirement
outcomes, pending at this closeout checkpoint. Cancellation mutation/races, complete MCP activation and live state retain their sibling owners; no release,
consumer adoption or deployment is claimed.

## Retained notice reads and recovery — TASK-00092 accepted checkpoint

[Retained notice composition](docs/agent-notice-recovery.md) now supplies inward `AgentNoticeReadAdmission` plus
consumer `AgentNoticeReadPolicy` for Common summary/list/detail/recovery handlers. Configured direct Permissions
precede trusted whole-metadata scope; coarse `notices.recover` Tool availability supplies no read grant. The same
configured QueryBus protects direct and Tool paths. Ordinary request principal snapshots and one nonce remain;
every later request needs a fresh provider. Common owns all queries/storage/coherent scanning, encrypted padded
continuation, truthful qualified status and encoded output bounds. No duplicate engine or read-side business effect
is added. The consumer supplies caller-bound access history and explicit namespace-evidence disclosure permission;
missing continuity stays uncertain/restart, never a fabricated complete-empty or an operator decision hold.

Common v1.5.0 (`06fb5cd3720af9cf8295198f74040d95b64c5155`) is the first sufficient supported minimum. Real routed
DBAL/SQLite fixtures use actual authentication/Permissions, Sodium continuation and polling/JSON-RPC boundaries,
including controlled recording commit followed by publication failure and authorized recovery without live runtime.
Canonical `.runs/handoffs/TASK-00092/work.md` owns minimum/ordinary locks, runtime, focused/full-gate/exact-coverage
results and warnings. Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`d989bdb25b897bd43c7ae87cd76e63498d18f561`: 13 independent scenarios / 235 assertions, fresh full gate
8,631 tests / 85,965 assertions, exact 10,451/10,451 global and 6/6 owned statements. TASK-00092 is done;
unfinished siblings keep TICKET-00030 and EPIC-00014 open. Land's behavior-preserving advanced-base integration,
publication/final delivery verification and retirement outcomes belong to canonical `.runs/handoffs/TASK-00092/land.md`;
delivery remains pending at this closeout checkpoint. Consumer policy, request lifetime/storage/cryptography,
historical evidence and production authority/retention races remain unqualified; no live/Mercure, receipt, sibling,
merge, release or deployment acceptance is claimed.

## Protected-detail admission — TASK-00090 accepted checkpoint

[Protected-detail composition](docs/agent-protected-detail.md) adds inward `AgentDetailReadAdmission` and the
consumer-owned `AgentDetailScope` port under Application/AccessControl/Agent/Detail. Real ordinary-request
Agent authority and separately configured conjunctive metadata/body Permissions intersect trusted consumer
structural/metadata/body scope in Common's actual ListCapturedMessages/GetCapturedDetail admission. Metadata-only
reads remain available without body Permission; timeline/coarse Tool grants and references confer no data grants.
Common owns relationship checks, SQL-scoped totals, safe projections, retention and bounded Base64 byte retrieval.
No relationship store, alternate reader, grant vocabulary or Common Adapter dependency inward is introduced.

Common v1.5.0 (`06fb5cd3720af9cf8295198f74040d95b64c5155`) is the first sufficient supported minimum; exact-minimum
and restored ordinary dependency lanes exercise real Tool/bus/handler/DBAL SQLite composition. Captures are UTF-8,
not arbitrary non-UTF-8 binary; Base64 byte chunks can split code points. Actual payload-SELECT counts prove denied
bodies are not retrieved/decoded. Fresh requests observe changed authority/scope; resolved ordinary requests retain
their existing principal snapshot and one nonce consumption. No continuous authority/storage guarantee is claimed.
Canonical `.runs/handoffs/TASK-00090/work.md` owns actual checks, lock/source identities, warnings and retained runtime.
Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`dbd36b9eb1b7e594d69cb0e61cc249be0d45c06a` (nine QA scenarios / 591 checks). Land's behavior-preserving
TASK-00097/develop integration passed a fresh full gate: 8,663 tests / 86,289 assertions, exact 10,523/10,523
statements. TASK-00090 is done; TASK-00089 keeps its parents open. Publication/final delivery checks are pending
at this checkpoint; canonical `.runs/handoffs/TASK-00090/land.md` owns subsequent outcomes and the provenance bridge.
Original independent subjects and Work history remain preserved. Package fixtures do not qualify deployed cryptography, relationships, persistence/races,
transport/sink lifetimes, consumer adoption or the combined unassigned EPIC release. TASK-00089 remains independent.

## Notice mutation admission — TASK-00091 accepted checkpoint

The [notice composition guide](docs/agent-notice-authorization.md) supplies independently configured recording and
bounded removal admissions for Common's real `NoticeAdmission` / `NoticeRemovalAdmission`. Application Agent/Notice
owns fresh original-bound Agent/direct Permission policy; consumers own current producer/scope/content/reference and
retention decisions. Common owns routed Commands, storage-lock ordering, retry comparison, all-target preflight,
positions/relationships/loss state, atomic mutations and actual post-commit dispatch. No new store, scheduler, grant,
protocol glue or authority-writer lock is introduced. TASK-00088's trusted binding is reused **inside** Common's
admission-under-storage-lock seam, not as cached pre-dispatch authority. Ordinary request snapshots and one-time nonce
consumption remain unchanged; the existing synchronized continuation guard's contract is not modified.

Common v1.5.0 (`06fb5cd3720af9cf8295198f74040d95b64c5155`) is the sufficient supported minimum. Real Common DBAL/SQLite
fixtures exercise recording/removal, current authority after actual storage-lock waits, data/event/rollback/uncertain
commit and dispatch-failure semantics. DBAL `^4.4` is development-only for these tests. Consumer false decisions retain
fixed Common rejection; malformed authority and operational faults propagate to the single consumer safe reporting
boundary, never exception-to-Boolean denial. Checks do not promise permission stability afterward or qualify real
consumer policy, cryptography, handoffs, writer races/storage, deployment or sibling notice capabilities. Independent
Review v3 ACCEPT and complete nonvisual QA PASS cover candidate `0689d72be2b8e1275a3ec5846a0f2f27ac660427`.
TASK-00091 is done; unfinished siblings keep TICKET-00030/EPIC-00014 and the combined unassigned release open.
Its TASK and canonical Work/QA handoffs own verification. Advanced-base integration, publication and final delivery
checks remain pending at this closeout checkpoint; canonical `.runs/handoffs/TASK-00091/land.md` owns subsequent
bridge, delivery and resource outcomes.

## TASK-00098 bounded retained Task removal — accepted checkpoint

[Task removal composition](docs/agent-task-authorization.md#bounded-retained-task-removal--slice-b) now supplies
independent configured Agent Permission/current consumer removal scope through Common v1.5.0's actual
`TaskRemovalAdmission` and routed `RemoveTasksHandler`. Common owns bounded whole-selection admission, atomic
aggregate removal, authorized missing-target no-op, commit uncertainty and postcommit removal facts. No removal
RPC, policy selector, store, scheduler or grant is added. Removal-admission faults follow Common's fixed
`TaskRejected`, not fabricated successful absence. Retained comparison/creation retry material disappears with
its aggregate; identity reuse is new creation, not permanent deduplication or a stale-producer fence across reuse.

The canonical TASK-00098 Work handoff owns minimum/ordinary dependency/runtime identity, focused real authority/
SQLite/bus/transaction/concurrent-lifecycle proof and full-gate/exact-coverage evidence. Controlled consumer scope,
retention/hold and background-fence fixtures do not qualify production policy/storage/authority writers, cleanup
scheduling, worker stopping or external effects. Independent Review v3 ACCEPT and complete nonvisual QA PASS cover
candidate `7bb8561e3a672937128ccea77cd3d8201325b798`; TASK-00098 is done. Unfinished siblings keep TICKET-00031 and
EPIC-00014 open. Publication/final delivery verification remain pending at this administrative closeout checkpoint;
canonical `.runs/handoffs/TASK-00098/land.md` owns subsequent delivery/resource outcomes. No sibling, release,
deployment or consumer qualification is claimed.

## Purpose

Fight AccessControl owns framework-neutral identity, credential, session, authorization, and account-lifecycle
behavior shared by Fight applications. The repository-local behavioral and security authority is
[TICKET-00001](planning/tickets/00001-TICKET.md).

## TASK-00097 durable Task admission — accepted checkpoint

The [slice A Task composition](docs/agent-task-authorization.md) supplies independent configured direct Agent
Permissions and consumer-owned creation, trusted producer and whole-state read scope through Common v1.5.0's
actual admission ports. Real Common Commands/Query handlers, buses, separate repository/UoW/committed reads,
original payload contracts, complete state, retry/terminal/retention and postcommit truth remain upstream-owned.
`AgentTaskAuthority::duringContinuation()` reuses TASK-00088's guard with a bounded action-only authority and
explicit consumer-scope fence wrapper; workers need no retained request provider or nonce replay. Original caller
continuation remains independently bound and guarded, never inferred from producer permission or Task creation.

The canonical TASK-00097 Work handoff owns exact feature minimum/ordinary-lane, SQLite/authority/current-worker,
full-gate/exact-coverage evidence and limitations. Doctrine DBAL is development-only for Common's actual SQLite
adapter qualification. Controlled scope/sharing/binding/visibility fixtures do not qualify actual consumer storage,
cryptography, durable visibility epochs, authority writers/locks, queues, worker lifetimes or external effects.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`efdc8beb9a10ea26886d16173bb2ad084d3a3167`, without findings: ten independent QA scenarios / 138 assertions
plus 35 focused tests / 687 assertions. Earlier full gate retained: 8,527 tests / 84,834 assertions, exact
10,397/10,397 global and 59/59 owned statements. TASK-00097 is done; unfinished children keep TICKET-00031 and
EPIC-00014 open. Publication/final delivery verification remain pending at this administrative checkpoint;
canonical `.runs/handoffs/TASK-00097/land.md` owns subsequent checks, publication and resource outcomes.
Original independent reports and Work history remain preserved. No later Task slice, partial MCP advertisement,
new lifecycle/executor, automatic grant, release or deployment is implemented here.

## TASK-00087 Prompt authorization — accepted checkpoint

Optional [Agent-protected Prompt composition](docs/agent-prompt-authorization.md) now binds nonempty conjunctive
canonical direct Permission requirements to the actual immutable Common Prompt definition objects in
`Adapter/Mcp/Prompt/Agent`. Common remains the only name registry, list/get engine and argument/output/error owner.
Trusted ordinary-request Agent authority stays inward; discovery constructs no bodies and direct get needs no listing.
Later requests recheck authority; an already resolved request retains its snapshot and consumes no nonce per definition.
Private zero-TTL results/cursors grant no continuing access. Controlled static/Query-backed providers and inert
Resource/Skill links retain independent current business/target admission with no automatic fetch, grant or execution.
Common v1.5.0 is the first sufficient supported Prompt minimum; the existing ^1.5.0 manifest needs no change.
Canonical `.runs/handoffs/TASK-00087/work.md` owns dependency/runtime, focused/full-gate and exact coverage evidence.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`1428f9bb9b5ff179332c1001e89009d964409986`, without findings. QA passes 12 groups / 161 new checks /
58 responses and a fresh full gate: 8,556 tests / 84,987 assertions, exact 10,384/10,384 global and 46/46 owned
statements. TASK-00087 and eligible TICKET-00027 are done; EPIC-00014 remains unfinished. Publication/final delivery
verification are pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00087/land.md` owns
subsequent bridge, checks, delivery and retirement outcomes. Original independent reports and historical failed/
interrupted gates remain preserved. No production consumer, release or deployment qualification or completion of
EPIC-00014's combined unassigned release is claimed.

## Mixed MCP Tool Permission bindings — TASK-00084 accepted checkpoint

The existing immutable Adapter `AgentToolPermissionCatalog` now accepts explicit canonical Tool-name Permission
bindings alongside attributed Tools, with exactly one nonempty validated source per Tool. Conflicts (even equal),
unknown mappings and malformed declarations reject complete composition. Equal requirements are deduplicated in
first-declaration order; all remaining requirements stay conjunctive. Common retains its exact definition objects,
schemas, discovery, invocation and interaction ownership; foreign definitions deny before principal resolution.
See [Tool composition](docs/agent-mcp-authorization.md) for the constructor, duplicate-key loader limit and examples.

The mixed journey uses all seven Common execution Tools plus attributed profile Tools with one real request-scoped
provider. Tool grants remain distinct from consumer Query scope and metadata/body admission; metadata-only protected
reads remain supported. Fresh later pages, calls and mapped retries need fresh authority/nonce; a transport failure
is terminal for the request. No blanket bus authentication, new grants, copied schemas or cross-request cache is added.
Common `^1.5.0` remains the minimum; no dependency manifest change is needed. Canonical
`.runs/handoffs/TASK-00084/work.md` owns original lane, gate, warnings and runtime evidence.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover candidate
`8ffa933d46e4d9b99a5105549c3d846ac94f4b62`, without findings: 12 QA groups, 107 tests /
1,012 assertions plus 105 independent cases / 2,009 checks. Land's normal build freshly passes
8,541 tests / 84,979 assertions, exact 10,357/10,357 statements after ordinary Laravel dependency refresh.
TASK-00084 and eligible TICKET-00025 are done; unfinished siblings keep EPIC-00014 open.
Publication/final delivery checks remain pending at this administrative checkpoint; canonical
`.runs/handoffs/TASK-00084/land.md` owns subsequent delivery and resource results. Minimum-lane
40 advisories / 12 packages remain disclosed; ordinary resolution reports none. No actual consumer
qualification, merge, release or deployment is claimed. Original independent report subjects remain preserved.

## TASK-00136 delivery Event contracts — accepted checkpoint

The [delivery Event contract map](docs/delivery-event-contracts.md) records all eight existing subscriber routes,
accurate local payload annotations, optional restoration, native Clock and actual Common metadata/failure limits.
John's scoped amendment accepts this design without generic upstream Event inference. New static and real
dispatcher/bus composition proof preserves committed issuance, exact safe actor/generation payloads and worker
failure/recovery semantics. Land retains TASK-00122's subsequently adopted typed actor contracts; current fixtures
and the contract map follow typed provenance and canonical anonymous identity, not historical string sentinels. Canonical `.runs/handoffs/TASK-00136/work.md` owns
complete inventory, tagged minimum/ordinary lanes, full-gate/exact-coverage evidence, warnings and runtime identity.
Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`3b1c7f99d4812c0c3d0042b6a5e406f6722a58b9`; canonical `.runs/handoffs/TASK-00136/land.md` owns the advancing-base
bridge, current checks, publication and resource outcomes. No whole-library or consumer qualification is claimed.

## Query dispatch — TASK-00128 accepted checkpoint

John's 2026-11-09 amendment retains native/static mixed QueryBus fetch/dispatch and permits known caller result
annotations, including collection elements. Each concrete handler declares its specific result or throws; dynamic
boundaries remain validated. No automatic generic inference or generic Common supplier prerequisite is required
for this slice. The [current dispatch manifest](docs/query-dispatch-contracts.md) maps all 22 current Query bindings,
both production callers, supported routing/pipeline composition, exact static contracts and runtime proof. Existing
correct result declarations/caller boundaries remain unchanged; current guide examples gain matching annotations.
The follow-up payload clarification requires one registered Query per handler and a flat local annotation immediately
above extraction in `handle()`. All 22 match after aligning preparation and preview while retaining their guards;
the shared Command/Query/Event style is documented in the PHP standard, not a sibling source sweep.
John subsequently permits this same precise-handler/known-caller result contract for TASK-00130's Agent outcome
Queries. TASK-00136 uses `eventRegistration()` plus concrete subscriber payload annotations and real routing proof,
not automatic generic inference or a new generic PHP/Common API. Their former generic supplier holds are cleared;
implementation and independent acceptance remain separately owned. TASK-00132 owns integrated strict page semantics.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`1e0f03a9b3383c663b6bcef9896ff450e959dc2f`, without findings. QA passes 12 groups, 53 new cases /
1,694 assertions plus 633 complementary tests / 7,491 assertions and 110 exact static diagnostics. Original full
gate passes 7,333 tests / 80,218 assertions, exact 9,385/9,385 global and 2/2 changed statements; QA's interrupted
extra build is not a pass. TASK-00128 is done; unfinished children keep TICKET-00038/EPIC-00016 open.
Integrated verification and publication/final delivery checks remain pending at this administrative checkpoint;
canonical `.runs/handoffs/TASK-00128/land.md` owns subsequent bridge, checks, delivery and resource outcomes.
Original reports and Work history remain preserved; no whole-library/consumer qualification or delivery is claimed.
Historical generic-typing observations below retain their original scope and are superseded for this Query slice.

## TASK-00132 paged Query contracts — accepted checkpoint

The six [paged Query paths](docs/paged-queries.md) now adopt Common v1.5.0 strict construction for incoming and
mapped results, check request metadata and preserve exact safe View mapping. Session reads independently check
admitted owner/usability; Agent exact Permissions, Feature binding information and Role revisions remain intact.
Captured page membership prevents dependency callbacks from changing enumeration; returned lists remain mutable.
Empty/out-of-range semantics and actual persistence/ordering limits are explicit, without new locks or writes.
Canonical `.runs/handoffs/TASK-00132/work.md` owns inventory, regressions, dependency/runtime and gate evidence.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover candidate
`98dcd31d4ab5e48ab4a8d9eb889dd0810b26aeba`, without findings: 11 QA scenario groups, 30 independent cases /
787 assertions plus 106 regression tests / 866 assertions. Earlier full gate retained: 7,251 tests / 79,950
assertions, exact 9,422/9,422 global and 142/142 owned statements, with Review-qualified prose-only follow-ups.
TASK-00132 and eligible TICKET-00039 are done; unfinished children keep EPIC-00016 open. Publication/final delivery
verification remain pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00132/land.md`
owns subsequent bridge, delivery and resource results. Original independent reports and minimum-lane advisories
remain preserved. No generic bus, whole-library or consumer qualification, merge, release or deployment is claimed.

## Canonical Common adoption — TASK-00110 accepted checkpoint

Current composition requires Common `^1.5.0`, published tagged source
`06fb5cd3720af9cf8295198f74040d95b64c5155`. The [consumed-contract inventory](docs/common-contracts.md)
maps every affected foundation spelling, MCP HTTP adapter and getter read, unchanged supported contracts and
future-feature owners. Exact-minimum and ordinary dependency lanes exercise actual registry/invoker/responder,
profile/Resource/Skill, authority/continuation and native safe-error composition. Upstream aliases retain runtime
identity, construction, serialization and safe errors; AccessControl adds no shim or new capability.

Supplier independent acceptance, final-main certification, publication and installed tag bytes resolve the old
needs-info premise. Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`c4fc6d249eed0e80e6f00237960b96c92416299f`, without findings: 11 scenarios / 314 new driver checks plus
504 tests / 5,403 assertions. QA's fresh full gate passes 7,080 tests / 78,397 assertions, exact 9,324/9,324
statements. TASK-00110 and eligible TICKET-00034/EPIC-00015 are done. Publication/final delivery checks remain
pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00110/land.md` owns subsequent bridge,
delivery and resource outcomes. Original independent subjects and historical gate failures are preserved. The
[Common v1.5.0 planning amendment](planning/epics/00014-EPIC.md#common-v150-readiness-amendment) clears the remaining
MCP family availability holds without accepting their unimplemented AccessControl integrations. Minimum/ordinary-lane
feature tests remain implementation work. Consumer ownership/scope/grants/access-change evidence are port inputs,
not missing operator decisions or a requirement for production adoption before package work. New disclosure/publication
composition checks permission before sending but does not exclude permission changes after that check; no send-spanning
lock or all-writer fence is intended. Existing TASK-00088 and upstream mandatory contracts remain unchanged. In particular, the pre-existing
Pagination constructor deprecation is inventoried, not silently replaced with behavior-changing strict validation.
Historical checkpoints below retain their actual older minima and planning observations; this section and the
current manifest/inventory own today's baseline. No sibling/consumer mutation, publication, merge, release or
deployment is implied by this package acceptance.

## Agent issuance dispatch qualification — TASK-00130 accepted checkpoint

The [issuance dispatch map](docs/agent-issuance-dispatch-contracts.md) inventories the two real void Command
bindings and independent GetAgentOperation Query, their concrete payload/message/metadata paths and owned callers.
New static probes distinguish precise handlers/known-caller optional outcome annotations from natively mixed Query
bus results; negative fixtures reject void-as-issuance and incompatible concrete uses. Runtime qualification reuses
the accepted conversion's production handlers, real Common routing, environments and stored-outcome oracle.
No business contract, transport, Command-result channel, generic supplier or consumer implementation changes.
Canonical TASK-00130 Work evidence owns actual dependency lanes, checks, logs, coverage and runtime identity.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`10385f66d55715325604117a881962b868722a84`, without blockers. TASK-00130 and eligible TICKET-00038 are done;
unfinished presentation/enforcement TICKETs keep EPIC-00016 open. Original independent subjects and minimum-lane
advisories remain preserved. Integration verification, publication and final delivery checks are pending at this
administrative checkpoint; canonical `.runs/handoffs/TASK-00130/land.md` owns the advancing-base bridge and
subsequent delivery/resource results. This is not whole-library, consumer, merge, release or deployment qualification.

## Command payload declaration contract — TASK-00129

John selected one supported Command/Query declaration and a flat local concrete annotation at payload extraction;
see the [canonical PHP rule](docs/engineering/standards/PHP.md#handler-payload-declarations). This replaces the
original generic Command supplier hold; Query result annotations follow TASK-00128 and its TASK-00130 amendment above.
All 37 baseline Command handlers already
have that declaration/flat-annotation structure; the two added issuance handlers remain TASK-00130's scope.
John subsequently selected trusted maps matching handler declarations and focused expected-registration unit
assertions; rejecting deliberately inconsistent explicit maps is not required. The retained Common v1.5.0
mismapped-router probe describes an out-of-contract configuration, not a blocker or supplier defect. TASK-00129
qualifies all 37 bindings through real routing, native/static contracts and message preservation, reusing existing
semantic tests. Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`51525609b3590dd7fe7075456c22270be9fa09af`, without findings. TASK-00129 is done; unfinished siblings keep
TICKET-00038/EPIC-00016 open. Land's behavior-preserving integration of paging/typed-audit develop passes a fresh
full gate (8,321 tests / 83,405 assertions, exact 10,267/10,267 statements) and the retained QA driver (26/202).
Publication/final delivery verification remain pending at this administrative checkpoint; canonical
`.runs/handoffs/TASK-00129/land.md` owns subsequent bridge/delivery/resource results. Original reports and
minimum-lane warnings remain preserved. No Common/local-bus implementation, sibling adoption, new business
change or actual consumer qualification is included.

## Audit outcome inventory — TASK-00166 accepted checkpoint

The [living audit matrix](docs/audit-outcome-coverage.md) reconciles the historical 37 + 7 + 8 baseline to current
39 CommandHandlers, seven sensitive User operations and six remaining Agent service entries, without counting
converted issuance or delegated recovery twice. It records observed writes/gaps, approved optionality/provenance,
actual transaction/external-effect boundaries and pending family/conformance/consumer proof. Optional auditing and
the evolved typed evidence API are not implemented by this documentation slice. Independent Review v3 ACCEPT
and nonvisual QA PASS cover candidate `1654b71a359e44547e21686b25e52ea12d2d8bf6`: ten scenario groups and
192 focused tests / 2,724 assertions, with no findings. TASK-00166 is done; unfinished siblings keep TICKET-00048
and EPIC-00017 open. Earlier full gate retained (7,009 tests / 78,157 assertions, exact 9,254/9,254 statements);
this is not fresh final-head execution. Publication/final delivery verification remain pending at this administrative
checkpoint; canonical `.runs/handoffs/TASK-00166/land.md` owns subsequent bridge, delivery and resource results.
Original independent reports and QA runner-option correction history remain preserved. Other audit suppliers retain
their own implementation and acceptance; no actual consumer qualification, merge, release or deployment is claimed.

## TASK-00148 Resource/Skill contracts — accepted checkpoint

The current [Resource](docs/agent-resource-authorization.md) and [Skill](docs/agent-skill-authorization.md)
contracts retain exact provider/URI and complete immutable-revision ownership, dynamically validated configuration,
precise typed exports and deliberate concealment versus operational-failure propagation. The Skill catalog now
narrows its actual dynamic JSON URI before association-key use; recursive Resource metadata callbacks keep justified
mixed values with explicit key types. Static fixtures and real Common composition preserve whole-entry versus
selected-member admission, fresh nested/later request authority, one ordinary-request nonce result, private zero-TTL
hints and opening/cleanup ordering. No business write, audit insertion, new transaction or grant is introduced.

Canonical `.runs/handoffs/TASK-00148/work.md` owns complete declaration/member/callback/caller dispositions,
minimum/ordinary dependency and focused/static/full-gate evidence, warnings and retained package runtime.
At this Work checkpoint independent Review and nonvisual QA remain required and TASK-00148 stays open;
this is not whole-library, actual consumer storage/parser/crypto/bootstrap/sink qualification, publication,
merge, release or deployment. Other slices and canonical Common adoption retain their own owners.

Independent Review v3 ACCEPT and complete nonvisual QA PASS now cover clean candidate
`91e4b9c78eb4e3cb98dd5b8d50c647cf9407ac00` against `311d6769668c8eea57758ae0c661465ca1b5e88c`,
without findings. QA passes 11 scenarios / 1,432 checks; Land's normal build cache miss freshly passes
6,800 tests / 73,498 assertions, exact 9,254/9,254 global and 124/124 owned statements. TASK-00148 is done;
unfinished children keep TICKET-00041/EPIC-00016 open. Publication/final delivery verification remain pending at this
administrative checkpoint; canonical `.runs/handoffs/TASK-00148/land.md` owns subsequent bridge, checks, delivery
and resource results. Original report subjects, historical Work proof and lowest-lane advisories remain preserved;
no whole-library or actual consumer qualification, approval, merge, release or deployment is claimed.

## TASK-00173 Symfony Feature admission — accepted checkpoint

Optional [native Symfony Feature admission](docs/symfony-feature-admission.md) now consumes the shared effective
requirements/evaluator at kernel.request after routing, independently of PermissionSubscriber. Trusted rematching and
resolved-controller integrity protect supported selected code; dispatch-owned provider sharing preserves both gates'
context and one Agent nonce. Only ordinary OFF/unmet PREVIEW is generic 404; Common retains one native safe-error owner.
The source-linked runtime flow documents pre-validation/action and lazy-construction boundaries, eager/late-listener,
non-JSON and real consumer limits. Minimum/core-only/native-without-Security-HTTP probes and ordinary composition remain
distinct evidence in the canonical Work handoff. Independent Review v3 ACCEPT and complete nonvisual QA PASS
cover `75dbe8418469b1bbf40506b364e6952430ab8c20`, with no outstanding findings: 12 QA groups including
40 new native journeys / 211 checks and 75 existing tests / 805 assertions. Land's behavior-preserving develop
integration retains native source/test bytes and the separate paged-query slice; fresh integrated gate passes
7,277 tests / 80,253 assertions, exact 9,493/9,493 statements. TASK-00173 is done; unfinished children keep
TICKET-00050/EPIC-00018 open. Publication/final delivery verification remain pending at this administrative
checkpoint; canonical `.runs/handoffs/TASK-00173/land.md` owns subsequent bridge, delivery and resource results.
Original independent report subjects and historical failures remain preserved. No actual consumer qualification,
approval, merge, release or deployment is claimed.

## TASK-00172 Feature admission — accepted checkpoint

Optional [PSR-15 Feature admission](docs/http-feature-admission.md) now connects the effective declaration supplier
to shared inward restriction/context semantics and real Slim/Yii 3 route-bound journeys. Feature-only registration
requires no Permission middleware; adjacent cooperating gates share trusted request-owned provider selection without
trusting copied contexts or replaying Agent authentication. Only ordinary OFF/unmet PREVIEW becomes generic 404;
missing/broken definitions and metadata/context/provider/storage faults remain safe errors through Common's one owner.
Availability still never authorizes an action. The guide records exact supported forms, eager-construction, request
identity/adjacency, selected JSON and consumer limits, with a source-linked runtime diagram.

Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`51f4dc199fc326388d3172268f8a4d3dc7817402`: 17 fresh behavioral groups / 107 cases / 1,015 checks,
with no findings. The fresh QA full gate passes 7,162 tests / 79,435 assertions and exact 9,366/9,366 statements.
TASK-00172 is done; unfinished native siblings keep TICKET-00050 and EPIC-00018 open. Publication and final delivery
verification remain pending at this administrative checkpoint. Canonical `.runs/handoffs/TASK-00172/land.md` owns
subsequent integration/administrative bridges, checks, delivery and resource outcomes; the Work handoff retains
original verification/dependency/runtime evidence, interrupted attempts and minimum-lane warnings. Original independent
reports remain unchanged. Other native peers and real consumer installations, merge, release and deployment are not qualified.

## Current public payload composition

The [current payload catalog](docs/public-payload-catalog.md) maps public messages, safe Query results, all seven
synchronous User payloads and deliberate internal/non-wire boundaries to the opt-in OpenAPI anchors. Consumers
explicitly project safe Views and TokenSet getters; arbitrary object serialization is not the wire contract.
Generated OpenAPI 3.1 samples run in the default suite without incidental production coverage. TASK-00170 now extends FeatureFlag to repeated class/method declarations with cumulative effective ancestry and
trusted route requirements; it remains declaration-only, without HTTP admission. See [effective declarations](docs/feature-references.md).
TASK-00170 has independent Review ACCEPT and nonvisual QA PASS on its named candidate; the owning TASK records
acceptance and canonical `.runs/handoffs/TASK-00170/land.md` owns subsequent delivery/resource results.
TASK-00171 adds [portable Permission declarations](docs/permission-requirements.md): repeatable canonical
class/method RequiresPermission and pure effective PermissionRequirements consume that same SelectedAction traversal.
Attribute-only protection, cumulative parent/route gates and explicit mode-conflict rejection preserve raw
PermissionDeclaration/admission semantics. Independent Review ACCEPT and nonvisual QA PASS cover candidate
`c6b01fd707f83bb34e75d715f787e45b258415fc` (12 scenarios / 80 checks); TASK-00171 and TICKET-00049 are done.
Earlier full gate retained: 7,080 tests / 78,397 assertions, exact 9,324/9,324 global and 19/19 added statements.
Publication/final delivery verification remain pending at this closeout checkpoint; canonical
`.runs/handoffs/TASK-00171/land.md` owns subsequent bridge/delivery/resource results. Original reports and minimum-lane
warnings remain preserved. Native portable Permission integration and the remaining native Feature peers stay
unfinished; the accepted shared/Slim/Yii Feature slice is described above. This is not whole-library or actual consumer qualification.
The earlier TASK-00149 acceptance below covers its original narrower contract, not this new behavior.
TASK-00149's canonical Work handoff owns implementation verification and limits. Independent Review v3 ACCEPT
and nonvisual QA PASS cover candidate `713837ab11122cafc5a66d00c0c0e6174eedb333`, with no open findings;
TASK-00149 is done, while unfinished siblings keep TICKET-00041/EPIC-00016 open. Publication/final-head verification
remain pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00149/land.md` owns subsequent
provenance, delivery and resource outcomes. Original report subjects and minimum-lane warnings remain preserved;
no actual consumer qualification, approval, merge, release or deployment is claimed.

## TASK-00144 Agent worker contracts — accepted checkpoint

The current [worker contracts](docs/agent-worker-contracts.md) retain exact safe delivery/maintenance outcomes and
source-qualified provider meanings, exact lookup identity, native mixed UoW/local known-result annotations and
revocation's distinct throwing policy. Recovery uses the configured QueryBus rather than an injected handler;
its bounded dynamic-result validation does not qualify TASK-00128's generic relationship. Current authority before
concealed reads, claim/admission/outcome separation, receipt-first restart, retirement/audit coupling, original
expiry/rewrap identity and external cleanup/acknowledgement uncertainty remain authoritative. Commit uncertainty
requires the owned invocation's completion evidence, not a collaborator's exception class. Failed recorded-receipt
verification is unavailable without refusal, history rewrite or restaging.

Canonical `.runs/handoffs/TASK-00144/work.md` owns exhaustive inventory/caller dispositions, regression chronology,
dependency/runtime/static/behavior/full-gate/exact-coverage evidence and limitations. Independent Review v3 ACCEPT
and nonvisual QA PASS cover clean candidate `94b151ddc4e4fff5ae3156a318a9a552449bbf2c` against develop
`17f7c8048bee3429f3ddbcfa66eb41968c299fe6`, with all four findings resolved. QA passes 122 adversarial cases /
540 checks plus 259 focused tests / 8,277 assertions; the qualified original gate passes 6,776 tests /
73,349 assertions, exact global 9,197/9,197 and owned 481/481 statements. TASK-00144 is done; unfinished children
keep TICKET-00040/EPIC-00016 open. Publication/final delivery verification remain pending at this administrative
checkpoint; canonical `.runs/handoffs/TASK-00144/land.md` owns subsequent bridge, checks, delivery and resource
results. Original independent report subjects and minimum-lane warnings remain preserved. No optional-audit feature,
generic bus or actual consumer qualification, approval, merge, release or deployment is claimed.

## TASK-00145 request authority and continuation — accepted checkpoint

The [current authority contracts](docs/authority-contracts.md) distinguish genuine User/Agent authentication refusal
from malformed or inconsistent authoritative snapshots and operational resolution faults. Exact Permission sets,
request-sensitive state and synchronized bounded continuation retain explicit native/static contracts. Nonce consumption,
original provenance time, current all-writer admission and post-invocation primary-error truth remain authoritative.
No new grants, executor, business transaction, Common implementation or consumer implementation is introduced.

Canonical `.runs/handoffs/TASK-00145/work.md` owns the exhaustive current declaration/member/callback/caller
dispositions, regression chronology, dependency/static/behavior/full-gate/exact-coverage evidence and retained runtime.
Independent Review v3 ACCEPT and complete nonvisual QA PASS cover clean candidate
`2c0570f66e0a59a068f92c1bf48c8d377aae413a` against `17f7c8048bee3429f3ddbcfa66eb41968c299fe6`, with no
findings. QA passes 12 scenarios / 335 checks / 36 protected effects and zero forbidden effects; its fresh full gate
passes 5,402 tests / 57,293 assertions, exact global 9,064/9,064 and owned 351/351 statements. TASK-00145 is done;
unfinished children keep TICKET-00040/EPIC-00016 open. Publication/final delivery verification remain pending at
this administrative checkpoint; canonical `.runs/handoffs/TASK-00145/land.md` owns subsequent bridge/delivery/
resource outcomes. Original Work history and independent report subjects remain preserved. Minimum-lane advisories
and deprecation plus QA's focused runner-option deprecation remain disclosed in the owning evidence. No whole-library,
generic dispatch, optional-audit or actual consumer qualification, approval, merge, release or deployment is claimed.

## TASK-00146 ordinary HTTP contracts — accepted checkpoint

This accepted checkpoint selected Common `^1.4.2` (current baseline: `^1.5.0`, above) and preserves one Common safe classification/reporting/presentation
owner across PSR/Slim/Yii and present Symfony/Laravel/CI4 peers. The
[ordinary HTTP contract map](docs/ordinary-http-failures.md) records deliberate Application 404/409/412/422,
private structured validation versus unknown/internal 500, required metadata and native/protocol/lifetime limits.
Symfony's exact event tuple is now statically declared; current native kernel examples explicitly select
`handleAllThrowables: true` for PHP Errors to enter safe exception dispatch. Constructors/named arguments and
inward policy/grants/nonce/continuation semantics are unchanged. Older framework checkpoints below retain their
original tested versions and do not describe the current supported minimum.

Canonical `.runs/handoffs/TASK-00146/work.md` owns exact source/tag/registry/runtime/lane/static/native/full-gate/
coverage evidence, historical prerequisite recovery and all warnings/limits. At the original Work checkpoint,
Review and nonvisual behavioral QA remained required. Controlled fixtures do not qualify consumer persistence,
crypto, target scope, sinks, bootstrap/proxies/HTML/deployment or an all-five-framework release.

Independent Review v3 ACCEPT and complete nonvisual QA PASS now cover clean candidate
`6f175c4b2bfca4a223af25c77f503b2995b4cb15` against `d1c18902add65ac65759c1ce5abd8500fdaf2b27`, with no product
findings. QA passes 37 independent scenarios / 214 checks plus 174 native tests / 2,122 assertions; the qualified
original gate passes 7,009 tests / 78,157 assertions, exact global 9,254/9,254 and owned 101/101 statements.
TASK-00146 is done; unfinished TASK-00147 keeps TICKET-00041/EPIC-00016 open. Publication/final delivery verification
remain pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00146/land.md` owns subsequent
bridge, checks, delivery and resource results. Original report subjects and historical warnings remain unchanged;
no actual consumer qualification, approval, merge, release or deployment is claimed.

## TASK-00143 Agent issuance contracts — accepted checkpoint

The current void provision/rotation handlers now distinguish source-qualified Common Application refusals from
sanitized internal failures. Exact retained-key/Agent/issuance and positive reservation promises are checked by their
owning boundary. The independent outcome Query retains confirmed/indeterminate Views and binding concealment, with
Common denial/unavailability categories. See [issuance composition](docs/agent-provisioning-operations.md#public-boundary)
and [rotation failures](docs/agent-rotation-operations.md#failure-bounds-and-delivery).

Native mixed UoW/local known issuance annotations, current execution/read authorization, original predecessor,
cohort/restoration, capacity/collision fences, same-transaction state/audit, uncertainty and committed issuance despite
both publisher failures remain authoritative. No retired service/result/warning, result channel, optional audit,
worker/authority redesign or consumer implementation is introduced. Canonical `.runs/handoffs/TASK-00143/work.md`
owns exhaustive dispositions, regression chronology, dependency/runtime/static/behavior/gate/coverage evidence and
limits. Independent Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`1b8f7113eb30f51d34411141b6564029fbcc16e1` against `0c8fd732e5d7c11a978f41918b913021fa00ba08`, with no
findings. QA freshly passes 118 cases / 2,693 checks plus 577 focused tests / 22,405 assertions; the original
complete gate passes 5,235 tests / 56,094 assertions, exact 8,928/8,928 global and 371/371 selected statements.
TASK-00143 is done; unfinished children keep TICKET-00040/EPIC-00016 open. Land's behavior-preserving merge
`f1e63299f7568b00c8b70f8042bc2b5fef789f5f` integrates develop `d5792e998b4f776a6974e866052ecc02b9946c1c`,
retaining both accepted slices and their original independent report subjects. Fresh focused integration passes
1,549 tests / 33,115 assertions and the retained QA driver passes 118 cases / 2,693 checks; this is Land evidence,
not a new independent verdict. Integrated full-gate/publication/final-head verification remain pending at this
administrative checkpoint; canonical `.runs/handoffs/TASK-00143/land.md` owns subsequent bridge/delivery/resource
results. Minimum compatibility advisories remain disclosed; ordinary dependencies are restored. No whole-library,
generic message typing, optional-audit feature or actual consumer qualification, approval, merge, release or
deployment is claimed.

## TASK-00142 Feature and policy mutation — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`c9a537f05ac499b07e34bf7c1babb583c3899c8b` against `0c8fd732e5d7c11a978f41918b913021fa00ba08`, with no
findings. QA passes 13 scenario groups / 372 checks and 634 focused tests / 3,327 assertions. The original full
gate passes 5,301 tests / 56,709 assertions, exact global 8,845/8,845 and owned 458/458 statements. TASK-00142
is done for this bounded slice; unfinished children keep TICKET-00040/EPIC-00016 open. Land integrates the
advancing Agent administration base without changing either slice's intended behavior. Integration verification
and publication remain pending at this closeout checkpoint; canonical `.runs/handoffs/TASK-00142/land.md` owns
current bridge, delivery and resource results. Original independent reports retain their exact subjects.
Lowest-lane advisories and actual consumer qualification limits remain explicit in the owning TASK.

### Historical TASK-00142 Work checkpoint

The six Feature/policy Commands now distinguish deliberate Common Application absence, explicit revision
preconditions, current-reference rule refusal and final state/reference conflict from wrong lookup identities and
unknown faults. [Current management contracts](docs/feature-management.md#mutation-contracts-unreleased) preserve
void handling, complete-empty discovery, lazy defaults, fresh Boolean restriction-only availability and existing
primary/uncertain/post-commit publication truth. One unchanged shared planner supplies preview and transaction-time
apply; unchanged managed definitions now also pass final nonwriting state/reference fences. Optional audit,
generic dispatch/Event typing and actual consumer scanner/database qualification remain separate.

Canonical `.runs/handoffs/TASK-00142/work.md` owns exhaustive declaration/member/callback/caller dispositions,
regression chronology, dependency lanes, real routing/safe errors, static/full-gate/exact-coverage and retained
runtime evidence for the implementation candidate. At that Work checkpoint, independent Review and nonvisual QA
remained required and TASK-00142 remained in-progress. Unfinished children keep TICKET-00040/EPIC-00016 open.
No sibling or whole-library acceptance, publication, merge, release or deployment is claimed.

## TASK-00141 Agent administration — accepted checkpoint

The four void administration handlers and relocated internal PermissionAssignment coordinator now distinguish
intentional Application absence/input/rule/precondition/conflict from internal repository identity/snapshot faults.
Domain owns precise rename/revision outcomes; Application translates only known outcomes without text/code
classification. [Administration contracts](docs/agent-administration-contracts.md) document current consumer
repository/catch obligations and the unchanged entry-authorization versus ADMIN_SAFE distinction, exact resolver,
validated no-op/revision/reference/cohort fences, native mixed UoW/local nullable-Event annotations and primary-failure/
commit/publication truth. Independent Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`190213325c22e5e93ce22f5ff4e02029e723420c` against `5e011cbc39bf935d3981433d6b993555d3a5df87`; R-01 is
resolved, with no open findings. QA passes 74 cases / 1,794 checks plus 138 tests / 1,314 assertions.

Land's behavior-preserving develop merge at `5e72e68762802a5b9f29ae433ddd5b12f8e34550` preserves both slices'
production/test bytes and combines shared static/inventory/context records. Fresh integrated focused verification
passes 313 tests / 2,762 assertions and the retained driver passes 74 cases / 1,794 checks. The normal full gate
executes freshly after a native cache miss: 5,190 tests / 55,654 assertions, exact 8,749/8,749 statements.
TASK-00141 is done; unfinished children keep TICKET-00040/EPIC-00016 open. Canonical Work retains original
inventory/regression/minimum/ordinary/full-gate evidence; original independent reports retain their exact subjects.
Publication/final-head verification remain pending at this administrative checkpoint; canonical
`.runs/handoffs/TASK-00141/land.md` owns current bridge/delivery/resource results. No generic bus inference,
optional-audit feature, real consumer qualification, approval, merge, release or deployment is claimed.

## TASK-00140 Role and User membership — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`a4a39aeff80ce482d93b8d287f742258cf4b91a5` against develop
`5e011cbc39bf935d3981433d6b993555d3a5df87`, with no findings. QA freshly passes 113 cases / 790 assertions
and 121 focused tests / 1,928 assertions plus owned level 8. The original fresh full gate passes 5,158 tests /
55,717 assertions, exact global 8,734/8,734 and owned 303/303 statements. TASK-00140 is done; unfinished
children keep TICKET-00040 and EPIC-00016 open. Land preserves both accepted slices through the advancing
session-contract base; the owning TASK records the integration and fresh focused checks. Integrated full-gate,
publication and final-head verification remain pending at this administrative checkpoint. Canonical
`.runs/handoffs/TASK-00140/land.md` owns subsequent bridge/delivery/resource results; independent reports retain
their exact subjects. Minimum-lane advisories and package/consumer boundaries remain explicit below.

### Historical TASK-00140 Work checkpoint

The seven [Role lifecycle/Permission and User membership Commands](docs/role-membership-contracts.md) now distinguish
intentional Common Application absence, rule refusal, caller-precondition failure and state conflict from wrong
identities, contradictory/reserved definitions and unknown faults. Success remains void. Domain owns decisions;
local owned Event/Event-null annotations retain native mixed UoW without a generic supplier or result DTO.
Role Permission no-ops also fence the complete current Role without writing. Consumer entry authorization,
tiers/reserved identity, revisions/incarnations, registration/reference/all-writer CAS, actually adopted audit and
primary/uncertain/post-commit failure truth remain authoritative; optional audit is not implemented here.

Canonical `.runs/handoffs/TASK-00140/work.md` owns exhaustive member/callback/caller dispositions, regression-first
chronology, actual minimum/ordinary dependencies, routing/static/safe-error/focused/full-gate/exact coverage and
retained runtime identity. At that Work checkpoint, independent Review and nonvisual QA remained required and
TASK-00140 remained in-progress. Unfinished children keep its parents open. No whole-library, generic bus,
consumer, publication, merge, release or deployment qualification is claimed.

## TASK-00139 synchronous User/session contracts — accepted checkpoint

The seven direct sensitive operations and secret-free `RevokeSession` Command now distinguish deliberate Common
Application refusals/conflicts from broken repository promises and unexpected faults. Actual narrow capability
homes, exact token/conflict/void results, known local UoW annotations and transaction/audit/authority/recovery fences
remain. Refresh bounded conflict does not revoke; terminal replay commits its safety mutation before outward refusal.
See [current composition and failure contracts](docs/user-authentication.md#explicit-failure-and-adapter-contracts-unreleased).

Independent Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`07a50e549a84b45b74e4d72cd6f0a44eb6aa8439` against develop `80f6d0d213a2324fdc02f29a26a17fc82ccd3702`, with no
findings. QA passes 24 groups / 906 checks; the qualified earlier full gate passes 5,011 tests / 54,432 assertions,
exact 8,496/8,496 global and 528/528 owned statements. TASK-00139 is done; unfinished children keep its parents open.
Canonical `.runs/handoffs/TASK-00139/work.md` retains exhaustive dispositions, regression chronology and dependency/
runtime proof. Publication/final-head checks remain pending at this closeout checkpoint; current administrative
bridge, delivery/resource results and fresh targeted checks belong to `.runs/handoffs/TASK-00139/land.md` and the
actual PR. Independent reports retain their exact subjects. Minimum-lane 43/14 advisories remain disclosed; ordinary
restoration reported none. No optional-audit feature, generic dispatch or actual consumer storage/crypto/transport
qualification, approval, merge, release or deployment is claimed.

## TASK-00138 human credential workers — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`e122bd09eac2a17e261257f4e2c34f486815e62b` against develop
`80f6d0d213a2324fdc02f29a26a17fc82ccd3702`, with no findings. QA freshly passes 125 independent tests /
1,376 assertions and 989 complementary tests / 7,507 assertions. Land's normal build executes freshly after a
native cache miss: 5,045 tests / 54,654 assertions, exact 8,596/8,596 global and 873/873 owned statements.
TASK-00138 is done; unfinished children keep TICKET-00040 and EPIC-00016 open. Publication/final-head verification
are pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00138/land.md` owns the bridge and
current delivery/resource results. Original independent reports and minimum-lane warnings remain preserved.
No whole-library, generic bus, optional-audit, real consumer, merge, release or deployment qualification follows.
The following Work checkpoint is historical, not a current independent Review/QA action.

### Historical TASK-00138 Work checkpoint

The eleven human request/delivery/retry/resend/cancellation/expiry Commands retain void execution and distinct
family no-work, authority and cleanup semantics. Their Engineer candidate separates intentional Common Application
failure categories from broken lookup/claim promises and internal faults, with exact owned callback results and
provider/invocation contracts. See [current worker contracts](docs/credential-delivery.md#request-and-worker-contracts-unreleased).
Required audit/transactions, User-before-grant recovery fencing, claim/generation/time checks, provider-outside-
transaction behavior, expiry cleanup, redaction and post-commit publication truth remain authoritative.

Canonical `.runs/handoffs/TASK-00138/work.md` owns exhaustive declaration/member/caller dispositions, regression
chronology, private intake provenance, minimum/ordinary/routing/static/full-gate/exact coverage and retained runtime
identity. Independent Review and nonvisual QA remain required; TASK-00138 stays in-progress, with no parent closeout,
publication, generic bus qualification, optional-audit implementation or real consumer qualification claimed.

## TASK-00137 User lifecycle contracts — accepted checkpoint

The eight account handlers and two target-specific authorization ports now have independently accepted corrections.
[Current lifecycle contracts](docs/user-lifecycle-contracts.md) distinguish deliberate absence, denial, conflict and
explicit expected-generation failure from wrong identities, broken configuration and unknown faults. Success remains
void; Domain transitions, registration defaults/origins, complete-state fences, adopted audit, recovery/session
cancellation, uncertainty and primary publication errors remain authoritative. Known callback results use local
annotations with native mixed UoW; no generic supplier, result DTO or optional-audit redesign is introduced.

Canonical `.runs/handoffs/TASK-00137/work.md` owns exhaustive declarations/member/callback/caller dispositions,
regression-first evidence, actual dependency-lane/runtime/dispatch/static checks and final gate/coverage results.
Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`d1e1e3a44781468f1a4d210389da179c30e55394` against `80f6d0d213a2324fdc02f29a26a17fc82ccd3702`, with no findings.
QA passes 17 scenario groups / 1,426 assertions and 286 preservation tests / 4,000 assertions; the retained complete
gate passes 5,036 tests / 54,677 assertions, exact 8,505/8,505 global and 414/414 owned statements. Lowest-lane
advisories remain disclosed; ordinary dependencies were restored before that full gate. TASK-00137 is done;
unfinished children keep TICKET-00040/EPIC-00016 open. Publication/final delivery verification are pending at this
administrative checkpoint; canonical `.runs/handoffs/TASK-00137/land.md` owns current bridge/delivery/resource
results. No consumer qualification, sibling acceptance, approval, merge, release or deployment is claimed.

## TASK-00135 preparation and policy preview — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`853e63718e3e158171af7b2e6498ace9d971c1c1` against `313db75af6821dbeaff9ee889697fb9e48628af6`, with no
findings; QA passes 14 scenarios / 606 checks. Land's behavior-preserving develop merge preserves both slices'
production/test contracts at `88398980f347a00e909e916b2e304793e0f2fb1d`. The fresh integrated full gate passes
4,912 tests / 54,142 assertions, exact 8,354/8,354 global and 291/291 owned statements; focused interaction/static
checks and the existing scenario driver pass. Original independent reports retain their exact subjects, not a
new integrated verdict. TASK-00135 is done; unfinished children keep its parents open. Publication/final-head
verification are pending at this checkpoint; current bridge/delivery/resource results belong to canonical
`.runs/handoffs/TASK-00135/land.md` and the actual PR. Lowest-lane advisories and consumer qualification limits
remain explicit. No whole-library, consumer, approval, merge, release or deployment claim follows.

### Historical TASK-00135 Work checkpoint

The two [preparation](docs/feature-preparation.md) / [policy-preview](docs/operation-outcomes.md#managed-policy-composition)
paths now distinguish legitimate typed diagnostics and known policy refusal from wrong repository identities,
relationships and unknown failures. One shared planner retains deterministic exact plans, tiers/reserved identity,
reference guards and preview/apply agreement. Apply replans inside its transaction and retains final fences;
queries remain read-only advisory observations, never reservations or authority.

Canonical `.runs/handoffs/TASK-00135/work.md` owns exhaustive member/caller dispositions, regression-first evidence,
minimum/ordinary real routing/safe-error proof, full gate/exact coverage and retained runtime provenance. Independent
Review and nonvisual QA remain required; TASK-00135 stays in-progress and unfinished children keep its parents open.
No Common/consumer mutation, generic bus inference qualification, optional-audit redesign, publication, merge,
release, deployment or consumer qualification is claimed.

## TASK-00134 Agent Query contracts — accepted checkpoint

The four operation/status/due/maintenance/count read paths now distinguish caller/binding concealment from
broken repository promises and unexpected internal faults. The narrow read interface remains independent of
material-bearing writes. Its work reads return secret-free `AgentDeliveryWorkView` evidence internally so handlers
can validate predicates, exact bindings, selection time, current reservation, bounds/order and cursor/grace policy;
public handler results and status wire shapes remain unchanged. Existing intentional operation failure reasons,
confirmed/indeterminate original issuance, retained history and current authority/cohort/restoration checks remain.
See [current Query contracts](docs/agent-query-contracts.md) for breaking PHP adapter/failure obligations and limits.

Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`4bca0f4c235d98a6920093c216c148e8a97df5c1` against develop
`8655f35011405ebaf2ffa2cf2655c339fdbfc2e0`. TASK-00134 is done for this bounded package slice;
unfinished children keep TICKET-00039 and EPIC-00016 open. Canonical `.runs/handoffs/TASK-00134/work.md`
owns regression chronology, exhaustive dispositions and retained minimum/ordinary/full-gate proof. Publication
and final delivery verification are pending at this administrative checkpoint; current bridge, delivery and
resource outcomes belong to `.runs/handoffs/TASK-00134/land.md` and the actual PR. Independent reports retain
their exact subjects. No consumer qualification, release or deployment is claimed.

## TASK-00133 develop integration — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover clean integrated candidate
`5b7829595f92e075f5ae85a053b287b29fe4688e` against develop `313db75af6821dbeaff9ee889697fb9e48628af6`,
with no findings. Fresh residual QA passes 14 cases / 400 checks and shared journeys 14 tests / 418 assertions;
criterion-specific original/incoming evidence remains explicitly retained, not relabeled fresh. The integrated
full gate passes 4,868 tests / 53,942 assertions, exact 8,292/8,292 global and 229/229 combined changed statements.
Both TASKs remain done; unfinished siblings keep parents open. Existing-PR publication/final-head checks remain
pending at this checkpoint; canonical `.runs/handoffs/TASK-00133/land.md` owns the administrative bridge and current
delivery/resource results. Original acceptance/history retains its exact subjects; no merge, release, deployment
or consumer qualification is implied. The following Work checkpoint is historical, not a current Review/QA action.

### Historical integration Work checkpoint

The existing published feature branch integrates pinned develop `313db75af6821dbeaff9ee889697fb9e48628af6`,
preserving TASK-00131's required-resource result-or-failure contracts and TASK-00133's explicit optional status and
bounded family discovery. Shared registration journeys retain both result contracts; static cases and structural
fingerprints include both slices. Both TASKs remain historically done and their original independent reports retain
their exact subjects, not a new combined verdict. Canonical `.runs/handoffs/TASK-00133/develop-integration.md` owns
both-parent provenance, conflict resolutions, fresh integrated verification and recreated-runtime identity.
Independent bridge review/applicable QA and Land's existing-PR update remain coordinator-owned; this Work stage
performs no push, PR mutation, teardown, acceptance, merge into develop, release or deployment.

## TASK-00133 credential status/discovery — accepted contract

The four human credential Queries now have independently accepted corrections: optional status returns a safe View or
explicit `CredentialDeliveryAbsent`, invitation inspection retains recorded origin, and each discovery source is
validated before deterministic global top-N selection. Nullable Domain lookups and distinct family authority/recovery/
expiry semantics remain; no query mutates, admits work, commits, audits or publishes. See the
[current contract](docs/credential-delivery.md#explicit-status-and-bounded-discovery-unreleased).

Inventory, regression chronology, static/runtime/minimum/ordinary and full-gate evidence belong to canonical
`.runs/handoffs/TASK-00133/work.md`. Review ACCEPT and nonvisual QA PASS cover clean candidate
`c62d261726082547757cba98eb3af150afa4665d`; TASK-00133 is done, while unfinished children keep its parents open.
The owning TASK preserves acceptance and verification; canonical `.runs/handoffs/TASK-00133/land.md` owns the
administrative bridge and current delivery/resource results. Consumer qualification, approval, merge, release
and deployment remain separate.

## TASK-00131 required-resource Queries — accepted checkpoint

Independent Review v3 ACCEPT and independent nonvisual QA PASS cover candidate
`e5c39fe1256f43e81a7c9a682d5f0250b97e1fff` against `8655f35011405ebaf2ffa2cf2655c339fdbfc2e0`,
with no findings; QA passes 14 scenarios / 609 fresh checks. TASK-00131 is done for its six-query slice.
Land's normal build freshly passes 4,789 tests / 53,683 assertions and exact 8,215/8,215 statements;
the original gate and exact 71/71 changed-statement evidence remain separately preserved. Minimum-lane
advisories remain disclosed, with ordinary dependencies restored. Unfinished children keep TICKET-00039 and
EPIC-00016 open. Publication/final-head verification are pending at this administrative checkpoint; current
bridge, delivery/resource results and actual PR belong to canonical `.runs/handoffs/TASK-00131/land.md`.
Independent reports retain their exact subjects. No whole-library/consumer qualification, merge, release or
deployment is implied. The Work checkpoint below is historical, not a current Review/QA instruction.

### TASK-00131 required-resource Queries — historical Work checkpoint

The six [required-resource Queries](docs/required-resource-queries.md) now have an Engineer candidate: exact non-null
safe Views or deliberate Common Application `ResourceNotFound`. Nullable Domain repositories remain unchanged.
Each handler checks requested identity before disclosure/related reads; wrong identities/native contracts and
operational failures remain internal. Agent profiles conceal absent/revoked targets identically, with fresh reads
and unchanged outer authorization. Feature missing-binding data, exact Agent Permissions and Role revisions remain.
The profile Tool consumes result-or-throw and explicitly binds ResourceNotFound, not generic lookup/configuration faults.

Canonical `.runs/handoffs/TASK-00131/work.md` owns refreshed inventory/caller dispositions, regression-first proof,
supported dependency lanes, canonical gate/coverage and retained isolated-runtime provenance. Independent Review
and nonvisual QA remain pending; TASK-00131 stays in-progress. No consumer/Common/provider changes, generic bus
inference adoption, optional audit, publication, merge, release or deployment is claimed.

## TASK-00120 pinned develop integration — Work checkpoint

The existing Feature branch integrates develop `4ea9fcd3a78624fae041232859eae2f249daaf3b`, retaining
TASK-00118/00119's accepted Agent contracts and TASK-00120's accepted Feature implementation. Original
independent reports keep their exact subjects; this mechanical integration is not a new combined verdict.
Canonical `.runs/handoffs/TASK-00120/work.md` owns the provenance bridge, fresh integrated verification and
new runtime identity; Land must independently qualify that bridge before updating the existing PR.
All six Domain children are terminal here, so automatic parent completion closes TICKET-00037. EPIC-00016
remains open for its other unfinished children. Earlier sibling-status observations below are historical,
not current counts. No push, PR update, approval, merge into develop, release or deployment is claimed by Work.

## TASK-00120 Feature Domain types — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`fb01e06e9b86ff1bdc550eaef437573ec3896b84` against develop
`458a35ac224d8dd6b855c4652db9fad34c327187`, with no findings. QA passes eight independent scenarios /
243 fresh assertions. The earlier complete gate is retained: 4,605 tests / 52,666 assertions and exact
8,073/8,073 global / 225/225 Feature Domain statements, with qualified prose-only follow-ups.
TASK-00120 is done; unfinished children keep TICKET-00037/EPIC-00016 open. Publication/final-head verification
are pending at this administrative checkpoint; current PR, bridge, proof and resource outcomes belong to
canonical `.runs/handoffs/TASK-00120/land.md` and the actual PR. Independent reports preserve their exact subjects.
No whole-library, consumer, merge, release or deployment qualification is implied. The Work checkpoint below
is historical, not a current independent-Review/QA instruction.

### TASK-00120 Feature Domain types — historical Work checkpoint

The complete current Feature Domain boundary has an Engineer audit/correction candidate: all 30 declarations,
including members, preparation values/callbacks, messages, exceptions, View and repository contracts. Payload
outputs now expose precise static shapes; ListFeatures rejects wrong input types without coercion, named variadic
preparation issues serialize as a list, direct Views require positive revisions and real Feature changes reject
revision exhaustion before integer overflow. See [management types](docs/feature-management.md#precise-domain-types-unreleased)
and [preparation output](docs/feature-preparation.md#precise-preparation-output-unreleased).

Nullable repositories, expected-state/reference/tier/retirement guards, no-ops and availability versus action
permission remain unchanged. Exhaustive candidate/member/caller/disposition inventory, regression-first logs and
verification/runtime/content mapping belong to canonical `.runs/handoffs/TASK-00120/work.md`. Independent Review
and nonvisual QA remain required; this checkpoint does not self-accept, close the TASK/parents, qualify consumer
persistence, publish, merge, release or deploy. Other Domain/Application/Common/audit owners retain their scopes.

## TASK-00119 develop integration — accepted delivery checkpoint

Independent Review ACCEPT covers integrated candidate `9e5a21be9244c43c2cc8ae36f9a1bc669918f874` against
`309b62f3a76d9a2b399ae5dfc1b3ceb17b4d8780`. The mechanical bridge qualifies retained scenario evidence from
both original independent QA reports; this is not a fresh integrated QA PASS. The new integration gate passed
4,579 tests / 53,165 assertions and exact 8,193/8,193 statements. Publication/final delivery checks remain pending
at this checkpoint; current administrative bridge and delivery/resource results belong to canonical
`.runs/handoffs/TASK-00119/land.md` and [PR #169](https://github.com/johnnickell/fight-access-control/pull/169).
Both TASKs remain done; unfinished children keep their parents open. Historical reports/checkpoints stay intact.

### Historical integration Work handoff

The feature branch integrates develop `309b62f3a76d9a2b399ae5dfc1b3ceb17b4d8780`, retaining accepted
TASK-00118 identity/admin contracts, including Permission iterable validation, alongside TASK-00119's operation/
cohort/delivery/maintenance contracts and R-001/R-002 repairs. Both historical acceptance subjects below remain
unchanged; neither is a new integrated verdict. Canonical `.runs/handoffs/TASK-00119/work.md` owns the exact
base/head/content/input/runtime bridge, both-parent provenance and fresh integrated verification. The Build
coordinator owns independent evidence qualification and subsequent delivery; Work performs no PR update or teardown.
Both TASKs remain historically done; TASK-00120 and other unfinished children keep their parents open.

## TASK-00119 Agent operation typing — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`3ce0b96c5ca713673be4e74e20f4aa9d46b5c7a4` against
`458a35ac224d8dd6b855c4652db9fad34c327187`. R-001/R-002 are independently resolved; no new blocking findings.
TASK-00119 is done for its complete 47-declaration package slice. TICKET-00037 and EPIC-00016 remain open for
unfinished children, including TASK-00118/00120; no sibling, whole-library or consumer qualification is implied.
The retained Engineer-02 gate passed 4,495 tests / 52,776 assertions and exact global 8,128/8,128 and owned
743/743 statements. Independent QA freshly passed 15 nonvisual scenarios / 482 checks.

Publication/final delivery verification are pending at this administrative checkpoint; the accepted-to-published
bridge, fresh closeout checks and current delivery/resource results belong to canonical
`.runs/handoffs/TASK-00119/land.md` and the actual PR. Retained gate evidence is not fresh final-head execution.
Canonical independent reports and earlier failures remain unchanged. No approval, merge, release or deployment
is claimed. The Work checkpoint below is historical, not a current Review/QA instruction.

### TASK-00119 Agent operation typing — historical Work checkpoint

The current 47-declaration operation/recovery Domain slice now has strict Event decoding, exact safe payload
shapes and a discriminated confirmed/indeterminate View representation. Current operation hydration rejects
contradictory state without fabricated history; retained-request resolution checks original issuance correlation.
Counter exhaustion, invalid capacity evidence and malformed cohort capability lists fail safely. Read/write
repositories, void issuance Commands, independent outcome Queries and current publication/recovery/fence contracts
remain separate and unchanged in authority. See [current contracts](docs/agent-operation-type-contracts.md).

Independent Review requested R-001/R-002; the first shared repair now caps hydrated admission at original pinned
retention and constrains model delivery replacements to preserved policy and valid claim/admission/outcome
succession. The canonical `.runs/handoffs/TASK-00119/work.md` owns refreshed exhaustive declaration/member/callback/
caller dispositions, regression-first evidence, fresh verification and exact candidate/dependency/runtime mapping.
Original evidence is preserved separately. Independent re-review and nonvisual QA remain required; this Work
checkpoint does not accept the TASK, qualify consumers, change sibling TASK-00118 or authorize publication,
merge, release or deployment.

## TASK-00118 Agent identity and administration — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`b4300b8108ff4cffa9499e1f4530d58459d78f6d` against `458a35ac224d8dd6b855c4652db9fad34c327187`.
R-01 is independently resolved; TASK-00118 is done for its complete owned Domain slice, not whole-library or
consumer qualification. Earlier full gate retained: 4,513 tests / 52,873 assertions, exact 8,128/8,128 statements
and 558/558 owned. QA adds 715 fresh driver checks and 121 tests / 1,653 assertions with no findings.
Publication/final-head verification are pending at this closeout checkpoint; current delivery/resource results
and the administrative-only bridge belong to canonical `.runs/handoffs/TASK-00118/land.md` and the actual PR.
Independent reports remain unchanged. Unfinished children keep TICKET-00037/EPIC-00016 open. The following Work
checkpoint is historical, not a current independent-reassessment instruction.

### TASK-00118 historical Work checkpoint

The owned Agent identity/administration Domain slice now rejects coercive decoded fields, validates list/revision
boundaries and explicit administration-Event instants, and exposes exact message/View shapes. Aggregate factories
and successors preserve extending types; Permission revision exhaustion rejects changed membership without altering
no-ops. The [current Agent guide](docs/agent-current-contract.md#precise-identity-and-administration-types-unreleased)
records current caller obligations and unchanged nullable repositories, lazy rename time, correlation, redaction and
Permission/cohort authority. Review R-01 identified an erased iterable gap: aggregate Permission replacement now
validates each member before deduplication/no-op, rejecting wrong-purpose identities even when UUIDs duplicate a
valid member. Regression-first repair evidence awaits independent reassessment; no acceptance is implied.
No optional audit, Common adoption or query-absence redesign is included.

Canonical `.runs/handoffs/TASK-00118/work.md` owns exhaustive member/callback/caller dispositions, regression chronology,
verification and exact candidate/runtime/dependency mapping. Independent Review and nonvisual QA remain required;
Work does not accept or close the TASK, qualify consumer persistence/crypto, publish, release or deploy it.
TASK-00119 retains operation/cohort ownership after the accepted aggregate relocation and Agent Command conversion.

## TASK-00117 integrated QA-01 repair — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover repaired candidate
`52540b7293a0cfd84d8fa03230c6e2ab2cde6520` against `e13995a5b2760d4d02300de2b81797b1d009eb38`.
QA-01 / Review I-01 is resolved by actual strict-diagnostic and affected lifecycle revalidation. The full gate
passes 4,429 tests / 52,484 assertions and exact 8,063/8,063 statements on the final installed lane. Two inherited,
out-of-scope User level-8 precision diagnostics remain; no whole-library level-8 or consumer qualification is claimed.

Land mechanically merges develop `f48e5dc9966c083e0207430954c54f08d9a97cb0` in
`decc2577edb799e23a4c5a701323a41a7b25c543`: only runtime-free fixture cleanup tooling/guidance changes,
with product, tests, fixtures and dependency constraints unchanged from the accepted repair. Independent reports
retain their exact subjects; the bridge and new-input gate results belong to canonical
`.runs/handoffs/TASK-00117/land.md`. TASK-00117 remains done; unfinished children keep its parents open.
Publication/final-head verification are pending at this administrative checkpoint; current delivery/resource
results belong to that Land handoff and [PR #165](https://github.com/johnnickell/fight-access-control/pull/165).
No approval, merge, release or deployment is claimed. The following Work and failure checkpoints are historical,
not current independent-QA instructions.

## TASK-00117 develop integration — historical Work checkpoint

TASK-00117 integrates develop `e13995a5b2760d4d02300de2b81797b1d009eb38`, retaining the accepted
TASK-00115/00116 source alongside its Permission/Role/authority contracts. Conflict reconciliation preserves
all three TASK records and regenerates planning views; no new policy or supplier adoption is introduced.
The original independent reports retain their exact subjects, not a new integrated verdict. Current verification,
content/runtime identity, interaction limits and continuation belong to canonical
`.runs/handoffs/TASK-00117/develop-merge-20261105.md`. Historical completion remains intact; this Work checkpoint
claims no new independent acceptance, publication, merge or consumer qualification.

John subsequently authorized the narrow inherited RefreshSession empty-history correction (QA-01 / Review I-01)
in this continuation. The most-recently-used credential predicate now returns false without diagnostics before
rotation; latest-predecessor selection and the inclusive-start/exclusive-end conflict window remain unchanged.
Regression-first proof and current verification belong to canonical `.runs/handoffs/TASK-00117/qa-01-repair.md`.
Historical TASK completion does not accept this correction: current independent reassessment remains pending,
and the prior QA FAIL remains an unchanged exact-subject report. No unrelated User typing repair is included.

## TASK-00116 develop integration — historical Work checkpoint

The published TASK-00116 branch integrates develop `998e892b4a4a9c1efd5464e76d3ee542c9d24a89`, including
TASK-00115's independently accepted User/session/audit contracts. Both slices' production changes and structural
ownership fingerprints are retained; current exact fixture diagnostics follow develop's narrowed UserView state.
Only overlapping progress prose and generated planning views require conflict resolution. Canonical
`.runs/handoffs/TASK-00116/work.md` owns fresh integrated verification, runtime identity and the exact old/new
base/head/effective-diff bridge. The independent reports below remain unchanged and name their original subjects;
this integration does not create a combined verdict or claim publication, merge, release or consumer qualification.
Land must qualify the bridge before the non-force PR update; canceled cleanup must not resume from old instructions.

## TASK-00116 human credential Domain contracts — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover candidate `080afc43b2b3942625e264547b75cb9b3a74b3bc`
against base `46a15c89fdb33de3e411053e53dfe33a4c60ea0e`, with no findings. TASK-00116 is done for its 68-file
Domain/caller/metadata slice; TICKET-00037 and EPIC-00016 remain open for unfinished children. The qualified earlier
full gate covers 3,480 tests / 51,037 assertions and exact 7,922/7,922 statements; fresh Land focused checks pass
650 tests / 1,003 assertions. No consumer persistence/provider qualification or whole-library acceptance is implied.

[PR #164](https://github.com/johnnickell/fight-access-control/pull/164) is open with accepted implementation at this
checkpoint; administrative closeout publication/final-head verification remain pending. Current delivery/resource
results and the administrative bridge belong to canonical `.runs/handoffs/TASK-00116/land.md` and the actual PR.
Independent reports remain unchanged. No hosted acceptance gate is declared; hosted CI is unchecked, not passed.
No approval, merge, release or deployment is claimed. The Work checkpoint below is historical, not a current
Review/QA action.

### TASK-00116 human credential Domain contracts — historical Work checkpoint

The four grant/delivery Domain families retain their current serialization, nullable repositories and worker/recovery
ownership. Required message fields now reject wrong-type coercion and timestamp payloads require explicit valid
RFC3339 instants. Safe work/status values validate purpose-specific identity, nonnegative revision and applicable
status relationships; due-work OpenAPI metadata agrees. Grant transitions expose their actual subtype with `static`
returns. The [current delivery guide](docs/credential-delivery.md#precise-domain-type-contracts-unreleased) records
consumer adjustments and unchanged boundaries.

The exhaustive declaration/member inventory, actual caller traces, regression-first evidence, static probes and
verification/content/runtime mapping are retained in canonical `.runs/handoffs/TASK-00116/work.md`. This Work
checkpoint claims no independent acceptance, consumer persistence/provider qualification, publication, release or
deployment. Before first independent Review, the advancing-base integration retains TASK-00151's accepted fixture
gate from develop without changing this slice's production contracts. The canonical handoff preserves original
evidence separately from fresh integrated verification. Independent Review and nonvisual QA remain required;
sibling typing/audit tasks retain their ownership.

## TASK-00150 structural contract inventory — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover candidate `73154cf29907711e67e254630465dcb16480da66`.
This accepts the structural tooling foundation only, not production semantics or EPIC-00016 completion;
all initial semantic judgments remain pending. Publication/final delivery verification are pending at this
closeout checkpoint; current results belong to `.runs/handoffs/TASK-00150/land.md` and the actual PR.
The Work checkpoint below is historical, not a current Review/QA prerequisite.

### Work checkpoint

The [declaration/contract inventory](docs/contract-inventory.md) parses all current src/OpenAPI PHP inputs and
routes declarations, members, callbacks and syntactic relationships to existing typing correction owners. Compact
tracked fingerprints enforce structural drift in `bin/quality`; detailed candidate-bound inventories and owner
judgments stay in ignored TASK evidence. Initial semantic dispositions remain pending. This foundation neither
completes EPIC-00016's semantic audit nor qualifies suppliers/consumers. Production behavior, default PHPUnit and
exact statement coverage are unchanged. Independent Review and nonvisual behavioral QA remain required; current
verification/content/runtime identity belongs to `.runs/handoffs/TASK-00150/work.md`.

## TASK-00182 void Agent issuance — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover integrated candidate `4990103d519bdd0a1bbb7d77bccf10619607f4cc`. Publication and final delivery verification were pending at closeout; current results are in `.runs/handoffs/TASK-00182/land.md` and the actual PR. Consumer qualification remains separate. The Work checkpoint below is historical.

### Work checkpoint

Provisioning and rotation now use immutable secret-free `ProvisionAgent` / `RotateAgentCredential` Commands and
registered `ProvisionAgentHandler` / `RotateAgentCredentialHandler`. The handlers own coordination/transactions;
retired direct services, issuance result wrappers and publication warnings have no facade or alias. Independently
authorized `GetAgentOperation` Query dispatch supplies original issuance/dispositions; void dispatch acceptance and
pre-execution/absent status cannot prove commit, rollback, rejection, queue progress, delivery or use authority.

Serialized original key/request and envelope metadata survive delay/restart/redelivery. Actual executing invoker/
explicit delegation and current scope/target/destination/cohort/restoration fences remain independent of payload or
enqueue-time allows. Canonical marker **2** and request bytes, atomic predecessor cancellation/successor/audit,
capacity-isolated original-key resolution, receipts/tombstones and recovery are preserved. Lost acknowledgement
throws safe `AgentOperationUncertainException`; confirmed issuance completes void handling despite both publishers
failing, without returned, durable or reconstructed warnings. Other operations keep their own publication policy.

[Current composition](docs/agent-integration.md), issuance/status/recovery guides and the scenario inventory describe
the breaking unreleased caller contract. Existing shared business/conformance fixtures now route through actual
Common buses; focused serialized execution tests add current worker/delegation and out-of-order predecessor proof.
Work verification and exact runtime/dependency/content identities belong to `.runs/handoffs/TASK-00182/work.md`.
Independent Review/nonvisual QA remain required; this checkpoint claims no acceptance, consumer broker/database/
crypto/activation qualification, publication, release or deployment. The advancing-base reconciliation imports
accepted TASK-00114 from develop: operation values/failures, workers and Queries now live under the separate
`AgentCredentialOperation` aggregate, with independent read/write repository contracts. The Agent Commands/handlers
use those accepted homes without resurrecting direct services/results or duplicating Query models. Fresh integrated
verification and independent Review/QA are required; old-subject acceptance does not cover this candidate.
TASK-00130/TASK-00162 typing/audit enhancements remain separately owned and are not implemented here.

## Approved portable Feature and Permission admission destination

TASK-00180's documentation-only [early evidence assessment](docs/http-permission-admission.md#portable-admission-evidence-assessment-unreleased)
now distinguishes current base Permission support in all five native families from pending portable declarations,
Feature-only HTTP enforcement and combined qualification. Shared evaluator/authority/raw-validation and existing native
lifecycle/error/minimum evidence is reused; genuine gaps name actual supplier owners and smallest recovery inputs.
TASK-00181 retains final candidate-compatible reconciliation. Independent Review ACCEPT and nonvisual QA PASS cover
TASK-00180's assessment candidate `1bee3e3f7e8387f907bc9be8d470d64c85676d33`; TASK-00180 is complete for evidence
accounting only. It implements no new runtime capability, tests or consumer adoption and closes no unfinished parent.

John approved [EPIC-00018 — Portable Feature and Permission Admission](planning/epics/00018-EPIC.md): two independently
selectable HTTP capabilities across Slim, Yii 3, Symfony, Laravel and CodeIgniter 4. Extend FeatureFlag and add
AccessControl-owned portable RequiresPermission, both class/method and repeatable. Effective class, method, inherited
and trusted route requirements accumulate conjunctively; deduplicate identical requirements, reject contradictory
configuration and prevent child/override weakening. Support action attributes and explicit route bindings without
intercepting arbitrary PHP calls. Feature discovery/provisioning must recognize the new declaration locations.

Reuse current FeatureAvailability semantics/fresh reads and trusted User/Agent/anonymous context. OFF/unmet PREVIEW
returns generic 404 without Feature/configuration disclosure; missing/broken definitions and operational faults stay
safe configuration/operational errors. ON passes only the Feature restriction, never action authorization. Preserve
existing Permission refusal/public/authenticated/protected rules. No Feature declaration means no Feature gate, not
public access. Thin optional native adapters keep actual order/request lifetime/safe errors and capability-first
inward dependencies, with real native qualification, full gate/exact coverage and independent Review/behavioral QA.

Grill and the complete four-TICKET requirement split are approved/written, not implemented or released:
[TICKET-00049](planning/tickets/00049-TICKET.md) effective declarations/discovery,
[TICKET-00050](planning/tickets/00050-TICKET.md) five-framework Feature admission,
[TICKET-00051](planning/tickets/00051-TICKET.md) five-framework portable Permission integration and
[TICKET-00052](planning/tickets/00052-TICKET.md) independent/combined conformance/guidance. Shared declarations supply
the two independent capabilities; conformance foundations begin early and final reconciliation consumes accepted
outputs. Each executable slice still owns its callers/tests/docs/full gate/exact coverage/independent acceptance.
TICKET-00049's two-TASK plan is now approved/written as [TASK-00170](planning/tasks/00170-TASK.md) effective Feature
requirements through discovery/current callers (order 501, no demonstrated blocker) and
[TASK-00171](planning/tasks/00171-TASK.md) portable Permission declarations/composition (order 502, blocked only by
TASK-00170's shared pure selected-action/ancestry output). The first owns that mechanism once; the second needs no
Feature evaluation/discovery/repositories or runtime activation. Feature admission can consume TASK-00170 without
waiting for TASK-00171. Preserve native Attribute validation, cumulative inherited/route semantics, actual scoped
complete discovery/current callers and raw PermissionDeclaration rules coherently; no Attribute-only partial contract,
new scanner, redundant route repetition, implicit public fallback or native enforcement is introduced. These are
planning allocations, not source/native/scanner/consumer qualification.

TICKET-00050's complete four-TASK plan is approved/written as [TASK-00172](planning/tasks/00172-TASK.md) shared inward
Feature admission/context and PSR-15 with separate Slim/Yii 3 proof, [TASK-00173](planning/tasks/00173-TASK.md) Symfony,
[TASK-00174](planning/tasks/00174-TASK.md) Laravel and [TASK-00175](planning/tasks/00175-TASK.md) CI4. Orders 503/504/504/504
select no relative native-peer priority. TASK-00172 consumes TASK-00170/completed TASK-00103; Symfony consumes
TASK-00172/completed TASK-00104; Laravel consumes only TASK-00172; CI4 consumes TASK-00172 and TASK-00106's thin
native safe error bridge (independently accepted at the checkpoint below). Common already supplies Laravel's safe handler, not an equivalent CI4 exception owner;
response helpers are insufficient. Source overlap with TASK-00105 is coordination, not a wait or duplicate base
Permission implementation. Reusing suppliers enables no Permission middleware/filter; no TASK-00171/TICKET-00051 wait.
Preserve one shared restriction/context meaning, actual fresh Feature/binding reads, generic 404 only for ordinary
OFF/unmet PREVIEW, real native target/order/construction/lifetime/failure/report/fallback proof and single Agent
authentication. Each native path owns its current docs/minimum/ordinary/isolated composition/full gate/exact coverage/
independent acceptance; final reconciliation is not deferred supplier proof. These are planning allocations covering
five families as 2 + 1 + 1 + 1, not implementation/native/consumer qualification.

TICKET-00051's four-TASK plan is approved/written as [TASK-00176](planning/tasks/00176-TASK.md) PSR-15 portable
Permission integration with separate Slim/Yii journeys, [TASK-00177](planning/tasks/00177-TASK.md) Symfony,
[TASK-00178](planning/tasks/00178-TASK.md) Laravel and [TASK-00179](planning/tasks/00179-TASK.md) CI4. Equal order 505
selects no relative native-peer priority. Each directly consumes TASK-00171 plus respectively completed TASK-00103,
completed TASK-00104, accepted TASK-00105 and accepted TASK-00106. No new shared runtime prefactor/sibling blocker or
TASK-00172–00175/Feature activation wait: effective composition/pure traversal/existing inward admission already have
owners. Unlike Feature-only Laravel, TASK-00178 genuinely consumes TASK-00105's native Permission middleware. Reuse
actual accepted base adapters/error bridge once; preserve earlier scope/orders/blockers. Attribute-only protection,
conjunctive inherited/route requirements, raw declaration validation/duplicate rejection and mode-conflict rejection
must hold without implicit PUBLIC, forged context or new policy. Permission-only needs no Feature reads; cooperating
gates preserve requirements/trusted contexts and one authentication without nonce replay or stale/nested identity.
Each path owns actual native order/form/construction/lifetime/safe errors, current callers/docs/minimum/ordinary/
isolation/full gate/exact coverage/evidence/independent acceptance. These are planning allocations for all five families
as 2 + 1 + 1 + 1, not implementation/native/supplier/consumer qualification.

TICKET-00052's reduced plan is approved/written as two chores: [TASK-00180](planning/tasks/00180-TASK.md) maps existing
supplier evidence/genuine gaps in current guidance (order 499, no blockers, no new tests), and
[TASK-00181](planning/tasks/00181-TASK.md) closes only demonstrated composition gaps and reconciles examples/guidance
(order 506, blocked by TASK-00180 and TASK-00172–00179: nine exact direct edges, declaration/base suppliers inherited).
John rejected the unwritten six-TASK retesting proposal: reuse existing unit/integration evidence, prefer units for
shared logic and add an occasional native integration case only for a specific otherwise-unproved ordering/lifetime/
framework risk. No new per-framework suites, exhaustive test matrix, conformance framework/runner or quota. Zero added
tests is valid when existing evidence suffices; every accepted behavior still needs current candidate-compatible proof.
Normal independent Review/QA/full-gate/exact coverage rules remain, including strictly documentation-only evidence
reuse only with complete input-equivalence/log/receipt/exit proof and targeted checks. Supplier defects return to their
actual owner for authorized repair, not duplicate implementation. All four EPIC-00018 TICKETs are fully TASK-planned as
12 TASKs (2 + 4 + 4 + 2: ten features/two chores), not implemented/qualified; parents remain open with unfinished children.
Existing PSR-15/Symfony/CI4 and accepted TASK-00105 Laravel
Permission work retain scope/order/blockers; assign actual native suppliers and shared adaptations once during
TASK decomposition rather than duplicate or silently expand them. No new version,
consumer adoption or release obligation with other EPICs is selected. TASK-00170's Work implementation now supplies
repeated class/method FeatureFlag, FeatureRequirements and shared pure Action/SelectedAction ancestry/association,
with aligned scoped discovery and preparation/provisioning/retirement tests. TASK-00170 and TASK-00171 now have
independent acceptance for the two metadata capabilities; TASK-00171 adds portable Permission metadata without a
consumer Attribute dependency or native activation. TASK-00172's accepted shared/Slim/Yii Feature admission is described
above; remaining native Feature and portable Permission integrations retain their own TASKs. The canonical handoffs
own verification/runtime details. Metadata alone still activates no runtime gate; Agent OS's class-only Attribute is
not a dependency or alias of the package API.

Selected EPIC-00018 TASK planning is complete. Next: `/skill:next` for Board-based selection, not automatic execution.
All six EPIC-00017 audit TICKETs are now TASK-planned below, not implemented/qualified. EPIC-00015's added Agent
conversion and actual Common supplier planning/qualification remain unfinished. No earlier Wayfinder
phase applies to this standalone destination. At that planning checkpoint the Board selected TASK-00107 first; planning approval did not start execution. Source/dependency/Common/consumer files remain
unchanged; no product build, new checkout, commit/publication, release or deployment follows.

## Fixed actor identities — accepted checkpoint

[TASK-00121](planning/tasks/00121-TASK.md) introduces direct `Audit\SystemId` and `Audit\AnonymousId` values
using supported Common UniqueId contracts. Both generate canonical nil and accept only exact nil strings;
equality/hashing and known-type string reconstruction preserve kind. See [identity guidance](docs/actor-identities.md)
for the fixed-generation exception, explicit provenance, consumer kind preservation and no-authority/no-evidence-ID
limits. Independent Review v3 ACCEPT and nonvisual QA PASS cover candidate
`4ffca636abe6eada9293cd28325c3dd6fa38f85b` against `39691e1b4156c3e98c0d55aaf243f40c0fa4456a`;
TASK-00121 is done. TASK-00122's subsequent acceptance completes TICKET-00043; unfinished family work keeps EPIC-00017 open. Publication/final delivery
verification remain pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00121/land.md`
owns subsequent bridge, checks, publication and resource outcomes. Original independent reports are preserved.
No existing evidence constructor/writer or optional audit wiring changed in that prefactor. The broader destination's
planning/source observations below describe its historical baseline; the shared replacement is now accepted separately.

## Approved explicit optional atomic audit destination

TASK-00154 adds optional last nullable audit storage to custom Role Permission grant/revoke only.
Enabled insertion follows authoritative Role persistence inside the existing transaction, retaining the required
typed actor, related Permission, exact Role incarnation/revisions, trusted transition time and actual message ID.
No-change keeps validation/fences without evidence; audit failure rolls back staged state/evidence and ordinary
uncertain/post-commit failure truth remains. See [composition](docs/role-membership-contracts.md#optional-role-permission-audit-composition).
Other administration slices remain separately owned. Independent Review v3 ACCEPT and nonvisual QA PASS cover
candidate `b0291f06d79dd9e2db1c9233a0a2b07d60426d22` (nine QA groups / 145 checks). TASK-00154 is done; unfinished
siblings keep TICKET-00045/EPIC-00017 open. Earlier full gate retained: 9,368 tests / 93,163 assertions and exact
10,841/10,841 statements and Unit-only executable lines. This corrects the earlier Work/TASK assertion count of
93,209 without changing original logs/reports. Final closeout publication/resource checks remain pending at this
checkpoint; canonical `.runs/handoffs/TASK-00154/land.md` owns delivery and retirement outcomes. No consumer database
qualification, workaround removal, merge or release is claimed here.

TASK-00167's [test-only conformance](docs/audit-transaction-conformance.md) now exercises the existing enabled
EnableUserHandler with ordinary shared transactions, reopened modeled User/evidence bytes, audit rollback,
concurrency loss, both commit-uncertainty outcomes, publication failure and two-active-composition wrong-connection
controls. Independent Review v3 ACCEPT and nonvisual QA PASS cover `c14704b259a48e9287b43bcb9d4ba5775c40531d`
(22 scenarios / 510 QA checks). Land's behavior-preserving integration of develop
`12aeb8464230892ff5eea04da037ca079fb8016c` adapts the supplied audit repository to the enabled writer's new final
constructor argument and passes a fresh full gate: 8,839 tests / 89,522 assertions, exact 10,551/10,551 statements.
TASK-00167 is done; unfinished children keep TICKET-00048/EPIC-00017 open. Publication/final verification remain
pending at this administrative checkpoint; canonical `.runs/handoffs/TASK-00167/land.md` owns subsequent bridge,
delivery and resource outcomes. Original independent reports and earlier Work/Review/QA counts remain distinct.
No production API, family optionality, real SQL/process/consumer qualification or workaround retirement is supplied.

TASK-00122 supplies the [typed evidence replacement](docs/audit-evidence.md): the old string/context model
and necessary callers/fixtures/schemas are replaced across all 22 current writer owners. Its TASK and canonical
Work handoff own implementation evidence. Independent Review ACCEPT and behavioral QA PASS accept
`5d8ba4496a19a1b1e33e9e16283d1aac6a64ddb4`; TASK-00122 and TICKET-00043 are done. Land's behavior-preserving
integration of current develop passed a fresh full gate (8247 tests / 81407 assertions, exact global/owned coverage).
Canonical `.runs/handoffs/TASK-00122/land.md` owns subsequent delivery/resource results, separate from human merge. TASK-00123 now implements optional last
nullable storage for the eight account lifecycle handlers. TASK-00124 now implements optional final storage for
all four invitation delivery/resend/retry/expiry handlers and typed expiry Command/Event provenance; see
[invitation composition](docs/credential-delivery.md#optional-invitation-audit-composition-unreleased). Expiry adds
transaction-local typed outcome evidence only for committed material expiry, preserving activation authority,
origin, admitted time and expected-state semantics. Claims/external sends remain separate; audit rollback does
not undo those effects, and ordinary post-commit failure preserves committed evidence. Work verification and
independent acceptance are recorded separately in TASK-00124 and its canonical handoffs. TASK-00125's Work slice
adds optional final storage for password-reset request/delivery/expiry and typed expiry provenance. Enabled expiry
records the exact material transition inside its existing transaction; public requests deliberately establish
AnonymousId and preserve concealed no-work. Claims/external sends, recovery authority, uncertainty and ordinary
publication failures remain distinct. See [reset composition](docs/credential-delivery.md#optional-password-reset-audit-composition-unreleased)
and its [outcome matrix](docs/audit-outcome-coverage.md#password-reset--task-00125). Synchronous password operations
remain TASK-00127. Independent acceptance is not claimed by this Work checkpoint.
TASK-00126 now implements optional final storage for email-change request/cancel/delivery/expiry,
self-service plus administrative request/cancel evidence and typed expiry provenance. See
[email-change composition](docs/credential-delivery.md#optional-email-change-audit-composition-unreleased).
Expiry evidence joins the coupled User reservation/grant transaction at the admitted occurrence time, preserving
inactive-state cleanup and no-change/CAS semantics. Claims/external sends, uncertainty and ordinary post-commit
failure remain separate. Synchronous confirmation optional auditing belongs solely to TASK-00127; other family
constructors and currently unaudited paths remain separately owned. Independent Review ACCEPT and complete
nonvisual QA PASS cover TASK-00126 candidate `1a2b3a4b5d6451843ba5562f4d3f36dc276c8348` (12 scenarios /
421 checks). Its TASK records package acceptance and retained full-gate evidence; canonical
`.runs/handoffs/TASK-00126/land.md` owns subsequent delivery/retirement outcomes. Real consumer qualification
remains separate. See [current account composition](docs/user-lifecycle-contracts.md#optional-account-audit-composition).
TASK-00153 now adds optional last nullable storage to custom Role creation, rename and removal. Explicit evidence
follows authoritative persistence inside each ordinary transaction, preserving direct required UserId attribution,
complete actual Role incarnation/revisions, outcome time and incoming message correlation. Validated same-name
rename adds none; all caller/CAS/name/reference/registration guards and ordinary uncertainty/publication failures
remain. See [Role composition](docs/role-revisions.md#optional-lifecycle-audit-unreleased) and the current
[outcome rows](docs/audit-outcome-coverage.md#administration-handlers-11). Independent Review ACCEPT and nonvisual
QA PASS cover candidate `47cbc012dfcb0e2d5b2944e6240849ce4b542da7` (35 scenarios / 295 checks). Its TASK records
package acceptance; canonical `.runs/handoffs/TASK-00153/land.md` owns integration, verification, publication and
retirement outcomes, pending at this closeout checkpoint. Real consumer mapping/connection qualification and
workaround retirement remain separate.
Omitted/null disables durable evidence without changing business-required typed actors, target/default-Role/CAS
admission or coupled persistence. Enabled evidence retains the actual mutation transaction, trusted time,
authoritative revisions/grant/delivery/message correlation and ordinary User publication-failure propagation.
Independent Review ACCEPT and complete nonvisual QA PASS cover TASK-00123 candidate
`a5e5934ea83a420674a27f9dd1217658402a5732`; TASK-00123 is done, with unfinished siblings keeping its parents open.
Canonical `.runs/handoffs/TASK-00123/land.md` owns the integration bridge and subsequent verification, publication
and resource outcomes, pending at this closeout checkpoint. Real consumer connection qualification remains separate. The destination and historical
planning observations below do not claim complete optional auditing.

John approved [EPIC-00017](planning/epics/00017-EPIC.md): explicit audit recording in all 37 current CommandHandlers
and related synchronous account/credential/delivery/maintenance use cases. Evolve AuditEvidence and its existing
repository; use an optional last constructor dependency everywhere, including previously mandatory audit writers.
Omitted/null intentionally disables durable audit. When supplied, evidence and mutation share the actual transaction/
connection and audit failure aborts pre-commit work; uncertainty and post-commit publication/external effects retain
existing truthful outcomes. This visibly amends earlier mandatory-audit target guidance, not released source claims.

Use typed actorId values directly. SystemId and AnonymousId are distinct fixed-nil UniqueId special cases: only
Uuid::NIL is valid and generate() returns nil. Actorless Commands gain explicit optional provenance, required before
the first audited mutation, not a pure no-change pass. Automated Feature provisioning supplies SystemId explicitly;
no transport/ambient actor resolver, account or authorization grant is introduced. Evidence has typed subjects
including Roles, unique record identity, trusted occurrence time, applicable revisions/relationships/correlation and
bounded safe context. Preserve no-change/same-key resolution without new mutation evidence; batches audit changed
subjects, not incidental repository writes. No guaranteed rejected-attempt store, outbox or replay framework.

Consumers own persistence/schema/shared-connection qualification. Agent OS can remove its RoleMutationUnitOfWork/
RoleMutationAudit/PostgresRoleMutationAudit workaround after adopting and qualifying the improved package contract.
EPIC-00015 retains architecture/Agent command conversion; EPIC-00016 retains typing, without duplicate audit feature
ownership or existing TASK expansion. This approved target is not implemented or released; source, dependencies and
consumer repositories are unchanged. John approved the complete six-TICKET split, now written as
[TICKET-00043](planning/tickets/00043-TICKET.md) through [TICKET-00048](planning/tickets/00048-TICKET.md): typed evidence/
attribution; User account/credential journeys (20 handlers plus synchronous operations); Role/membership/Agent
administration (11); Feature/managed policy (six); synchronous Agent credential/recovery outcomes; and composition/
coverage/adoption guidance. The families account for all 37 current handlers, not executed verification. Shared
contracts supply family work; inventory/conformance foundations start early and each slice keeps its own tests,
docs/gate and independent acceptance. John approved TICKET-00043's complete two-TASK plan, now written as
[TASK-00121](planning/tasks/00121-TASK.md) (fixed system/anonymous identities, order 401, no demonstrated blocker) and
[TASK-00122](planning/tasks/00122-TASK.md) (shared evidence replacement across 21 existing writer owners and necessary
callers, order 402, blocked by TASK-00121). Actual optional wiring, family admission and expanded outcomes remain
with TICKET-00044–00047; shared replacement is not a claim that all 37 handlers become optional/audited. No source
or product qualification follows from this planning manifest. John also approved TICKET-00044's complete five-TASK
plan, now written as [TASK-00123](planning/tasks/00123-TASK.md) through [TASK-00127](planning/tasks/00127-TASK.md):
account lifecycle (eight handlers), invitations (four), password-reset work (three), email-change work (four) and
synchronous User security/session operations (one handler plus all seven synchronous methods). The last owns
synchronous wiring once, following actual narrower capabilities; it preserves committed terminal-replay revocation
evidence despite outward refusal, while benign conflict/no-change gets none. Orders 403–407 each consume only
TASK-00122 directly, with no demonstrated mutual blocker. This is planning coverage, not behavioral qualification.
John approved TICKET-00045's complete five-TASK plan, now written as
[TASK-00153](planning/tasks/00153-TASK.md) custom Role lifecycle, [TASK-00154](planning/tasks/00154-TASK.md) Role
Permissions, [TASK-00155](planning/tasks/00155-TASK.md) User Role membership,
[TASK-00156](planning/tasks/00156-TASK.md) Agent names and [TASK-00157](planning/tasks/00157-TASK.md) direct Agent
Permissions/coordinator ownership. Orders 408–412 are ready-for-agent, each blocked only by TASK-00122 with no
mutual wait demonstrated. Allocation is 3 + 2 + 2 + 1 + 3 = 11/11 handlers, plus the coordinator solely in TASK-00157;
these counts are planning coverage, not source audit or transaction/consumer qualification. Reuse existing required
typed actors, optional last nullable audit injection and explicit handler evidence within the actual mutation
transaction. Preserve actual Role/assignment revisions and rename time without invented revisions/stale snapshots;
reconcile helper commits, not returned-Event inspection or duplicate evidence. Every slice retains its own current
callers/docs, focused proof/full gate/exact coverage/independent acceptance. UoW mixed/local @var remains sufficient.

John approved TICKET-00046's complete four-TASK plan, now written as
[TASK-00158](planning/tasks/00158-TASK.md) Feature creation/retirement,
[TASK-00159](planning/tasks/00159-TASK.md) Feature settings,
[TASK-00160](planning/tasks/00160-TASK.md) code-defined provisioning and
[TASK-00161](planning/tasks/00161-TASK.md) managed-policy reconciliation. Orders 413–416 each consume only TASK-00122
directly, without demonstrated mutual waits. Allocation is 2 + 2 + 1 + 1 = 6/6 handlers, with the read-only planner a
TASK-00161 collaborator, not another handler/audit writer. These are planning counts, not qualification. Each slice
owns explicit optional typed provenance/last nullable repository, trusted outcome time and same-transaction evidence,
necessary messages/callers/schemas/docs, full gate/exact coverage and independent acceptance. Provisioning supplies
SystemId explicitly once per autonomous Command; no-change needs no audit-only actor. Reconciliation only changes
Roles/Permissions; Feature references are guards, not invented Feature changes. Keep actual revisions/correlation,
reference/tier fences, batch rollback/uncertainty and ordinary publication semantics; no generic UoW wait is added.

John approved TICKET-00047's complete four-TASK plan, now written as
[TASK-00162](planning/tasks/00162-TASK.md) paired recoverable issuance/retained origin,
[TASK-00163](planning/tasks/00163-TASK.md) credential revocation,
[TASK-00164](planning/tasks/00164-TASK.md) delivery/scheduler recovery and
[TASK-00165](planning/tasks/00165-TASK.md) material maintenance. Orders 417–420 each consume TASK-00122 directly;
TASK-00164 also waits on TASK-00162's real retained-origin data. No mutual barrier or issuance prerequisite for
independent revocation/maintenance is demonstrated. Allocation is six primary services/eight outcome entry points,
not new handlers, completed source audit or product/consumer qualification. nextRunAt stays pure scheduling.
Preserve original provenance without changing canonical key/request/associated-data/public issuance, separate real
worker authorization, claim-path expiry/exhaustion evidence, receipt-first restart and exact actual outcome transactions.
Rewrap remains inside a bounded fenced transaction; cleanup external effects remain between admission/acknowledgement.
Audit failure cannot reverse a sink effect. Only issuance retains safe confirmed-result warnings; no generic UoW or
Command-conversion dependency is added. Every slice retains its own callers/docs/gate/exact coverage/independent acceptance.

John approved TICKET-00048's complete four-TASK plan, now written as
[TASK-00166](planning/tasks/00166-TASK.md) independent outcome inventory/design,
[TASK-00167](planning/tasks/00167-TASK.md) reusable audit transaction conformance,
[TASK-00168](planning/tasks/00168-TASK.md) ordinary Role composition/adoption and
[TASK-00169](planning/tasks/00169-TASK.md) final integrated qualification/current guidance. Orders 399/400 favor early
foundations; 421/422 prefer composition/final reconciliation. Inventory has no blocker; conformance consumes TASK-00122;
Role composition consumes TASK-00167 and TASK-00153; final work has 21 direct output/evidence suppliers: all three
plus the 18 families TASK-00123–00127 and TASK-00153–00165. Earlier scopes/orders/blockers remain unchanged; no family
waits on these foundations to start its own proof. Inventory accounts for 37 + 7 + 8 entry points, not 52 writers or
source/runtime/consumer qualification. Reopened modeled persistence/negative controls must prove more than active
flags, and real consumer storage/connections/process/sinks/restoration remain separate. No workaround removal follows.

All six audit TICKETs are fully TASK-planned as 24 chores (2 + 5 + 5 + 4 + 4 + 4), not implemented/qualified; their
unfinished children keep parents open. TICKET-00049's subsequent plan is written above as TASK-00170/TASK-00171,
not implemented/qualified. TICKET-00050's subsequent four-TASK Feature plan is also written above, not native proof.
TICKET-00051's subsequent four-TASK portable Permission plan is also written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning as
12 TASKs, not implementation/qualification or a second testing programme. Next: `/skill:next` for Board-based selection.
EPIC-00016's six accepted TICKET plans below are complete, not implemented/qualified; added EPIC-00015 Agent conversion and actual
Common suppliers remain separate unfinished planning/qualification handoffs. Existing scope/order/blockers and first
Board selection remain unchanged. No source/dependency/consumer mutation, product build or implementation follows.

## TASK-00117 Permission, Role and authority typing — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover candidate `191b972258acfa4ca0bb7e8c90ded115a26f2fcc`
against `46a15c89fdb33de3e411053e53dfe33a4c60ea0e`. This accepts the complete owned Domain correction slice,
not whole-library typing or actual consumer qualification. TASK-00117 is done; its parents remain open for
unfinished children. Publication/final delivery verification are pending at this closeout checkpoint; current
results belong to canonical `.runs/handoffs/TASK-00117/land.md` and the actual PR. The Work checkpoint below
is historical, not a current Review/QA prerequisite.

### Work checkpoint

The complete Permission/Role/ManagedPolicy/Authorization Domain slice has been inspected, with an exhaustive
member/callback inventory and caller/disposition evidence retained in the canonical TASK Work handoff. Current
corrections validate decoded message/policy values without coercion, describe exact policy-plan/Event payloads,
project Role membership as typed lists and enforce complete canonical Role names. Valid serialization, nullable
repositories, revisions, tier/reserved identity and reference/authority fences retain their existing semantics.
Principal snapshot arrays remain genuinely dynamic validated inputs, not live authority. See
[Role input types](docs/role-revisions.md#serialized-input-types-unreleased),
[policy input types](docs/operation-outcomes.md#serialized-policy-types-unreleased) and
[type-contract proof](docs/type-contracts.md). No Common adoption, new policy, compatibility reader or consumer
qualification is included. Independent Review and behavioral QA remain pending; Work does not mark TASK-00117 done.

## TASK-00115 User/session/audit typing — accepted checkpoint

Independent Review v3 ACCEPT and nonvisual QA PASS cover repaired integrated candidate
`e0853b518997713493fb1473d2ea0f6878479e68` against `46a15c89fdb33de3e411053e53dfe33a4c60ea0e`.
L-01 is resolved; TASK-00115 is done, while TICKET-00037/EPIC-00016 remain open for unfinished children.
The complete gate passed with 3,756 tests / 51,540 assertions and exact global/owned statement coverage.
QA executed eight affected groups / 99 checks freshly and qualified fourteen unchanged production groups /
759 original checks for reuse, not fresh execution. Publication/final delivery verification are pending at this
closeout checkpoint; current delivery/resource results belong to canonical `.runs/handoffs/TASK-00115/land.md`
and the actual PR. No consumer qualification, merge, release or deployment is implied. The Engineer and repair
checkpoints below are historical; independent canonical reports preserve their exact subjects.

## TASK-00115 User/session/audit typing — Engineer checkpoint

The Engineer candidate audits the complete current 67-file User/RefreshSession/Audit Domain boundary, with a
candidate-specific declaration/member/contract/caller inventory: 37 corrected declarations and 30 justified
unchanged dispositions, without Domain additions, moves or removals. All 32 owned messages retain valid current
outputs while rejecting coercive type mismatches; typed Role/context collections, current hydration, counter limits
and exact refresh-credential shape have executable regressions. The User test repository preserves native subsecond
instants in expected-state comparisons, including rejected coupled session insertion. See the
[current type-contract guide](docs/user-session-type-contracts.md).

Existing nullable repository lookups, direct synchronous User capability homes, CAS/coupled persistence, required
audit, recovery and secret handling remain. No Common adoption, optional-audit design or query absence change is
included. Exact candidate, regression/focused/static results and final gate/coverage evidence belong to the canonical
TASK-00115 Work handoff. The pre-Review candidate integrates pinned develop `47f1e1a8d61ba87a91ac4055a376332a5f4ae1ee`,
preserving TASK-00150's accepted structural foundation. Owned fingerprints and candidate evidence are reconciled;
changed Composer/quality inputs require fresh integrated verification, not reuse of the earlier gate as current proof.
Independent technical Review and behavioral QA remain pending; this checkpoint does not
self-accept or close the TASK, qualify consumer persistence/crypto, publish, release or deploy anything.

### Integrated fixture repair

The prior TASK-00115 candidate received independent Review ACCEPT and behavioral QA PASS. Integration of pinned
develop `46a15c89fdb33de3e411053e53dfe33a4c60ea0e` then exposed stale UserView shape messages in the required
fixture manifest. The repair aligns only those two expectations with the precise four-value UserState output;
production/test bytes and exact diagnostic enforcement remain unchanged. Original failure and current verification,
inventory equivalence and newly prepared runtime identity are retained in the canonical TASK-00115 Work handoff.
Independent reassessment/applicable QA remain required for the repaired integrated subject; the earlier checkpoint
and verdicts are historical, not acceptance of this repair. TASK-00115 remains in-progress.

## TASK-00151 type-contract foundation — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover `c7b6900ecf3ee376ba152d84eddac0170f1b335d` against
`2993293d02c1679a53b4741cf7732eaa7dc65370`. Publication/final delivery verification are pending at this closeout;
current delivery/resource results are in canonical `.runs/handoffs/TASK-00151/land.md` and the actual PR.
TASK-00151 is done; its parents remain open. This accepts the bounded fixture foundation, not whole-library,
dispatch/Event supplier, minimum-lane or consumer qualification. The Work checkpoint below is historical.

### Work checkpoint

The [type-contract fixture guide](docs/type-contracts.md) describes the required `bin/quality` check and focused
`./bin/type-contracts` command. Two cases check six exact positive types and six case-bound rejections against current
identity/list/shape/nullable/callback contracts. The runner validates actual exits and complete diagnostic identities;
its failure-path qualification is separate tooling, outside product PHPUnit/coverage. Ordinary source/caller analysis
and exact production coverage remain unchanged. Work evidence belongs in `.runs/handoffs/TASK-00151/work.md`.
Independent Review and nonvisual QA remain required; this checkpoint claims neither acceptance nor a whole-library
audit, dispatch/Event supplier qualification, business repair, publication or release. TASK-00150/00152 and the
semantic owners retain their inventory, sufficient enforcement and real composition obligations. Native UoW mixed
with a matching known-local callback `@var` remains sufficient.

## Approved library-wide typing and failure-contract destination

John approved [EPIC-00016](planning/epics/00016-EPIC.md) to audit and correct typing throughout the entire library,
including every command/query handler, synchronous service and supporting Domain/Application/Adapter contract.
Required queries return their expected value or an intentional Application failure; repository lookups remain
aggregate-or-null. Optional absence/status is explicitly modeled, and internal identity/result violations remain
server faults rather than not-found outcomes. Common owns upstream bus typing/result invariants; actual qualified
and tagged APIs/minimum evidence gate affected adoption, without sibling or consumer mutation. Preserve inward
layering, safe HTTP/protocol boundaries, security, atomicity, recovery and post-commit outcomes. Coordinate with
EPIC-00015/TASK-00110 rather than duplicating canonical import adoption. This is approved planning, not a completed
audit or source/dependency/release change. John approved the complete six-TICKET split, now written as
[TICKET-00037](planning/tickets/00037-TICKET.md) through [TICKET-00042](planning/tickets/00042-TICKET.md): Domain/
repository contracts; typed dispatch; exact query results/absence; commands and synchronous Application contracts;
presentation/integration; exhaustive inventory/enforcement/final reconciliation. Start inventory design early;
each correction slice still owns its tests and independent acceptance. John approved TICKET-00037's complete
six-TASK plan, now written as [TASK-00115](planning/tasks/00115-TASK.md) through
[TASK-00120](planning/tasks/00120-TASK.md), accounting for all 291 current Domain files across User/session/audit,
human credentials/delivery, Permission/Role/authority, Agent identity/admin, Agent operation/recovery and Feature.
Orders 301–306 follow existing approved work with no demonstrated local/mutual blockers; each slice starts its
owned inventory without waiting for later tooling. This is planning coverage, not an audit result or execution.
John also approved TICKET-00038's complete three-TASK plan, now written as
[TASK-00128](planning/tasks/00128-TASK.md) (22 Query dispatch paths), [TASK-00129](planning/tasks/00129-TASK.md)
(37 existing void Command paths) and [TASK-00130](planning/tasks/00130-TASK.md) (amended void Agent Command and outcome
Query typing/qualification, not conversion). Orders 307–309 are unchanged. All retain actual qualified/tagged generic
API/minimum evidence holds; TASK-00130 now has the genuine TASK-00182 conversion edge. No result-capable CommandBus
is required, and functional conversion does not depend on this downstream typing proof. Current void paths and
independent Query adoption retain their owners. Scope manifests establish planning
coverage only, not executed bus/type/consumer proof. TICKET-00039/00040 retain semantics, TASK-00110 retains canonical
migration and TICKET-00042 retains final reconciliation; each slice keeps its own gate and independent acceptance.

John also approved TICKET-00039's five-TASK plan, now written as
[TASK-00131](planning/tasks/00131-TASK.md) (six required-resource queries), [TASK-00132](planning/tasks/00132-TASK.md)
(six paged queries), [TASK-00133](planning/tasks/00133-TASK.md) (four human credential status/discovery queries),
[TASK-00134](planning/tasks/00134-TASK.md) (four Agent operation/scheduler/count queries) and
[TASK-00135](planning/tasks/00135-TASK.md) (two preparation/policy-preview queries). The manifests cover all 22 current
handlers without overlap/omission, not executed type/runtime/consumer qualification. Orders 310–314 follow existing
typing plans with no demonstrated local/mutual blockers. At that planning checkpoint four slices were ready-for-agent;
current status belongs to the owning TASKs and Board. TASK-00132 remains needs-info for Common ResultSet validation
NOT found in current local Common plans. Common's done strict Pagination task
validates request bounds, not returned-page metadata. A separate bounded Common plan under its 1.x policy and actual
accepted/tagged/minimum composition evidence are required; no sibling file or fictitious blocker is created here.
The other slices still verify supported tagged APIs/minimum/ordinary lanes, and keep their own gate/independent acceptance.

John approved TICKET-00040's revised complete ten-TASK plan, now written as
[TASK-00136](planning/tasks/00136-TASK.md) through [TASK-00145](planning/tasks/00145-TASK.md): Event-driven delivery/
Clock, User lifecycle, human credential workers, synchronous User/session, Role/membership, Agent administration,
Feature/policy, Agent issuance/results, Agent workers and authority/continuation. Primary manifests allocate 119
non-query/non-presentation Application files and all 37 CommandHandlers without overlap/omission. With 22 QueryHandler
and 13 Application presentation files, all 154 baseline Application files have planning ownership, not completed
source review or executed type/runtime/supplier/consumer proof. Orders 315–324 preserve earlier scope/priority with
empty local blocked_by and no demonstrated mutual blockers. At that planning checkpoint nine slices were
ready-for-agent; current status belongs to the owning TASKs and Board. TASK-00136 was needs-info for Event typing
at that checkpoint. During its later explicit Build invocation John accepted static Event class-to-method
registration through Common's real eventRegistration() and correct local payload `@var` extraction in EventMessage
handlers. This scoped amendment supersedes the generic Event supplier hold, not Query/Command generic adoption.
Actual mapping/annotation, static limitations, tagged minimum/ordinary composition and preserved delivery/failure
behavior still require proof; no upstream generic Event inference or native generic enforcement is claimed.

John explicitly removed the UoW supplier hold. Native commitTransactional() remains mixed; a matching local @var
for the known owned callback result is sufficient, including known tuple/union branches. No generic Common UoW
API, qualification task, replacement wrapper/result DTO or redundant runtime check is needed. This settled local
annotation policy is not validation of untrusted external data or proof of bus/Event payload inference. Preserve
actual authority, secret safety, audit atomicity, uncertainty and deliberate provider/pre-admission containment;
shared planner/resolver/caller edits have one owner and every slice retains its own gate/independent acceptance.

John approved TICKET-00041's complete four-TASK plan, now written as
[TASK-00146](planning/tasks/00146-TASK.md) (ordinary HTTP), [TASK-00147](planning/tasks/00147-TASK.md) (profile Tools),
[TASK-00148](planning/tasks/00148-TASK.md) (Resource/Skill) and [TASK-00149](planning/tasks/00149-TASK.md) (public payload/
declarative metadata). At that planning checkpoint orders 325–328 were ready-for-agent and TASK-00146/00148 had no
local blocker; current status belongs to the owning TASK records and Board.
TASK-00147 waits on TASK-00128/00131/00141 and TASK-00149 consumes TASK-00133's now-accepted status representation.
Primary manifests allocate all 23 current
files (2 Adapter, 13 Application presentation, 8 OpenAPI) without overlap/omission, not completed source audit or
runtime/type/supplier/consumer qualification. Preserve protocol distinctions, actual tagged/minimum/ordinary proof,
current examples and directly necessary earlier schema/caller fixes; every slice keeps its gate/independent acceptance.
Known local callback @var with UoW mixed remains sufficient. Unchanged schema wire shapes neither qualify Common
ResultSet runtime invariants nor inherit that hold as a blanket metadata blocker. Actual relocations/adoption and
unfinished native/MCP features retain their owners without duplicate implementation or an all-framework wait.

John approved TICKET-00042's complete three-TASK plan, now written as
[TASK-00150](planning/tasks/00150-TASK.md) (declaration/contract ownership, order 299),
[TASK-00151](planning/tasks/00151-TASK.md) (type-contract fixture gate, order 300) and
[TASK-00152](planning/tasks/00152-TASK.md) (integrated enforcement/qualification, order 329). All are ready-for-agent.
Both foundations have no demonstrated blockers and are independent; their new early priorities precede 301–328
without renumbering existing work or adding prerequisites to earlier correction slices. Final TASK-00152 waits on
30 actual local suppliers: both foundations and all 28 correction/adoption TASKs (00115–00120 and 00128–00149).
This is final-completion fan-in only. Real Query/Command/Event/ResultSet and future conversion holds remain inherited
through those edges, without an invented Common/architecture/conversion supplier or blanket early wait.

The inventory accounts for declarations/members/callables and actual contract boundaries, not fixed file counts or
completed semantic audit. Fixture machinery checks positive precision and expected-negative diagnostics/exits;
dispatch/Event owners keep their actual fixture semantics and acceptance. Required checks use bin/quality; runner
qualification is separate tooling, not product coverage/default PHPUnit. Final work completes sufficient static
rules/integrated checks and reconciles every current contract/disposition/evidence identity, not merely a report.
New source defects return to their owners and all slices retain full gates/exact coverage/independent acceptance.
Native UoW mixed plus matching known-local-callback @var remains sufficient, with no generic supplier or redundant check.

All six EPIC-00016 TICKETs now have approved TASK plans (31 TASKs), not implementation, source audit or qualification.
All six audit TICKETs are now TASK-planned above as 24 chores, not implemented/qualified.
TICKET-00049's two-TASK declaration plan is now approved/written above, not implemented/qualified.
TICKET-00050's four-TASK Feature admission plan is now written above, not implemented/qualified.
TICKET-00051's subsequent four-TASK portable Permission plan is written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced two-chore plan is now written, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. John's subsequent five-framework Feature/portable
Permission admission interview is approved/written as EPIC-00018 above. Current FeatureAvailability remains the evaluator;
FeatureFlag metadata still is not enforcement and no Feature middleware exists. EPIC-00018's four-TICKET split is
now approved/written as TICKET-00049–00052, all four fully TASK-planned (12 TASKs), not implemented/qualified.
This needs no further interview/TICKET split or expansion of audit/existing Permission TASKs.
EPIC-00015's added Agent conversion is now fully planned as [TICKET-00053](planning/tickets/00053-TICKET.md)/
[TASK-00182](planning/tasks/00182-TASK.md). That planning-only checkpoint did not implement it; the current Work
checkpoint above records its implementation and subsequent independent acceptance. John superseded result-bearing synchronous dispatch
with transport-neutral void Commands and outcome Queries; the result-capable Common hold is removed. Distinct actual
Common generic/canonical qualification remains with its owners. At the earlier planning checkpoint TASK-00107 was
first; it has since been independently accepted and the current Board owns execution selection.
No source/dependency/sibling/consumer mutation, product build, new checkout, commit/publication or release follows.

## TASK-00112 direct synchronous User capabilities — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover the implemented candidate. Publication and final delivery verification are pending at this checkpoint; current results belong to the canonical TASK-00112 Land handoff and actual PR. The Work checkpoint below is historical; consumer qualification remains separate.

### Work checkpoint

Secret-bearing User operations now have direct Application owners: `User\\Authentication\\UserAuthentication`
for activation/login, `RefreshSession\\SessionLifecycle` for refresh/logout, `User\\PasswordManagement` for change/reset
and `EmailChangeGrant\\EmailChangeConfirmation` for confirmation. `User\\Authentication\\TokenIssuer` shares token and
session lifetime creation without persistence, transaction or authorization ownership. The retired broad
AuthenticationService is removed without a facade or alias. Current constructors, before/after callers and failure
contracts are in [synchronous composition](docs/user-authentication.md).

The redesign preserves TASK-00111's complete User transitions, existing secret safety, Common ports, repository
CAS/coupled insertion, recovery/session/audit fences and pre-/uncertain-/post-commit outcomes. It does not implement
future optional audit, Common adoption, new grants or consumer infrastructure. Canonical Work handoff/logs own the
verification and tested-content mapping. Independent Review and nonvisual behavioral QA remain required; Work does
not self-close TASK-00112 or claim consumer database/crypto qualification, publication, release or deployment.

## User authentication transitions — TASK-00111 Work checkpoint

User activation, password change/reset and email confirmation now construct complete successors with one owned
`authenticationAuthorityRevision` advancement. `recordSuccessfulLogin()` records the required login serialization
with an optional verified rehash. It preserves `authenticationVersion` and recovery authority; no-rehash login keeps
existing password-update metadata. Standalone revision increment/rehash APIs are removed with direct caller updates.
Current [composition](docs/operation-outcomes.md#user-authentication-transitions-unreleased) retains repository CAS,
coupled session insertion, recovery/grant/session/audit transaction fences and existing publication outcomes.

This is TASK-00111's approved design change, not evidence of a previous persistence bypass. At that Work checkpoint,
AuthenticationService remained the synchronous use-case owner; TASK-00112 now supplies the direct owners above. Work verification and subject mapping
belong in the canonical TASK handoff. Independent Review/nonvisual QA remain required; no consumer database/crypto
qualification, release or deployment is claimed at this checkpoint.

## TASK-00114 operation aggregate/read ownership — accepted checkpoint

Current integrated candidate `f3794cc96872ee92eb23f67dc9773bf33e2c32ec` has independent Review ACCEPT and nonvisual QA PASS against `766442fe501a9c86f64b9eb1d1a825cf5b537b62`. Publication and final delivery verification are pending at this checkpoint; current results are in canonical `.runs/handoffs/TASK-00114/land.md` and PR #158. Consumer qualification remains separate. The original and Work checkpoints below are historical.

Independent Review ACCEPT and nonvisual QA PASS cover original candidate `2cca7db1c19c31bd022a9075e30931d0cc967e18`
against `4d677c1ee0d24543dc9909dbba907dd3298f8797`. Its unpublished administrative closeout is preserved. Develop
then advanced through TASK-00112's direct synchronous User capabilities. Current integration retains both scopes
and requires fresh independent Review/QA; the original verdicts do not qualify that combined subject. Exact Work
verification and subject mapping are in canonical `.runs/handoffs/TASK-00114/work.md`; delivery history is in
`land.md` and PR #158. No integrated acceptance, merge, release, consumer qualification or deployment is claimed.
The Work checkpoint below records the original stage, not a new verdict or current runtime instruction.

### Work checkpoint

`AgentCredentialOperation` now has a distinct aggregate home inside AccessControl, with aggregate-named write
`AgentCredentialOperationRepository` and independent `AgentCredentialOperationReadRepository`. The four read handlers
receive only safe status, bounded due/maintenance selection, global key-reference inventory and cohort observation;
no root/material lookup, reservation, write or UoW capability is exposed. One consumer persistence adapter may
implement both contracts on the existing shared connection. The [current ownership/composition map](docs/agent-integration.md#operation-ownership-and-readwrite-composition-unreleased)
classifies the moved Domain values/messages/evidence and Application authorization/worker/port/result responsibilities.

Operation Delivery/Maintenance/query owners and mirrored tests now follow that aggregate. Agent identity/name,
authentication and issuance/lifecycle owners remain Agent responsibilities. `AgentOperationView::fromOperation()`
owns projection of already loaded snapshots; the root exposes recorded dispositions, not `getStatus()` construction.
Real read adapters project metadata directly without loading secrets. PHP imports/registrations/named arguments
change directly without aliases; safe wire/OpenAPI shapes and canonical marker 2 remain unchanged.

Original-key/restart/uncertain outcomes, scope/target/destination checks, atomic Agent retirement/operation cancellation
and audit, current cohort/restoration/key/order/claim/receipt fences and scoped publication outcomes are preserved.
Actual participant qualification and trusted witness verification remain consumer responsibilities, not entity flags
or optional preflights. TASK-00182's Command conversion is not implemented here. Canonical Work handoff/receipts own
verification and exact subject mapping; independent Review and nonvisual QA are still required. This checkpoint
claims no acceptance, consumer persistence/crypto/restore qualification, publication, release or deployment.

## TASK-00113 AgentName ownership — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover the implemented candidate; current delivery results are in
`.runs/handoffs/TASK-00113/land.md`. Acceptance does not imply merge, release, consumer qualification or deployment.
The Work checkpoint below records its earlier stage, not a current Review/QA instruction.

`AgentName::fromString()` now owns the single fixed Unicode White_Space edge set plus NUL, nonempty UTF-8 and
120-code-point rule for provisioning and rename. It preserves case/internal whitespace, performs no NFC conversion
and leaves U+200B/U+FEFF untrimmed. Canonical request construction consumes the value, with safe operation-failure
translation; the duplicate canonicalization name helper is removed directly without an alias or mode.
Rename intentionally changes from PHP `trim`: NBSP/ideographic padding trims and Unicode-whitespace-only input
rejects. This is an approved breaking pre-v1 behavior change, not behavior-preserving cleanup.

Provisioning marker **2** and exact existing valid request bytes remain unchanged. Retained original issuance,
keys/tombstones/restart, current authority and no-op/transaction/publication fences remain independent of name
construction. TASK-00114's operation/read redesign and TASK-00182's Command conversion remain separate work.
Fixed expected value/canonical/rename/profile fixtures and existing recovery conformance own package verification;
canonical evidence is in `.runs/handoffs/TASK-00113/work.md`. Independent Review and nonvisual QA are still required;
this Work checkpoint claims no acceptance, consumer migration, publication, release or deployment.

## Current combined MCP and capability organization

TASK-00108's outward MCP presentation/bindings/availability and TASK-00109's inward capability homes and descriptive
collaborators are combined here. Current composition uses `Adapter/Mcp/{Tool,Resource,Skill}/Agent`, Agent
`Authentication`, `Credential`, `Delivery`, `Maintenance`, `Continuation` and `PermissionAssignment`, Feature
`Discovery` and authorization `Admission`. No Common dependency, wire/security/transaction/recovery contract or
consumer qualification is changed by their integration. Historical independent checkpoints below retain their
original subjects; the advancing-base integration evidence is in `.runs/handoffs/TASK-00109/develop-merge.md`.

## TASK-00109 capability organization — Work checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover candidate `eb976a98d967865030dc7bf964015365dbc7ad2f`. Publication and final delivery verification were pending at closeout; current results are in `.runs/handoffs/TASK-00109/land.md` and the actual PR. The Work checkpoint below is historical; consumer qualification remains separate.

The selected implementation groups Agent collaborators and mirrored tests under `Authentication`, `Credential`,
`Delivery`, `Maintenance` and `Continuation`, retaining actual inward ports and distinct failure/authority/outcome
contracts. The internal assignment coordinator is in `PermissionAssignment`, while message handlers keep their
registrations and post-commit publication. Feature reference/discovery contracts share `Feature/Discovery`;
Permission declarations, admission and trusted request authority share `Authorization/Admission`. MCP profile
Tools/catalogs/family availability remain at their current Application homes for separate TASK-00108.

The naming inspection covers all owned PHP source, tests/fixtures, shipped OpenAPI helpers and maintained runnable
examples. Repositories, buses and dispatchers now use responsibility names, including named callers and native
composition. Actual lists/maps/results retain plurals: Agent/Permission/Role collections, repository backing arrays,
FeatureCreated event batches, captured event lists and the User repository-state fixture are not collaborators.
Different fixture participants keep qualifiers (for example login/reset repositories and role/Agent dispatchers).
The current [composition map](docs/agent-integration.md#public-composition-map) describes canonical homes; no aliases,
new forwarding graph, new dependency or behavior redesign is introduced. User authentication decomposition and Agent
name/operation redesign remain separate work. Canonical TASK-00109 handoff/logs own verification and exact subject;
independent Review and behavioral QA remain required. This Work checkpoint is not acceptance, release or adoption.

## TASK-00108 MCP relocation — accepted checkpoint

Independent Review ACCEPT and nonvisual QA PASS cover candidate `eacf83ccb0637db24274339dc157cf045f3fdc58`.
Publication and final delivery verification were pending at closeout; current results are in
`.runs/handoffs/TASK-00108/land.md` and the actual PR. The Board owns current execution routing.

### Work checkpoint

Agent profile Tools/failure mapping, Tool catalog/attribute and Tool/Resource/Skill bindings/catalogs/availability
now live in `Adapter/Mcp/{Tool,Resource,Skill}/Agent`, with mirrored tests and current family composition guides.
Retired Application PHP names have no aliases; profile bus arguments are `queryBus` and `synchronousCommandBus`.
Existing Common v1.4.0 invocation/registry/descriptor and actual bus paths remain in use; TASK-00110 canonical
upstream adoption is separate. Authentication, signed-request contracts, direct Permission policy and synchronized
continuation remain in Application/Domain. There is no profile facade, new endpoint, grant or consumer bootstrap.
Wire IDs/schemas/results, neutral Tool concealment versus Resource/Skill operational failures, nonce/request scope,
post-commit name-change semantics and consumer qualification limits are unchanged. At the Work checkpoint independent
Review and nonvisual QA were required; Work did not self-close the TASK. See [current profile composition](docs/agent-profile-tools.md)
and [TASK-00108](planning/tasks/00108-TASK.md) for verification progress.

## TASK-00107 guidance checkpoint — independently accepted

Independent Review and nonvisual QA accepted candidate `cc022c4f664841e21a7401a6d356b411167a90c0`.
Publication and final delivery verification were pending at closeout; current results are in
`.runs/handoffs/TASK-00107/land.md` and the actual PR. The Board owns subsequent execution routing.

The checkout-local [Architecture rules](docs/engineering/standards/Architecture.md#accesscontrol-placement-and-dependency-rules)
and [Naming rule](docs/engineering/standards/Naming.md#descriptive-collaborators-and-collections) now codify the approved
EPIC-00015 target. AGENTS links those owners; the profile and ADR 0001/0003 amendments visibly reconcile the earlier
framework-only Adapter and single-AuthenticationService requirements. WF-035's closed resolution remains history.
The [source-linked Common notes](planning/agents/domain.md#consumed-common-contracts) identify current consumption
without guessing canonical APIs/minima or clearing TASK-00110's hold.

This was guidance implementation only, not source-conformance certification. At that candidate, source kept Agent MCP
Tools/catalogs/family availability in Application, broad Agent Security/Service homes, one AuthenticationService,
and operation types under Agent. TASK-00108 owns MCP presentation moves, TASK-00109 cohesion/naming, TASK-00111/00112
User transitions/synchronous decomposition, TASK-00113 AgentName and TASK-00114 operation/read ownership.
TASK-00110 owns actual qualified Common adoption; the separate Agent command conversion is still a future supplier.
No production/test/dependency/executable-gate change or consumer/persistence infrastructure is supplied here.
At the Work checkpoint, independent Review and guidance walkthrough QA remained required; Work did not self-close TASK-00107.

## Approved pre-v1 architecture and Common integration plan

John approved [EPIC-00015](planning/epics/00015-EPIC.md), [TICKET-00033](planning/tickets/00033-TICKET.md)–
[TICKET-00036](planning/tickets/00036-TICKET.md), and the full [TASK-00107](planning/tasks/00107-TASK.md)–
[TASK-00114](planning/tasks/00114-TASK.md) split. The accepted target clarifies aggregate-first ownership, moves
MCP presentation/registry bindings outward, groups Application capabilities, requires descriptive collaborators,
encapsulates User revision transitions, replaces the broad synchronous authentication graph, unifies AgentName
and makes credential-operation/read ownership explicit. TASK-00107 owns guidance reconciliation; these are
approved target changes, not implemented source or verification claims.

John approved one additional requirement area and one chore for Agent provisioning/rotation conversion, then
explicitly amended the caller contract: void Commands and independent outcome Queries, invocable sync or async at any
time with no particular transport. [TICKET-00053 — Issue Agent credentials through Commands and outcome Queries](planning/tickets/00053-TICKET.md)/
[TASK-00182](planning/tasks/00182-TASK.md) own the complete conversion/caller/fixture/docs change. Production sources
were direct services at that planning checkpoint; the current Work checkpoint above now supplies the conversion.
Planning approval alone was not source conformance.

Retain secret-free original operation key/request before dispatch and across delay, restart, retries and redelivery.
Execution rechecks current real invoker/delegation, target/destination/expected state and transaction-duration writer
fences; serialized actor/scope or an enqueue-time allow is not authority. Reuse the authorized read-only
GetAgentOperation/AgentOperationView for confirmed original issuance and recorded dispositions or indeterminate status.
Absence proves neither rollback, rejection nor queue progress; storage failure is unavailable. No duplicate Query model,
automatic replacement key, raw-secret message or claim that issuance authorizes delivery/activation/use.

Confirmed commit remains queryable even when both publishers fail; no invocation-specific warning is returned or
reconstructed by status. Pre-commit and uncertain-commit failures retain safe execution error/same-key recovery semantics,
without promising an async submitting caller the worker's exception or adding durable attempt/warning/outbox storage.
The original result-capable Common prerequisite is superseded. TASK-00182 uses supported void Command/Query APIs and
was ready-for-agent at that planning checkpoint; the accepted checkpoint above records subsequent minimum/composition verification.
[EPIC-00016](planning/epics/00016-EPIC.md#agent-command-result-coordination-follow-up)/TASK-00130 retain separate exact
payload/metadata/registration and Query-result typing qualification with blocked_by TASK-00182. Their actual generic
supplier evidence holds are not a functional conversion prerequisite. Audit/type owners follow the actual candidate
contract without resurrecting removed result APIs or expanding earlier architecture/operation scope.

The original EPIC-00015 four-TICKET/eight-TASK split plus this fifth-TICKET/ninth-TASK addition are fully planned.
No earlier Wayfinder phase applies. Next: `/skill:next` for current Board selection, not automatic execution.
The selected isolated branch fast-forwarded local develop to 3efa74d4092d98c3847f57f26896d1912b47256e with permission,
preserving TASK-00107's independently accepted architecture guidance. No source/tests/dependency/Common/consumer
mutation, product build, commit/push/PR, independent implementation acceptance or release follows from this planning.

The [Roadmap](planning/ROADMAP.md#first-priority--pre-v1-architecture-enablers) puts the three local enablers first.
Canonical consumed-Common adoption TASK-00110 remains needs-info for actual tagged/accepted/qualified APIs and
sufficient minimum evidence; Common's concurrent local refactoring plans do not supply that proof. Pending MCP
roots TASK-00084/00087/00091/00097 consume TASK-00108/00110, with descendants inheriting those suppliers. Each
feature retains its own additional Common family/operation qualification. Native TASK-00105/00106 remain independent
of MCP and the later User/Agent redesigns; those redesigns follow existing features rather than blocking them all.

EPIC-00015 visibly amends conflicting earlier layout/single-service/future-supplier guidance while retaining
inward dependencies, Common ownership, synchronous sensitive non-serializable inputs, independent consumer policy,
atomicity, recovery/redaction/outcome behavior and current-contract safety. Apply ADR 0011 directly: no AccessControl
aliases/legacy modes. Existing code, completed TASKs and release checkpoints below remain historical baseline;
no source/dependency/configuration/grant change, Common/consumer mutation, execution, commit/publication or release
is claimed. EPIC-00014's combined release/all-five-framework scope and unassigned version remain unchanged.

## Approved external MCP Tool binding reconciliation

John accepted the existing external-Tool design recommendations and authorized reconciliation of AccessControl's
records only. [TICKET-00025](planning/tickets/00025-TICKET.md) / [TASK-00084](planning/tasks/00084-TASK.md#approved-binding-contract)
retain one complete mixed registry: explicit maps for Common-owned Tools, attributes for application/package-owned
Tools, and rejection of both on one Tool even when equal. The approved catalog extension accepts exact canonical
Tool-name keys with nonempty conjunctive Permission-string lists, retaining Common's exact immutable definitions.
This remains an implementation target; the current catalog is attribute-only. No subclass/copied schema, reverse
Common dependency, automatic grant or duplicate TASK is introduced.

Request-bound providers/availability/profile Tools share one request and cannot become singletons; rebuild their
registry/catalog together. Later pages/calls/retries need fresh request authority. Exposed direct Tool/CLI/HTTP/
broker/worker entries own caller/operation/target admission; the raw configured bus remains trusted, not another
public API or a blanket second authentication gate. Coarse availability creates no target, metadata or body scope.

[Common TASK-00205](https://github.com/johnnickell/fight-common/blob/8f69f701102f246c0f77000d46bd4238f8f56455/planning/tasks/00205-TASK.md)
is merged approved planning for optional safe activity target/timing/result facts, not implemented/tagged supplier
availability. [TASK-00089](planning/tasks/00089-TASK.md#typed-activity-facts-reconciliation) owns their scoped timeline
qualification when supplied by the selected tag; [TASK-00090](planning/tasks/00090-TASK.md#external-tool-and-typed-fact-reconciliation)
retains independent structural/metadata/body admission, including metadata-only results. Typed facts confer no
grants, business-success evidence, stable history, automatic capture or new transaction guarantee. No new
TASK-00205 blocker or reverse Common release prerequisite is added. Existing statuses/orders/edges, TASK-00110,
the qualified/tagged/minimum holds and EPIC-00014's combined-release boundary remain unchanged. Agent OS record
reconciliation is a separate operator prompt; this operation makes no edits there and starts no implementation.
The investigation below retains its historical observations; the owning TASKs record current amendments.

## Future Common MCP permission integration — investigation 2026-10-25

John requested investigation/planning for a future AccessControl release. The
[closed map](planning/wayfinder/mcp-capability-permission-integration-map.md) and
[WF-032 evidence](planning/wayfinder/research/fight-common-mcp-permission-integration.md) preserve the initial Common
maturity investigation, including the then-uncommitted Prompt source. EPIC-00014 records later local observations
without treating Common planning, local completion or tags as actual consumer qualification. Common has neutral enforcement seams; all seven execution Tools fail closed in the current
attribute-only AccessControl catalog (research probe: 7/7 rejections, no Query calls). Resource bindings now have the
TASK-00085 implementation checkpoint below; TASK-00086 adds the whole-Skill checkpoint below. TASK-00088 now supplies
explicit current continuation admission under the checkpoint below. Prompt bindings and actual Common live/Task
runtime integration remain unimplemented work, not evidence of a bypass. Consumers still own scope/fence composition;
the existing request-local principal snapshot is not a live permission lease.

[WF-033](planning/wayfinder/tickets/WF-033-common-owned-mcp-permission-bindings.md) is Closed: John accepted explicit
Tool mappings alongside attributes, permission-check tooling with consumer-owned policy/scope, request-local admission
and separate current live/continuation checks. His follow-up asks about framework-native adapters and requires
compatibility with Common's safe-error approach. [WF-035](planning/wayfinder/tickets/WF-035-framework-permission-adapter-ownership.md)
is Closed: John selected optional integrations in this package's `src/Adapter`, using Common-style capability-first
layout while retaining DDD in Domain/Application. The profile records the narrow approved boundary; TASK-00103 now implements the first optional PSR-15 adapter
with Slim/Yii 3 composition under the checkpoint below. TASK-00104 now implements the optional native Symfony
subscriber under its Work checkpoint below; TASK-00106's CI4 implementation has independent acceptance below. Laravel remains separate work. WF-034 is also Closed: John approved one combined release covering the selected MCP families including
Subscriptions/Tasks and all five frameworks (Slim, Yii 3, Symfony, Laravel, CodeIgniter 4). Grill wrote the single
approved destination as [EPIC-00014 — Permission-Aware MCP Integration and Framework Adapters](planning/epics/00014-EPIC.md).
The map is Closed for completed decisions and EPIC handoff. John approved the complete requirement split, now
written as [TICKET-00025](planning/tickets/00025-TICKET.md) through [TICKET-00032](planning/tickets/00032-TICKET.md):
Tool binding; Resource/Skill; Prompt; current continuation authority; scoped execution/detail; notices/Subscriptions;
durable Tasks; all-five native HTTP integration. All map TICKET planning is complete. John then selected
TICKET-00025 for To Tasks and approved its complete single-TASK plan, now written as
[TASK-00084 — Protect mixed Common-owned and attributed MCP Tools](planning/tasks/00084-TASK.md). It is
`needs-info` pending actual qualified/tagged Common execution APIs and minimum-version evidence, with no unfinished
local TASK blockers. John subsequently approved TICKET-00026's full two-TASK plan, written as
[TASK-00085 — Authorize exact Resource discovery and reads](planning/tasks/00085-TASK.md) and
[TASK-00086 — Authorize complete Skills and protected member reads](planning/tasks/00086-TASK.md).
TASK-00085 is ready with no local blocker; TASK-00086 depends only on TASK-00085. Their actual Common minimum/
behavior qualification belongs to implementation; neither inherits the Tool execution-API hold. John then approved
TICKET-00027's full single-TASK plan, written as
[TASK-00087 — Authorize Agent-protected Prompt discovery and retrieval](planning/tasks/00087-TASK.md), needs-info
for qualified/tagged Common Prompt contracts and supported-minimum evidence, with no unfinished local TASK blockers.
John then approved TICKET-00028's full single-TASK plan, written as
[TASK-00088 — Revalidate bound Agent authority before protected continuation](planning/tasks/00088-TASK.md),
ready-for-agent with no local TASK blocker or new Common runtime API prerequisite. Trusted binding/current checks
and check-to-admission synchronization stay together; actual consumer handoff/lifetime/scope/fence qualification
remains separate. John then approved TICKET-00029's full plan, written as
[TASK-00089 — Compose scoped execution and timeline reads](planning/tasks/00089-TASK.md) and
[TASK-00090 — Compose independent protected-detail metadata and body reads](planning/tasks/00090-TASK.md).
Both consume TASK-00084 and are needs-info for qualified/tagged Common scoped-read contracts/minimum evidence;
neither depends on the other or on TASK-00088 for ordinary requests. John then approved TICKET-00030's full plan,
written as [TASK-00091](planning/tasks/00091-TASK.md) recording/removal,
[TASK-00092](planning/tasks/00092-TASK.md) retained inspection/recovery,
[TASK-00093](planning/tasks/00093-TASK.md) live Tool/Resource/Skill disclosure,
[TASK-00094](planning/tasks/00094-TASK.md) Prompt signals,
[TASK-00095](planning/tasks/00095-TASK.md) subscriber inspection and
[TASK-00096](planning/tasks/00096-TASK.md) optional publication. All six retain needs-info Common qualified/tagged
contract/minimum holds and their approved local supplier edges. Prompt absence does not block ordinary live families;
publication needs no live/Prompt/inspection integration, and ordinary polling needs no live runtime. John then approved
TICKET-00031's full plan, written as [TASK-00097](planning/tasks/00097-TASK.md) creation/producer/protected reads,
[TASK-00098](planning/tasks/00098-TASK.md) removal, [TASK-00099](planning/tasks/00099-TASK.md) input/continuation,
[TASK-00100](planning/tasks/00100-TASK.md) cancellation/intent,
[TASK-00101](planning/tasks/00101-TASK.md) complete MCP start/reconnect and
[TASK-00102](planning/tasks/00102-TASK.md) optional full-state notifications. All six retain needs-info qualified/tagged
Common contract/minimum holds plus approved supplier edges. Ordinary polling/input/cancel need no live runtime;
removal does not block complete protocol activation, and full-state live has no extra Prompt/inspection/Mercure
blocker. John then approved TICKET-00032's full plan, written as
[TASK-00103](planning/tasks/00103-TASK.md) shared PSR-15 with separately qualified Slim/Yii 3 journeys,
[TASK-00104](planning/tasks/00104-TASK.md) Symfony, [TASK-00105](planning/tasks/00105-TASK.md) Laravel and
[TASK-00106](planning/tasks/00106-TASK.md) CodeIgniter 4. All are ready-for-agent: TASK-00103 has no local blocker;
native peers depend only on its shared inward contracts/initial Adapter wiring, not Slim/Yii runtime. Native peers
share order 104 without relative product priority. No inherited MCP/TASK-00088 hold; actual sufficient tagged
minima/native qualification remain acceptance work. All map decisions, single EPIC, eight TICKETs and full TASK
plans are accepted/written as TASK-00084–00106 (23 features), not implemented/done. Next: `/skill:next` for Board-based
selection, not automatic execution.
TASK-00085 is the first accepted implementation checkpoint for this EPIC: canonical Review ACCEPT and nonvisual QA PASS
cover exact candidate `e2b7594b0005867880601f8d244cca3671a3d746`; [PR #139](https://github.com/johnnickell/fight-access-control/pull/139)
is open against `develop`. AccessControl's release number stays unassigned; actual qualified/tagged Common contracts,
dependency minima and framework evidence remain external
prerequisites, not guessed versions or proof of capability readiness.

Agent OS's request-local middleware pattern fits, but its custom failure mapping is not a drop-in Common safe-handler
migration. The inspected safe-error baseline already ships in Common v1.4.0. John subsequently authorized committing,
pushing and opening a PR to develop for these planning changes only. Implementation, Permission grants, dependency
changes, merge, release and consumer adoption remain outside that publication request. v0.8.0 remains the current published
AccessControl release. Existing product suites were inspected, not rerun; the bounded host-PHP probe is not a
canonical Docker build or consumer qualification.

## Native CodeIgniter 4 Permission admission — TASK-00106 Work checkpoint

Independent Review ACCEPT and behavioral QA PASS cover candidate `eb1b70470747df0bbae776272284ef7b11c8d155`.
Publication and final delivery were pending at closeout; current result: `.runs/handoffs/TASK-00106/land.md`.
The Work checkpoint and its pending-stage wording below are historical; consumer qualification remains separate.

TASK-00106 Work supplies optional `Adapter\\Http\\CodeIgniter` before-filter admission using the existing inward
PermissionDeclaration/RequestAuthority/PermissionAdmission and actual User/Agent providers. Native matched PHP route
options supply requirements; headers/filter arguments/copied contexts are not grants. Reused injected services clear
per-request context before fresh selection. Native ResponseInterface denial precedes protected validation/controller
construction/action for the four documented route forms. Multiple requirements use one Agent authentication/nonce.
No automatic registration, identity issuer, grants, scope policy, Feature or portable Permission API is introduced.

The [CI4 guide](docs/codeigniter-permission-admission.md) owns the zero-argument native filter shell/service composition,
current router/request/service-reset duties, route/attribute construction limits and explicit JSON-only coverage.
CI4's normal custom exception handler runs after its raw logger. The selected SafeCodeIgniter loop therefore catches
routing through downstream handleRequest failures before native 404/global formatting, sharing one Common-policy
SafeErrorResponder with the filter. One redaction-aware diagnostic attempt precedes finite Common projection/encoding;
metadata/sink/projector/native-render/fallback failure retains a fixed safe 500. Fresh responses discard stale headers
and partial output. Required filters and native Restrict remain intact; bootstrap/required-filter/outer-event/send/
shutdown failures, HTML/global handling and reentrant process-global nested dispatch are explicitly outside proof.
MCP/OAuth keep separate entry points. Native CI4 exceptions unknown to Common remain generic 500, not guessed statuses.
Common v1.4.0's CI4 JSend helper double-encodes under the qualified native API, so the bridge applies Common's encoded
envelope with native setJSON's default encoded-string mode; no supplier source or semantic policy is duplicated.

Selected sufficient tags: Common v1.4.0 (`^1.4`) and CI4 v4.7.6 (`^4.7.6`), executed PHP 8.5.12/Xdebug 3.5.3 with
intl/mbstring. CI4 is an optional suggestion/dev fixture; tooling now installs its required intl extension. Both
ordinary and isolated exact-minimum Common/CI4 source sets match tag archives; isolated native tests need no Slim/Yii/
Symfony application runtime, and a no-dev core fixture proves actual User/Agent admission with CI4 absent. Mirrored
Adapter/default/OpenAPI tests, exact owned coverage and existing gates remain required. Focused tests cover all 52 new
statements; current counts/full-gate evidence and exact candidate bridge belong to TASK-00106's Work handoff. Independent
Review and behavioral QA are pending; this Work checkpoint is not acceptance, adoption, release or deployment.
Actual consumer bootstrap/routes/reset/authentication/storage/crypto/scope/sinks/proxies/cache/runtime remain unqualified.

## Native Laravel Permission admission — TASK-00105 acceptance checkpoint

TASK-00105 now supplies explicit `Adapter\\Middleware\\Laravel\\PermissionMiddleware`, exact trusted Route/action
bindings and fresh inward User/Agent admission. The [Laravel guide](docs/laravel-permission-admission.md) owns native
registration, six controller forms, discovery/construction limits, FormRequest/validator order, sequential/nested
request lifetimes and single-authentication nonce semantics. No guard fallback, Feature/portable Attribute, automatic
bootstrap/grants or competing error handler is supplied. Common alone owns selected safe JSON report/render.

The original supplier defect below is resolved by tagged Common **v1.4.1**; minimum is now `^1.4.1`, excluding broken
1.4.0. The original persistent-encoder regression passes with one diagnostic attempt and fixed safe native 500;
default native tests include two/persistent encoder faults and failed-sink/terminal fallback. Actual response
prepare/sendContent and later independent requests remain bounded. Common's delegated non-JSON path is explicitly
not a sanitizer. Native ambient state restoration/concurrency and arbitrary final observers remain consumer duties.

Selected native bounds are Laravel `^13.40.0`, HttpFoundation/HttpKernel `^8.1.11` and Event Dispatcher `^8.1.5`.
Preliminary 8.1.9 components passed behavior but reported five security advisories; 8.1.11 is the supported floor.
Actual tagged source/archive/lock, independent-supplier-to-tag runtime bridge, ordinary/isolated minimum and no-Laravel
core evidence are retained in canonical `.runs/tasks/TASK-00105/runs/engineer-02/`. The same branch safely integrates
current develop through `2705dcc2fe10489080113489488b6f124b47c4cb`, reusing current CI4/User/Agent boundaries.
The initial combined full gate exposed Laravel/CI4 global `config()` helper collisions. Native test launchers now
defer Laravel Foundation/Support globals before Composer; fresh Laravel test processes load actual supplier helpers.
This isolates application bootstraps without modifying CI4 or supplier production code. Current full-gate result and
exact content-to-commit bridge belong to `.runs/handoffs/TASK-00105/work.md`.
Independent Review ACCEPT and behavioral QA PASS cover candidate `b7faea4c10b84ec86ad090f9ea790111c846cc84`.
Publication and final delivery were pending at this checkpoint; current result: `.runs/handoffs/TASK-00105/land.md`.
Controlled fixtures do not qualify actual consumer guards/bootstrap/storage/crypto/scope/sinks/proxies/workers/deployed
runtime. The combined release remains incomplete and unassigned.

### Historical supplier-blocked Work checkpoint

TASK-00105's isolated Work attempt contains partial **uncommitted, unaccepted** native middleware and mirrored
kernel/router fixtures. It binds declarations to exact trusted Laravel Route objects and their registered actions,
selects fresh inward User/Agent authority and leaves reporting/rendering solely with Common's explicitly selected
native handler. Controlled tests distinguish lazy/static controller forms from legacy constructor-time middleware
discovery, and exercise FormRequest/order, sequential/nested requests, nonce replay refusal, safe JSON negotiation,
headers and primary fallback. Feature admission and portable Attributes remain follow-up work.

**Supplier hold:** tagged Common v1.4.0 `SafeLaravelExceptionHandler::fallback()` does not contain an encoder failure.
Actual Laravel kernel probes show recursive report/render (two attempts for two encoding failures; six for persistent
failure) and a private-bearing renderer exception escaping on persistent failure. The TASK therefore needs a
qualified tagged Common terminal-render/fallback contract before its dependency minimum can be called sufficient.
No competing AccessControl renderer or supplier repair is authorized. The focused ordinary/minimum suite passes
57 tests / 504 assertions, but the separate supplier regression fails; no complete gate, commit or acceptance is
claimed. See [TASK-00105](planning/tasks/00105-TASK.md) and canonical `.runs/handoffs/TASK-00105/work.md` for exact
source/lock/runtime evidence, retained isolated resources and resumption inputs. Native fixtures do not qualify
consumer guards, bootstrap, persistence/crypto, sinks, deployed or worker runtimes. Combined release remains incomplete.

## Native Symfony Permission admission — TASK-00104 Work checkpoint

Independent Review ACCEPT and behavioral QA PASS cover candidate `fb25934087f931527325c3d66aa49e2b83a944eb`.
Publication and final delivery were pending at closeout; current result: `.runs/handoffs/TASK-00104/land.md`.
The Work checkpoint and its pending-stage wording below are historical. Consumer qualification remains separate.

TASK-00104 Work adds explicit `Adapter\\Http\\Symfony\\EventSubscriber\\PermissionSubscriber` at post-routing
kernel.request priority 16, before controller resolution/construction, Common Fight validation, arguments and native
IsGranted. It independently rematches the same trusted immutable PHP RouteCollection/UrlMatcher and checks native
route/controller association; copied request defaults/context cannot select requirements or grants. It reuses the
shared inward policy, actual request-owned User/Agent providers and explicit public/authenticated/protected modes.
Sequential requests and distinct nested subrequests get independent trusted selection; there is no parent-identity
handoff, cached singleton provider or per-listener Agent nonce replay. Consumer direct/alternate use-case protection
and later current-authority continuation remain independently owned.

The [Symfony guide](docs/symfony-permission-admission.md) owns six supported controller forms, construction limits,
IsGranted/Common Validation ordering and selected Common native safe error composition. Common's native listener at
exception priority 64 reports once before the actual default ErrorListener logger at 0, with validated headers,
finite public projection and safe sink/projector/encoder fallback. JSON/XHR uses canonical negotiation; delegated
HTML/missing-Accept logging/rendering is not claimed redacted/safe. No competing renderer, firewall/token issuer,
grants, consumer bootstrap or automatic registration is supplied. MCP/OAuth stay outside this ordinary HTTP path.

Selected sufficient native minima are Event Dispatcher 8.1.5 and HttpFoundation/HttpKernel/Routing/optional Security
HTTP 8.1.9; Common remains tagged v1.4.0 (`^1.4`). All seven installed Common/native package file sets (including
transitive Security Core 8.1.9) match tagged archives. Exact-minimum fixtures pass with Slim/Yii application runtimes
absent, including a core-only lane without Security HTTP. The executed patched runtime is PHP 8.5.12. Native components
remain optional suggestions/dev fixtures; production requirements are unchanged. Mirrored Adapter/default/gate wiring
and exact coverage remain mandatory. The canonical full gate passes **2641 tests / 48524 assertions**, exact
**7721/7721 statements** (**18/18** new), wrapper/workload exits **0**, with no final warnings/skips. Focused native
proof passes **44 tests / 376 assertions**. Source, lock and all installed vendor bytes remain stable across the gate;
only image rebuild attestation changes, with runnable Config/RootFS equivalence verified. TASK evidence and Work
handoff own full logs, chronology and the prose-only content-to-commit bridge; independent Review and behavioral QA
are pending. Controlled native fixtures are not consumer persistence/crypto/scope/bootstrap/
sink/firewall/proxy/deployment qualification. Other native peers and the combined release/version remain unfinished.

## HTTP Permission admission — TASK-00103 acceptance checkpoint

TASK-00104 intake verified PR #143 MERGED into `develop` at `d4fda9b41fff0dde922c7dae569ee71805d0e358`
on 2026-10-28T12:10:37Z, with published head `87e3d640aaf882c58dddfe2effbefa5c6fe05cf5` integrated at the selected base.
Its marker is retired; no TASK-00103 review/publication/merge action remains. The following pending-at-closeout
wording and Work evidence are historical checkpoints.

Independent Review ACCEPT and nonvisual QA PASS cover candidate `2807785e332187a36299c08ce3826e9fed585729` with no findings. TASK-00103 is done for accepted package behavior; TICKET-00032 and EPIC-00014 remain open. Publication and final delivery were pending at closeout; current results are in `.runs/handoffs/TASK-00103/land.md`. The Work checkpoint below is historical, not current acceptance status.

### Historical Work checkpoint

TASK-00103 Work implementation is verified, awaiting independent Review and behavioral QA. The
[HTTP admission guide](docs/http-permission-admission.md) owns the shared `PermissionDeclaration`/`AdmissionMode`,
`RequestAuthority`, `PermissionAdmission` and optional `Adapter\\Middleware\\Psr15\\PermissionMiddleware` contracts.
Declarations bind directly to native route middleware, not request metadata/callback reflection. Actual User role-derived
and Agent direct-Permission providers resolve one trusted request authority; incomplete/opaque Agent resolution remains
operational failure, not fabricated 401/403. Public/authenticated/protected modes, conjunctive requirements, fresh factory
lifetimes and nested-context isolation retain existing snapshot/nonce semantics. Consumers protect target scope and
alternate use-case entry points independently. Common alone reports/presents selected ordinary HTTP failures; MCP/OAuth
remain outside JSend wrapping. Native peers consume inward contracts without depending on Slim/Yii runtime.

The selected Common minimum is now `^1.4` (tag v1.4.0); Slim `^4.15.3`, Yii Router `^4.0.2`, FastRoute `^4.0.3`,
Middleware Dispatcher `^5.4`, DI `^1.4.1`, Injector `^1.2.2` and Slim PSR-7 `^1.8` are optional native/dev-fixture
ranges. All selected lower bounds are installed tagged versions with source/archive equivalence; patched PHP 8.5.12
is the qualified runtime. Composer/autoload, Deptrac, style/static-analysis/Rector/default Adapter suite and exact
coverage wiring include the new layer without weakening inward rules. Controlled native fixtures are not actual
consumer bootstrap, cryptography/persistence/scope, redacted production sinks, proxy/security qualification or adoption.
The final native-bound gate passes **2597 tests / 48148 assertions**, exact **7703/7703 statements** (**45/45** new), with
actual wrapper and Docker workload exits **0** and no final warnings/skips. The first full attempt exhausted the
default 128 MiB during OpenAPI generation; the expanded default workload now has a bounded 512 MiB PHPUnit budget
without weakening suites or coverage. Final peak memory is 128.50 MiB. Final packages/lock/vendor source are stable;
only generated installed root-reference metadata refreshes, with exact before-hash reconstruction proof. Full chronology
and frozen-input snapshots are in the TASK and Work handoff. Final handoff inspection corrected a test-only native
Agent request-binding gap regression-first: signed context now matches the actual native method/authority/path rather
than reusing the MCP helper target. Production contracts are unchanged; updated focused evidence passes 29 tests /
445 assertions, followed by that fresh complete final gate and a prose-only content-to-commit bridge. No independent
acceptance, publication, release or deployment is claimed; all-five combined EPIC release/version remains unfinished.

## Current continuation authority — TASK-00088 acceptance checkpoint

TASK-00103 intake verified PR #142 merged into `develop` at `14eae522213d03c936cd03b34c1f8ef54ed81487`
on 2026-10-27T22:18:27Z and integrated in the selected base. Published head
`8d24b2b177208a8218a2258cf39fa2e87dac15d0` is its ancestor. The marker is retired; no TASK-00088
review/publication/merge action remains. The following checkpoints preserve their original evidence and timing.

Independent Review ACCEPT and nonvisual QA PASS cover candidate `b9db457d68e46cddbdde738d7e241fbc2b9bd891`.
TASK-00088 and TICKET-00028 are done for accepted package behavior; EPIC-00014 remains open. Publication and final
verification were pending at this checkpoint; current delivery results are in `.runs/handoffs/TASK-00088/land.md`.
The Work checkpoint below remains historical; actual consumer qualification and the combined release remain separate.

### Historical Work checkpoint

John selected the main checkout for TASK-00088 from `develop` `7fc6c313c39df96f7e261144025433d2b5c46e31` on
`feature/task-00088-continuation-authority`. The [continuation guide](docs/agent-continuation-authorization.md) owns the
new `AgentContinuationBinding`, original claim context, trusted worker-handoff port and `AgentContinuationGuard` API.
The binding roots in complete admitted authentication, retains the original Agent/credential identity/revision and
five-minute provenance window, and rejects serialization/hydration. Copied principals/Permission lists are not proof.
Worker reconstruction requires separately trusted provenance/recipient verification, never refreshed original time.

Each bounded queued disclosure, background step or retained-input consumption checks current cohort/restoration
readiness, ACTIVE original credential, exact fresh direct ADMIN_SAFE Permission definitions and conjunctive canonical
requirements, current consumer scope and final provenance validity under required `AgentContinuationFence` protection.
The actual action runs synchronously while that qualified composite fence remains held; no detached Boolean or cached
allow authorizes later work. All Agent/Permission/scope writers must coordinate. Before-acquisition changes affect
admission; conflicting writes wait/reject through the action, and after-release changes affect the next action.
Ordinary short-request snapshots/nonce transactions remain unchanged. Rechecks replay neither HMAC nor nonce.

Neutral pre-admission rejection contains no private cause; action/fence failures after invocation remain owned by the
consumer's safe error boundary, not fictitious denial/rollback. No grants, business events/commits, issuer, executor,
stream manager, Common live/Task API, persisted business schema or new Common dependency prerequisite is introduced.
Controlled package scenarios do not qualify real persistence/cryptography, worker handoff/lifetimes, scope authorities,
clocks or races. Independent technical Review and behavioral QA remain pending; TASK-00088/TICKET-00028 are not done.
Both latest and tagged Common v1.3.0 focused lanes pass **26 tests / 601 assertions**. The single canonical full gate
passes **2568 tests / 47703 assertions**, exact **7658/7658 statements**, with actual wrapper exit **0** and no final
warnings/skips. Packages/lock and runnable runtime Config/RootFS remain unchanged; only installed metadata regenerates.
Full verification results and exact tested-content/commit mapping belong to the TASK and ignored Work handoff.
The combined EPIC release/all five frameworks/unassigned version remain unchanged; no publication or adoption is claimed.

## Resource Permission composition — TASK-00085 implementation checkpoint

TASK-00085 adds `AgentResourcePermissionBinding`, `AgentResourcePermissionCatalog` and request-scoped
`Security\\AgentResourceAvailability` in the existing Application Agent boundary. The
[Resource guide](docs/agent-resource-authorization.md) owns exact provider/URI composition, dynamic fresh metadata,
conjunctive direct Permissions, trusted request-provider reuse and independent consumer containment/scope obligations.
Static binding checks use metadata-only exact lookup, not content opening or a discovered/frozen registry. Unknown
runtime metadata denies. Common retains collision/descriptor validation, exact lookup, finite bounds and stream cleanup.
Authentication/Permission denial conceals; operational resolution, invalid Permission snapshots and provider/policy
faults propagate to Common's safe error boundary, not fabricated empty/partial success. Requests/pages/reads use fresh
authority with private zero-TTL results; a short already-resolved request retains its immutable snapshot.

The existing Common `^1.3` minimum is sufficient: tagged v1.3.0 (`7de6cad6e8a9752973ad9f8e27e285b0c1510582`) passes
actual Resource/principal composition tests in isolated dependency scratch; the ordinary installed lane is v1.4.0
(`87b9580c072f3149d574e09865daa79620c92118`). At the initial checkpoint both focused lanes passed **62 tests / 412
assertions**. That canonical full gate passed **2453 tests / 45796 assertions**, exact **7507/7507 statements** including **55/55** new Resource
statements. A regression-first Domain correction also rejects terminal-newline Permission names. TASK evidence owns
exact locks, inputs, tooling-update/warning chronology and the prose-only retained-gate bridge.
Independent Review identified F1: PHP loose descriptor comparison admitted unequal numeric strings and nested scalar
types, including stale metadata links through real discovery. The regression-first repair compares public descriptor
values without coercion: scalar types, JSON object/list distinctions and list positions are preserved; object property
order and fresh object identity do not affect equivalence. Both dependency lanes pass **79 tests / 488 assertions**,
including real discovery concealment and equivalent controls. A fresh repair full gate passes **2470 tests / 45872
assertions**, exact **7519/7519 statements**. Repair-specific logs/receipt and exact candidate mapping live in the
TASK Work handoff; the initial gate above is historical, not proof for the changed executable inputs.

This checkpoint has independent technical acceptance and nonvisual behavioral QA, but does not claim deployed
content/cryptographic/database qualification, Skill admission (TASK-00086), consumer adoption, publication or release.
TASK-00085 is done for required package verification; the approved combined EPIC release and unassigned version remain
unchanged.

## Whole-Skill Permission composition — TASK-00086 acceptance checkpoint

Current delivery reconciliation at TASK-00088 intake verified [PR #141](https://github.com/johnnickell/fight-access-control/pull/141)
merged into `develop` at `7fc6c313c39df96f7e261144025433d2b5c46e31` on 2026-10-27 and integrated in the selected base.
Its marker is retired; no TASK-00086 merge/review action remains. The acceptance and historical Work checkpoints below
retain their original evidence and pending-at-checkpoint wording; neither implies consumer qualification or release.

Independent Review ACCEPT and nonvisual QA PASS cover candidate `c1cf1a412209254087bd93eda497679725495420`.
TASK-00086 and TICKET-00026 are done for package acceptance; EPIC-00014 remains open. Publication and final delivery
were pending at closeout; `.runs/handoffs/TASK-00086/land.md` owns the current delivery result.
The following implementation and verification description preserves its original Work checkpoint.

TASK-00086 adds `AgentSkillPermissionBinding`, `AgentSkillPermissionCatalog` and request-scoped
`Security\\AgentSkillAvailability` in the existing Application Agent boundary. The
[Skill guide](docs/agent-skill-authorization.md) owns trusted composition with actual Common Skill availability,
immutable revisions, protected Skill Resources and the exact shared read-enabled Resource capability. Requirements
bind actual validated revisions and exact complete immutable exports, not Tool object identity or URI prefixes.
Canonical nonempty conjunctive direct Permissions, conflicting ownership rejection and fail-closed unknown/inexact
entries supplement Common's integrity/catalog protections without another parser, provider or runtime.

Whole-Skill and every member's Resource admission protect complete list/get results; a denied member conceals the
entire entry. Individual direct reads apply whole-Skill and the selected Resource requirement only, before stream
allocation, without prior discovery or an invented other-members grant. Both decisions share one trusted ordinary
request provider/nonce transaction. Later requests/pages/historical reads reevaluate current authority; already
resolved short requests retain snapshot semantics. Private zero-TTL hints and visible-catalog cursors confer no
authority. Operational faults propagate to Common's safe boundary, never fabricated empty/partial success.
Common owns exact bytes, digests, manifests, bounds, ownership and stream cleanup. Consumers retain containment,
parser/storage/crypto/database/client qualification, relationship scope and untrusted-content handling.

The existing Common `^1.3` minimum is qualified against actual tagged v1.3.0 composition, not inferred from a
label: latest and isolated minimum lanes each pass **156 tests / 1833 assertions**. All 735 source/test/config
inputs match the isolated lane and all 619 installed Common source/Composer blobs match the v1.3.0 tag.
The single canonical full-gate workload passes **2549 tests / 47179 assertions**, exact **7574/7574 statements**
including **55/55** new statements, without final warnings/skips. Its calling tool timed out while the final container
continued; complete output/direct container-process exit **0** were recovered from the same run, not a repeated gate
or an observed successful outer-wrapper completion. Five ignored dev packages update through the normal resolver;
Common/constraints are unchanged. TASK evidence owns exact locks, snapshots, complete logs, timeout/recovery and
the prose-only retained-gate bridge. At this Work checkpoint independent technical Review and behavioral QA
remain pending, so TASK-00086/TICKET-00026 are not done.
No grants, activation, other capability/framework implementation, consumer adoption, version selection or release
is claimed. The one combined EPIC release/all five frameworks/unassigned version remain unchanged.

## PHP runtime security maintenance — approved 2026-10-22

John invoked Build for standalone [TASK-00083](planning/tasks/00083-TASK.md) in the main checkout on
`feature/task-00083-php-runtime` from `develop` `d955d2f`. Both Docker wrappers now share explicit base refresh
and actual-runtime admission before package work. The supported stable 8.5 build line requires PHP 8.5.12 or
later patches for CVE-2026-109618 / CVE-2026-109619; other lines and prereleases fail closed pending assessment.
The isolated behavioral regression reproduced cached PHP 8.5.7 execution before repair and passes afterward.
Registry availability and the real refreshed PHP 8.5.12 CLI image are verified. Explicit tooling qualification
is separate from product coverage/default build/CI; contributor guidance explains failure and offline behavior.
Canonical `./bin/build` passes **2399 tests / 45422 assertions**, exact **7452/7452 statements**, without final
warnings/skips; explicit tooling qualification passes **8 tests**. Composer packages/lockfile/installed metadata
are unchanged. Complete logs/direct exits and runtime identities are under `.runs/logs/TASK-00083/`.
This verification-prose follow-up retains the full gate through unchanged executable inputs and targeted checks.
Independent technical Review v3 **ACCEPT** and independent behavioral QA **PASS** (10/10 nonvisual scenarios)
cover exact candidate `e41550853361f57895922f6fddc15cc912b66ba4` against `d955d2f`; both have no findings.
TASK-00083 is done for accepted implementation and required local/behavioral verification, with no parent to close.
Land published [PR #136](https://github.com/johnnickell/fight-access-control/pull/136) against `develop`; its
administrative closeout retains the earlier full gate through verified input equivalence and current
planning/documentation/diff checks. Hosted CI is not an accepted TASK gate and no hosted conclusion is claimed.
No consumer Composer minimum, product behavior or consumer runtime changes are included. Local CLI verification
is not a demonstrated exploit, compromise, consumer web-runtime remediation/restart, PR approval/merge, release or
deployment.

## v0.8.0 publication — 2026-10-21

[v0.8.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.8.0) is published from certified
`main` release merge **`319738255e682d587642b67856039f882b9588d6`** through
[PR #134](https://github.com/johnnickell/fight-access-control/pull/134). Independent release-content review accepted
prepared head `fb328af`; independent documentation QA passed **8 scenario groups / 14 walkthroughs** with no findings.
The main merge preserves that exact tree. Fresh exact-main `./bin/release certify 0.8.0` passed **2399 tests /
45422 assertions**, exact **7452/7452 statements**, plus separate OpenAPI **16 tests / 659 assertions**, without
final warnings/skips or resolved dependency/lockfile changes.

Human-signed annotated tag object **`6d696c0ae5bb843366b601633fed3af3311b7a48`** and the remote peeled commit
match the certified merge. GitHub publication at **2026-10-21T18:19:50Z** is latest, non-prerelease and immutable,
with zero uploaded assets; the provider release attestation verifies that tag object and release identity.
Packagist normalized version **0.8.0.0** source/dist references match the certified commit. The
[release overview](docs/release-0.8.0.md) retains the breaking Role/recovery contracts and unexecuted consumer obligations.
Registry projection is not consumer installation, qualification, adoption, enablement or deployment.

Main-to-develop merge-back completed through [PR #135](https://github.com/johnnickell/fight-access-control/pull/135)
on 2026-10-21 at **`d955d2f88cf58a5696814adeebb609a64fff8ee9`**. Following John's cleanup request, verification
found both `release/0.8.0` and `release/0.8.0-merge-back` already absent locally and on origin, with no isolated
release worktree; no deletion actor or time is inferred. Release closeout is complete, with no release-owned action
remaining. The ignored `.runs/handoffs/release-0.8.0/ledger.md` retains actual integration/cleanup observations and
release evidence. The immutable tagged source retains its preparation wording; no tag rewrite or recertification
is needed for this prose-only reconciliation.
All implementation TASKs/parents are already terminal; the separate v1.0.0 stability decision is unchanged.

## Historical v0.8.0 release preparation — 2026-10-21

John selected v0.8.0 and the main checkout for `release/0.8.0` from clean, refreshed `develop`
`6ea05dc3270f1bd314fce9878940a07be528791e`, the merge of
[PR #133](https://github.com/johnnickell/fight-access-control/pull/133). The
[release overview](docs/release-0.8.0.md) covers the integrated operation-outcome, recovery, managed-policy and Role
revision contracts plus contributor/release-documentation tooling. The implementation TASKs have independent technical
acceptance and behavioral QA; no implementation TASK remains unfinished. PHP 8.5+ and Common ^1.3 remain unchanged.
This release preparation changes documentation only, not production code, tests, schemas, dependencies or tooling.

The preparation `./bin/build` passes **2399 tests / 45422 assertions**, exact **7452/7452 statements**, without
warnings/skips. Planning validates **118 records / 0 active**, documentation links and v0.8.0 metadata checks pass,
and local README previews cover light/dark desktop and narrow layouts. No dependency/lockfile drift occurred;
rebuilt image metadata changed with identical runnable Config/RootFS. This verification-prose follow-up retains that
full gate with unchanged executable inputs and fresh targeted checks; it is not a second build or final certification.

At this historical preparation checkpoint, v0.7.0 remained the latest verified published release. Independent
release-content acceptance, main integration, fresh exact-main certification, human signing, publication, merge-back
and owned cleanup were outstanding; verified subsequent publication is recorded above. The ignored `.runs/handoffs/release-0.8.0/ledger.md` owns resumable state and effect authority.
No actual consumer qualification, dependency adoption, data operation, enablement, restricted disclosure or deployment
is claimed. The separate v1.0.0 stability decision remains unchanged.

## Historical package entrypoint and release consistency — TASK-00082

John requested [TASK-00082](planning/tasks/00082-TASK.md) in the main checkout to reconcile merged planning
handoffs, shorten and brand the README, and add a version-consistency preflight to release certification.
The [release runbook](docs/releasing.md) owns the current-version inventory and publication distinction.
At that implementation checkpoint, v0.7.0 remained the latest verified published release and v0.8.0 was recommended
for the integrated breaking Role and recovery contracts, not yet selected. John's later selection is recorded above.
No package behavior or consumer qualification changes.
Independent technical Review **ACCEPT** and post-review behavioral **QA PASS** cover exact candidate
`3163026d07e82f1db6520b311aea8c72a0045f8e` against unchanged `develop` `d1ed655`: eight scenario groups,
29 CLI/guard cases, three browser state pairs and eight instruction walkthroughs pass with no findings.
TASK-00082 is done for accepted implementation and required verification; it is standalone with no parents to close.
The saved full gate passes **2399 tests / 45422 assertions**, exact **7452/7452 statements**, without final warnings/skips.
Local README previews do not prove hosted rendering, and isolated certification wiring is not a real release.
Land opened [PR #133](https://github.com/johnnickell/fight-access-control/pull/133) against unchanged `develop`,
with six publicly accessible QA Before/After captures verified against their digests. Final metadata push and
ready transition remain pending at this tracked checkpoint; the ignored TASK Land receipt owns final delivery facts. No merge, version selection, signed release, consumer qualification or deployment is claimed.

## Role caller revisions — TASK-00080 delivery checkpoint

[TASK-00080](planning/tasks/00080-TASK.md) completed its accepted Role caller-revision capability on
`feature/task-00080-role-revisions` from `develop` `f10ed5a`. The [Role composition](docs/role-revisions.md) supplies
authoritative View revisions, required rename/remove expectations, validated persisted incarnation/revision, all-writer
participation and final fences; existing membership, managed/reserved identity, tiers, registration integrity and outcome
policy remain intact. The [consolidated guide](docs/operation-outcomes.md) connects the correction slices.

Independent technical Review accepted and independent nonvisual QA passed the exact implementation candidate
`35eea6329b8f74e253521870734b11ece45d1988` with 7 scenarios / 48 checks and visual N/A for the nonvisual library.
Focused checks pass **565 tests / 11182 assertions**; the saved canonical `./bin/build` passes **2399 tests / 45422 assertions**,
exact **7452/7452 statements**, without final warnings/skips. No resolved package/lockfile drift occurred. This
administrative tracking closeout retains the earlier full gate through verified input equivalence and targeted checks,
not as a second full build.

TASK-00080 and its sole-child parent TICKET-00024 are done for accepted package implementation and required local/
behavioral verification; EPIC-00001 remains historically terminal. Package proof is not consumer SQL/transport/
permission qualification. Publication, PR approval, merge, release, deployment and restricted disclosure remain separate.

## Managed-policy consistency — TASK-00079 implementation checkpoint

John invoked Build for [TASK-00079](planning/tasks/00079-TASK.md) in the selected main checkout after TASK-00077
and TASK-00078 integrated. Engineer work is on `feature/task-00079-managed-policy` from clean `develop` `8ade1cd`.
The current [managed-policy composition](docs/operation-outcomes.md#managed-policy-composition) documents shared
planner wiring, read-only snapshots, final reference fences and transaction/publication outcomes. Existing policy
constraints, exact membership and consumer-owned authorization remain unchanged. No new API or persisted shape,
optional composition, Role caller revision, compatibility support or production adapter is introduced.

Focused policy/reference/outcome checks pass **215 tests / 2220 assertions**; canonical `./bin/build` passes
**2365 tests / 44985 assertions**, exact **7377/7377 statements**, without final warnings/skips. The normal resolver
updates ignored dev-only `sebastian/git-state` **1.0.0 → 1.1.0**; constraints and Common v1.4.0 remain unchanged.
Detailed approved criteria, regression chronology, early test/style/static-analysis/normalization failures and
interrupted attempts remain private. This prose-only summary retains that full gate through verified input
equivalence and current targeted checks.

Independent technical Review **ACCEPTED** and independent adversarial nonvisual QA **PASSED** the exact candidate;
their canonical private reports retain detailed evidence. Land opened
[PR #131](https://github.com/johnnickell/fight-access-control/pull/131) against `develop`; TASK-00079 is done for
accepted package implementation and required local/behavioral verification. Package modeling is not real consumer
SQL/adapter qualification; no consumer change, restricted disclosure, PR approval, merge, release or deployment is
claimed.

## Recovery lifecycle — TASK-00078 landing closeout checkpoint

John invoked Build for [TASK-00078](planning/tasks/00078-TASK.md) in the main checkout after TASK-00077 integrated.
Engineer work is on `feature/task-00078-recovery-lifecycle` from verified clean `develop` `a3b6eee`. Current
[recovery composition](docs/operation-outcomes.md#recovery-composition) documents the Domain/Application contract,
mandatory shared persistence fences, worker wiring and consumer qualification obligations. Detailed approved criteria
and execution evidence remain in the private maintainer handoff; no affected-release assessment is published here.

The current conformance binding supplies persisted User setup, shared transactional writes and reopened authority
state. Original persistence assertions are retained, with admission and rollback checks added. The saved canonical
`./bin/build` passes **2353 tests / 44831 assertions**, exact **7372/7372 statements**, without final warnings/skips;
focused recovery checks pass **28 tests / 790 assertions**. Failed attempts, including the repair's initial Rector
normalization failure, remain in ignored evidence. No package/lockfile drift occurred; PHP 8.5.7 and Common v1.4.0
satisfy unchanged requirements. The passing build preserves dependency bytes and runtime Config/RootFS. This
prose-only closeout retains that full gate through proven input equivalence and current targeted checks.

Independent technical Review **ACCEPTED** and independent adversarial nonvisual QA **PASSED** the exact candidate;
their canonical private reports retain detailed evidence. Land published
[PR #130](https://github.com/johnnickell/fight-access-control/pull/130) against `develop`; TASK-00078 is done for
accepted package implementation and required local/behavioral verification, and historical terminal parents remain
unchanged. No consumer adoption or qualification, PR approval, merge, release, deployment, restricted disclosure, or
hosted CI success is claimed.

## Contributor workflow alignment — TASK-00081 implementation checkpoint

The approved standalone [TASK-00081](planning/tasks/00081-TASK.md) aligns contributor routing with optional
Fight Agent OS/Pi workflows. [AGENTS](AGENTS.md) and [planning continuations](planning/CONVENTIONS.md#workflow-continuations)
recommend Build for approved implementation/confirmed repair and stop; explicit invocation delegates its
work, independent Review, QA and open-PR sequence. The [project binding](planning/agents/project-profile.md#agent-os-workflow-binding)
keeps provider resources separate from local planning, Docker runtime, gates, security and evidence rules.
The package remains self-contained for contributors without those tools.

At this implementation checkpoint, independent acceptance, behavioral QA and publication remain pending.
No correction-batch implementation, private brief disclosure, package/dependency/runtime change, end-to-end Build
qualification, merge, release or deployment is claimed. TASK-00077 remains the separate correction-batch entrypoint;
this general guidance chore does not require its restricted brief.

## Operation outcomes — TASK-00077 landing checkpoint

John invoked Build for [TASK-00077](planning/tasks/00077-TASK.md) in the main checkout, on
`feature/task-00077-operation-outcomes` from verified clean `develop` `8da8b3e`. The current
[operation-outcome guide](docs/operation-outcomes.md) describes notification, transaction and consumer composition.
Existing success/no-op, authorization, audit and Agent issuance-warning boundaries remain in scope for preservation.
No consumer code, Common dependency policy, transport API or persisted schema is changed.

Focused Application checks pass **1634 tests / 36950 assertions**; canonical `./bin/build` passes **2325 tests /
44043 assertions**, exact **7311/7311 statements**, without final warnings/skips. Ignored dev-only swagger-php
updates **6.17.1 → 6.18.0** through the normal resolver; dependency constraints and installed Common v1.4.0 are unchanged.
This prose-only verification follow-up retains that full gate with proven executable-input equivalence and current
targeted checks. Independent technical Review **ACCEPTED** and independent nonvisual QA **PASSED** the exact
candidate; their private canonical reports retain detailed evidence. Land published
[PR #129](https://github.com/johnnickell/fight-access-control/pull/129) against `develop`, and TASK-00077 is done
for accepted package implementation and required local/behavioral verification. No hosted acceptance gate is
declared and hosted CI was not checked. No PR approval, merge, consumer qualification, release or deployment is
claimed. Detailed approved criteria, inventory and execution receipts remain in the private maintainer handoff, not
public tracking. TASK-00078–00080 remain separate unfinished work.

## Consolidated correction planning

The approved [correction-batch map](planning/wayfinder/access-control-correction-batch-map.md) is Closed for
planning. TICKET-00001 owns [TASK-00077](planning/tasks/00077-TASK.md) for operation outcomes,
[TASK-00078](planning/tasks/00078-TASK.md) for recovery lifecycle and [TASK-00079](planning/tasks/00079-TASK.md)
for policy consistency. TICKET-00024 owns [TASK-00080](planning/tasks/00080-TASK.md) for Role caller revisions.
TASK-00078/00079 depend on TASK-00077; TASK-00080 depends on TASK-00077/00079. The relative order of
TASK-00078 and TASK-00079 is priority only. TASK-00077, TASK-00078 and TASK-00079 are complete and integrated;
TASK-00080 also completed independent acceptance and behavioral QA, and its PR #132 merged into `develop`
at `d1ed655` on 2026-10-21. The correction batch has no remaining implementation or merge handoff.

Public records summarize approved scope, ownership and sequencing. Full maintainer briefs and restricted evidence
remain private under SECURITY.md and must be obtained before implementation or independent review. No new behavioral
verification or consumer qualification is claimed. Next: `/skill:next` for Board-based selection; planning publication
does not authorize implementation, restricted disclosure, release or deployment.

## v0.7.0 publication — 2026-10-20

[v0.7.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.7.0) is published from the exact
`main` release merge `cf55a25ade9c36f99c9211f12e467446a5bb0c9d` through
[PR #124](https://github.com/johnnickell/fight-access-control/pull/124). Independent release-content review accepted
prepared candidate `911b5f6`; the merge preserved its tree. Fresh exact-main `./bin/release certify 0.7.0` passed
**2225 tests / 42779 assertions**, exact **7264/7264 statements**, with no final warnings/skips; separate OpenAPI
composition passed **15 tests / 614 assertions**. Human-signed annotated tag object
`79de80627128ace44c38fe7a80e063e7115335f0` and its remote peeled commit were independently verified.

At its publication checkpoint, GitHub publication at **2026-10-20T15:21:22Z** was latest, non-prerelease and immutable with zero uploaded assets;
the provider release attestation verifies the tag object. Packagist normalized version `0.7.0.0` has matching source
and dist references. This is registry projection, not installed-consumer qualification, adoption or deployment.
The [release overview](docs/release-0.7.0.md) retains the breaking contracts and unexecuted consumer obligations.

Reviewed merge-back into `develop` subsequently completed through
[PR #125](https://github.com/johnnickell/fight-access-control/pull/125) on 2026-10-20. Owned branch cleanup outcomes
remain in the ignored release ledger and are not inferred from the merge. At the original publication-reconciliation
checkpoint there were no package behavior, dependency, test or gate input changes;
retain the exact-main full gate through verified input equivalence and fresh documentation/planning checks.
EPIC-00013 and its children are already done; the separate v1.0.0 stability decision remains unchanged.

## Historical v0.7.0 release preparation — 2026-10-20

John selected v0.7.0 and the main checkout for `release/0.7.0` from clean, refreshed `develop`
`4ffc31b1d36272a2e7df9f2234fbccfce31371b6`, the merge of
[PR #123](https://github.com/johnnickell/fight-access-control/pull/123). The
[release overview](docs/release-0.7.0.md) covers the complete independently accepted registration capability:
TASK-00073 activation origin, TASK-00074 atomic default-Role registration and TASK-00075 administrator recovery.
EPIC-00013 and all children are done. This is a breaking pre-v1 minor with required persistence/repository,
provider-invocation and status-view changes; PHP 8.5+ and Common ^1.3 remain unchanged. No production, test,
schema, dependency-constraint or release-tooling change is part of this documentation preparation. The EPIC's
v1.0.0 target remains its historical pre-stability planning destination, not a selected stable release.

The preparation `./bin/build` passes **2225 tests / 42779 assertions**, exact **7264/7264 statements**, without
final warnings/skips. Planning validates **111 records / 0 active** and documentation links pass. No resolved
package or lockfile drift occurred; Composer regenerated ignored installed metadata and the rebuilt image retains
identical runnable Config/RootFS. This verification-summary follow-up retains that full gate with unchanged
executable inputs and targeted planning/documentation checks; it is not a second full build or final certification.

At this preparation checkpoint, independent release-content acceptance, release PR/integration, exact-main
certification, human signing and publication remain outstanding. The ignored
`.runs/handoffs/release-0.7.0/ledger.md` owns resumable evidence and effect-specific authority. The published v0.6.0
release and its merge-back through PR #120 were verified; earlier preparation checkpoints below are historical.
Package acceptance/publication does not qualify actual consumer DB, authorization, mail/templates, restart or
public-security behavior, enable registration, authorize dependency adoption or grant deployment permission.

## Administrator registration recovery — TASK-00075 landing checkpoint

John invoked Build for TASK-00075 in the selected main checkout after TASK-00074 integrated via PR #122.
Engineer work is on `feature/task-00075-registration-recovery` from verified clean `develop`
`8119bd841d66d5c797db0c83667dd7fe27e7da2f`. The Unreleased
[recovery guide](docs/registration-recovery.md) owns current command, authorization, persistence and evidence details.
`RecoverPendingRegistration` binds claimed actor, exact User, expected activation delivery ID and destination.
Mandatory `RegistrationRecoveryAuthorization` authenticates/adopts administrator authority for that exact target
inside the trusted transaction, valid through completion; consumers also protect every dispatch/query entry point.

Wrong-address correction and exact/post-expiry same-email reissue retain the pending User and exact Roles. Defaults
are not reread. `UserRepository::replacePendingRegistration()` compares complete expected User state and holds the
shared identity/claim fence through completion, even for same-email no-op validation. All User and activation
writers share transaction-duration fences. Predecessor revocation, fresh registration-origin activation/encrypted
delivery and audit commit atomically. Post-commit `PendingRegistrationRecovered` uses the existing subscriber/worker;
provider invocation remains outside transactions and does not activate the User. Original throwables survive safe
constant failure evidence and notification faults. Lost/uncertain responses resolve from persisted current state,
not inferred rollback or stale-command replay. Existing protected invitation status now exposes the required
`activation_delivery_id`; no new query, public existence probe or historical reader is added.

Controlled package tests cover both variants through delivery and single-use same-User activation, both uncertain
commit outcomes, response loss, both outer publishers failing, provider retry/restart/resend, both-winner pre-write
schedules, delayed provider outcomes and held-fence rollback/commit. Pending deletion remains unsupported; legal
activation/delete/restore produces a new private invitation and defeats stale recovery. Focused checks pass **295
tests / 4035 assertions**; the canonical gate passes **2225 tests / 42779 assertions**, exact **7264/7264**
statements (**393/393** in changed statement-owning classes), without final warnings/skips. The TASK retains early
setup/style failures and the first interrupted canonical attempt; the passing rerun has a direct exit-zero receipt.
No package/lockfile drift occurred; regenerated Composer metadata and rebuilt image metadata are recorded with
equivalent runnable Config/RootFS. Repair cycle 1 corrects Review F1's changed-class subtotal only; raw gate
artifacts and failed-attempt history remain unchanged. This prose correction retains the earlier full gate, not a
fresh full-build claim.

Independent technical Review **ACCEPTED** exact candidate `e3304547de6aa2cef82173481413b205dcc7d7f4` against
`develop` `8119bd841d66d5c797db0c83667dd7fe27e7da2f` with no current findings. Independent nonvisual QA **PASSED**
8/8 bounded real-contract scenarios with no failures or incompletes; visual evidence is N/A for this nonvisual
library. Land published [PR #123](https://github.com/johnnickell/fight-access-control/pull/123) against `develop`.
TASK-00075 is done for accepted package implementation and required local/behavioral verification; automatic parent
completion closes its sole-child TICKET-00023 and EPIC-00013. No hosted acceptance gate is declared and hosted CI
was not checked.

No real consumer DB/authorization/template/restart/public-security qualification, enablement, PR approval, merge,
release or deployment is claimed. Current logs/receipt and exact candidate mapping belong to
`.runs/logs/TASK-00075/` and `.runs/handoffs/TASK-00075/`.

## Optional User registration — TASK-00074 Engineer repair checkpoint

John invoked Build for TASK-00074 in the main checkout. Engineer implementation is on
`feature/task-00074-registration` from clean `develop` `5e5f436d` (TASK-00073 integrated through PR #121).
The Unreleased [registration guide](docs/user-registration.md) describes distinct `RegisterUser` / `RegisterUserHandler`,
required nonempty consumer RoleName defaults resolved on every command, and post-commit `UserRegistered`.
The command carries only new User ID/email, never authenticated actor claims, Roles, passwords or credentials.
Audit/worker source `registration` is fixed provenance, not authority. Failure evidence retains the safe command with
constant text and rethrows the original throwable even if notification fails.

Mandatory `UserRepository::addWithRoleAssignments()` validates the complete expected Role snapshots and initial
assignment set under a transaction-duration reference/name fence shared by all Role writers. Pending User/all Roles,
canonical-email claims/reservations, activation grant, encrypted registration-origin delivery and audit commit together
or not at all. Optional subscriber dispatch follows commit; due-work restart does not require registration replay.
Provider acceptance leaves the User pending; existing single-use activation activates that same User with its Roles.
Private invitations and other credential purposes retain their existing behavior.

Independent Review of initial `8b19ff7` requested F1/F2: the composed delivery worker copied storage diagnostics into
failure evidence and could replace the original throwable when failure notification threw. Two regression-first
subscriber/Common-bus/worker cases reproduced both defects. The bounded worker catch now uses constant
`User invitation delivery failed.` text and best-effort notification, preserving its original throwable. This applies
to registration and private invitation; transaction/provider behavior is unchanged. Tests assert safe attempted payloads,
throwable identity, committed pending User/all Roles/grant/audit and due-work recovery with the same delivery ID/origin.

Repair focused checks pass **696 tests / 9546 assertions**; fresh canonical `./bin/build` passes **2199 tests / 41974
assertions**, exact **7131/7131** owned statements (**106/106** in the repaired worker, **232/232** across all TASK-changed
production files). Final checks have no warnings/skips. Complete logs/direct exits, failed-attempt history and snapshots
are under `.runs/logs/TASK-00074/repair-1/`; the ignored Work handoff owns the committed mapping. No repair dependency
package drift occurred; Common remains v1.4.0 and swagger-php 6.17.1. Rebuilt runnable image Config/RootFS are identical.
Fresh independent technical re-review **ACCEPTS** exact candidate
`17e7237e0196ef7da8990f62c0ccdd7c0458aeb8` against unchanged `develop` `5e5f436d5d8ed4e37bb57bb58d7b3d1f09e6df29`;
canonical review v3 passes C1–C8, resolves F1/F2 and has no current findings. Independent nonvisual behavioral QA
**PASS** on the same candidate covers 10 selected scenarios (nine real-contract driver scenarios / 56 checks plus
29 delivery-regression tests / 198 assertions), with no findings or incomplete scenarios; visual QA is N/A for this
nonvisual PHP library/worker change. Canonical Review and QA SHA-256 values are respectively
`18a3bb179b03a924e35ba9ef5ba2197941742c3b81b1b78a97d8bf19859c98c0` and
`fcfe4adb543d1954adff8d81a1928511487af4115a9166356babfde611f70db2`.

TASK-00074 is complete for accepted package implementation and local/behavioral verification; automatic parent
completion closes sole-child TICKET-00022 in the same Land administrative operation. EPIC-00013 remains in progress
because TASK-00075/TICKET-00023 remain unfinished. Land published
[TASK-00074 PR #122](https://github.com/johnnickell/fight-access-control/pull/122) for human review; no hosted
acceptance requirement is declared and hosted CI is not checked. No consumer SQL, mail/templates, physical worker
restart or public-security qualification, enablement, approval, merge, release, adoption or deployment is claimed.
Historical planning checkpoints below do not override this execution authority.

## Activation origin — TASK-00073 implementation checkpoint

The Unreleased [activation-origin contract](docs/credential-delivery.md#activation-origin-v070) stores required
immutable `ActivationOrigin` on each `ActivationGrant`, shared by its owned delivery generation. Current values are
`private_invitation` and `registration`; creation/hydration has no default or historical reader. Complete-state
repository CAS compares origin in both operands and terminal predecessors. Issuance/hydration requires the typed
origin first; `CredentialDeliveryInvocation` requires it as the fifth argument (explicit null for reset/email change).
The worker passes the claimed stored origin outside transactions with unchanged activation purpose and delivery ID.
Consumers choose their own templates, never transient events/routes or User history.

Private issuance/correction remain private; correction rejects registration before mutation. Resend preserves the
current origin. Deleted-to-pending restoration starts a fresh private invitation even after registration. Controlled
package tests cover both origins through immediate invocation, modeled persistence restart/discovery, retry/lease
reclaim, successor/stale-worker fences, rollback, uncertain commits and post-commit publication/provider faults.
The repository conformance suite is consumer-bindable; the OpenAPI inventory adds a standalone origin enum, not a
serializable sensitive invocation or new public input/query. Reset/email-change purpose and safety remain unchanged.

TASK-00073's focused checks pass **465 tests / 7419 assertions**; canonical `./bin/build` passes **2139 tests /
41423 assertions**, exact **7038/7038 statements**, without final warnings/skips. The normal resolver updated only
ignored dev-only swagger-php **6.17.0 → 6.17.1**; PHP 8.5.7 and Common v1.4.0 satisfy unchanged package requirements.
The TASK and ignored Work handoff retain failed-attempt chronology, full log/exit and tested-content mapping.
Earlier full gate is retained for final verification-prose updates with input equivalence and targeted checks.
Independent technical Review **ACCEPT** and behavioral QA **PASS** now accept candidate
`899dcbd9abdb22df1ca359afaa173789190fc780` against unchanged `develop` `2edbd347`; TASK-00073 is done for
accepted package implementation and required local/behavioral verification. Its administrative Landing closeout and
PR publication remain separate from human approval/merge. This contract slice does not implement TASK-00074
registration/default Roles or TASK-00075 administrator registration recovery. No actual consumer SQL,
mail-template composition, worker process, public protection, adoption, release or deployment qualification is
claimed. Earlier self-registration planning checkpoints
below are historical; TASK-00074's later execution authority and current checkpoint are recorded above.

## v0.6.0 release preparation — 2026-10-19

John selected v0.6.0 and the main checkout for `release/0.6.0` from clean `develop` `cc364511`, the merge of
[PR #118](https://github.com/johnnickell/fight-access-control/pull/118). The
[release overview](docs/release-0.6.0.md) covers TASK-00076's independently accepted expiry-aware invitation retry
and required fourth-argument Clock. This is a breaking pre-v1 minor; PHP 8.5+ and Common ^1.3 remain unchanged.
No production/test/dependency change is part of this release preparation. Optional self-registration remains
separate planned v1.0.0 work, not included behavior or a v0.6.0 readiness prerequisite.

The preparation `./bin/build` passes **2122 tests / 41004 assertions**, exact **7021/7021 statements**, with no
final test warnings/skips. Its normal resolver updates only ignored dev dependency swagger-php **6.16.0 → 6.17.0**;
Common remains v1.4.0 within unchanged ^1.3. Planning validates **111 records / 7 active** and documentation links
pass. This evidence-only checkpoint update retains that full gate with unchanged executable inputs and targeted
checks. The ignored release handoff records the tested snapshot, direct exit, runtime and dependency identities.

At this preparation checkpoint, release-content acceptance, release PR/integration, exact-main certification,
human signing and publication remain outstanding. The ignored `.runs/handoffs/release-0.6.0/ledger.md` owns
live preparation/delivery evidence. Package verification does not qualify actual PostgreSQL/provider/clock behavior
or authorize consumer source/lock adoption or deployment. Earlier no-version-selected and publication-pending
checkpoints below are historical; v0.5.0 was published on 2026-10-06 and merged back through PR #115.

## Expiry-aware invitation retry — TASK-00076 accepted

[Standalone bug TASK-00076](planning/tasks/00076-TASK.md) repairs the invitation-recovery blocker reported by
Agent OS TASK-00042 against v0.5.0 (`46ffcf75f1e14dbc5b58a5049e05a600ce5cf8a0`). John approved the three decisions
and main checkout before implementation: existing coarse safe typed failure remains primary through notification
failure; one fresh post-read transactional admission sample, not a commit/completion deadline; required Clock with
breaking pre-v1 minor classification and no fallback. No exact release was selected at that implementation checkpoint;
the subsequent v0.6.0 preparation is recorded above.

The retry handler now consults the grant's existing usability predicate before transition/CAS/audit. Exact/post-expiry
rejection preserves persisted state, audit and delivery identity; the safe not-retryable exception remains caller-mappable.
Valid retry preserves authority and history. Existing worker expiry, complete-state CAS, rollback, post-commit delivery
and at-least-once semantics remain. Expiry after admission can still fail the subscriber after the retry commit;
no rollback of that commit is implied. Expired invitations need explicit fresh-grant resend, not implicit cleanup or
TTL extension. [Delivery composition](docs/credential-delivery.md) documents required constructor wiring and timing.

Regression-first package evidence and gate receipts belong to the TASK: focused **299 tests / 5185 assertions** and
canonical full gate **2122 tests / 41004 assertions**, exact **7021/7021 statements**, pass. The canonical resolver
updated ignored local Common to **v1.4.0** within unchanged `^1.3`; no new API/minimum is used. Initial test-formatting
and setup failures are retained, with no final test warnings/skips. Independent technical review **ACCEPT** and
behavioral **QA PASS** cover candidate `0cd2afab1728f12d332bd2f70bd15b2d1baa5e8f` against unchanged `develop`
`e7a9e32ca91858c90b5be024ddf7955e96751a14`, with no outstanding findings. QA passed nine independent package
scenarios; visual evidence is N/A. TASK-00076 is done for accepted implementation/verification, not publication,
merge or release; this standalone bug has no parent to close. Land retains the earlier full gate only with verified
unchanged executable/build/runtime/dependency inputs and current targeted checks under the Testing standard.
Authorized Land created [PR #118](https://github.com/johnnickell/fight-access-control/pull/118) against unchanged
`develop`, initially open/draft at administrative head `6e43f7c`; sanitized inline Before/After/QA evidence is published
and read back. Final metadata gates/push and ready transition remain pending at this tracked checkpoint. Hosted CI
is not checked. The ignored Land receipt owns final delivery facts and the administrative-only provenance bridge;
canonical Review/QA remain unchanged. Main checkout, branch, shared image and useful evidence are retained.
Earlier harness
write denials left the reusable Work handoff and summary receipt in permitted
`.runs/tasks/TASK-00076/runs/` alongside the complete log/result/snapshot bundle in `.runs/logs/TASK-00076/`. This is
inconsistent recovery state, not expired-credential activation. Controlled package tests do not qualify the consumer's
actual PostgreSQL/shared connection, physical clock or provider deduplication. Tagged-release qualification and deliberate
consumer adoption remain separate; no Agent OS source/lock update, release or deployment is authorized.

## Optional self-registration planning for v1.0.0

John requested a separate opt-in User self-registration track before v1.0.0, without enabling it by default in
fight-agent-os or starters or changing the v0.5.0 feature set. The closed
[Wayfinder map](planning/wayfinder/self-registration-v1-map.md) records John's decision that consumer projects own
registration exposure/enablement; AccessControl supplies use-case operations without an installation-enable gate.
John chose consumer-configured RoleName strings at registration handler construction, resolved by name for automatic
assignment. The list must be nonempty; missing Roles or `ROLE_SUPER_ADMIN` reject the whole registration without
leaving a pending User or delivery. [WF-026](planning/wayfinder/tickets/WF-026-registration-admission-and-default-authority.md)
is Closed: pending User, default Roles, activation grant/delivery and audit commit atomically with canonical-email
and Role-reference safety; email invocation follows commit. Closed
[WF-027](planning/wayfinder/tickets/WF-027-registration-delivery-origin.md) reuses activation with durable
registration/private-invitation origin exposed to the consumer provider across recovery and resend. Distinct
administrator registration recovery retains the pending User and Roles for wrong-address correction or fresh
activation after expiry at the same email; restoring a deleted User to pending is a new private invitation.
Consumer projects own the templates for both messages. Closed
[WF-028](planning/wayfinder/tickets/WF-028-public-registration-safety-boundary.md) retains existing duplicate-email
rejection without package generic success; consumers own public throttling, challenges, logging and response mapping.
All three decisions are Closed. John approved the single destination now written as
[EPIC-00013 — Optional User Self-Registration and Registration-Origin Delivery for v1.0.0](planning/epics/00013-EPIC.md).
The map is Closed; John approved three requirement areas, written as
[TICKET-00021](planning/tickets/00021-TICKET.md) (durable activation-delivery origin),
[TICKET-00022](planning/tickets/00022-TICKET.md) (atomic default-Role registration) and
[TICKET-00023](planning/tickets/00023-TICKET.md) (administrator pending-registration recovery). John then
approved one TASK per TICKET: [TASK-00073](planning/tasks/00073-TASK.md) for durable origin/provider recovery →
[TASK-00074](planning/tasks/00074-TASK.md) for atomic default-Role registration →
[TASK-00075](planning/tasks/00075-TASK.md) for authorized administrator recovery. All planning phases are
complete; consult the Board for execution selection. No implementation, release or consumer integration is
authorized by this handoff.

## v0.5.0 release preparation — 2026-10-06

John selected v0.5.0 and the main checkout for release preparation from clean `develop` `4163e58`, which includes
merged PR #113 and all preceding package implementation. The [release overview](docs/release-0.5.0.md) brings
recoverable Agent operations, current-contract cleanup, human expiry, Features and Agent-protected profile Tools
into one breaking pre-v1 minor. PHP 8.5+ and Common ^1.3 are required. No production, test or dependency policy
change is part of this preparation; public guidance and release notes are aligned with accepted implementation.
The preparation `./bin/build` passes **2109 tests / 40671 assertions**, exact **7019/7019 statements**, with no final
warnings/skips or resolved dependency changes. Planning validates **103 records / 0 active** and documentation links
pass. Subsequent prose-only cleanup retains the full gate with verified input equivalence and targeted checks.
This is not final-main release certification or independent acceptance of the release documentation.

At this preparation checkpoint there is no v0.5.0 release PR, final-main certification, signed tag or publication.
Independent release-content acceptance and separately authorized delivery remain required. The ignored
`.runs/handoffs/release-0.5.0/ledger.md` owns resumable live delivery facts; earlier TASK publication/pending notes
below retain their historical checkpoint meaning. Current Board records are all terminal. Actual consumer
qualification, dependency adoption, data operations and deployment are not established by package acceptance.

## Self-service Agent profile Tools — TASK-00071 accepted

John selected the clean main checkout from `develop` `40168bc` on `feature/task-00071-agent-profile-tools`.
At that checkpoint the [profile Tool guide](docs/agent-profile-tools.md) defined Application `Agent\\Tool\\GetAgentProfileTool`
(`agent.profile.read`) and `UpdateAgentProfileTool` (`agent.profile.update`), sharing the same request-scoped provider,
signed request and correlation as TASK-00035 availability. Distinct read/update Permissions precede protected work;
no caller target/authority fields are accepted. Read uses TASK-00070's fresh query. Update derives target and typed
initiator solely from the principal, normalizes through `AgentName` and requires Common `SynchronousCommandBus` for
TASK-00069's void command. Both outputs are exactly `{agent_id, name}` with derived JSON text. The update acknowledgement
is successful input, not latest state; no compensating read or second fact. Expected failures bind to constants through
`AgentProfileToolFailures`; unknown faults use Common's safe responder boundary. Post-commit publication failure has no
success acknowledgement or rollback claim. Consumers sanitize diagnostic/event logs and protect all alternate paths.
Common remains installed v1.3.0 at the recorded revision; no dependency policy or persisted contract change is needed.
Builder tests cover Tool behavior and bounded real Common/package composition over controlled ports, not a consumer
MCP runtime/database/seed qualification. Focused checks pass **90 tests / 843 assertions**; the canonical full gate
passes **2109 tests / 40671 assertions**, exact **7019/7019** owned and **19/19** new statements. No final warnings/skips
or dependency package changes; ignored Composer metadata regenerated and the rebuilt image retains identical runnable
Config/RootFS. The TASK owns the receipt and corrected early fixture/style failures. Final evidence prose retains
equivalent executable inputs plus targeted checks. Independent technical review subsequently **accepts** clean
`a160c95` against unchanged `develop` `40168bc`, with C1–C5 passing and no findings. Independent behavioral **QA PASS**
covers six groups, **36 executable cases / 437 checks**, **10 instruction walkthroughs** and fresh **90 tests / 843
assertions**. QA-only initial envelope-assertion and deprecated-option issues were corrected in its disposable harness;
final runs have no warnings/skips/deprecations, and no product defect was identified.

TASK-00071 is done for accepted package implementation and required local/behavioral verification. Automatic parent
completion closes TICKET-00019 and EPIC-00011 in the same operation; TASK-00070 and TICKET-00018 were already done.
Fresh landing focused/full gates pass **90 tests / 843 assertions** and **2109 tests / 40671 assertions**, exact
**7019/7019** statements, without final warnings/skips or dependency package drift. John authorized
[PR #113](https://github.com/johnnickell/fight-access-control/pull/113), open/draft against unchanged `develop` at initial
closeout head `d94b607`. Sanitized nonvisual QA evidence is published/read back and publicly accessible; final metadata
gates/push and ready transition remain pending at this tracked checkpoint. The ignored
`.runs/logs/TASK-00071/landing/` receipt owns final delivery and the administrative-only acceptance/QA/runtime bridge.
Canonical reports remain unchanged. Hosted CI is optional/not checked. The main checkout and useful evidence are retained; no isolated TASK
worktree or persistent service exists. Human approval/merge, consumer qualification, release and deployment remain separate.

## Minimal Agent profiles — TASK-00070 accepted

John selected the clean main checkout from `develop` `2ee2597` on `feature/task-00070-agent-profile`.
The unreleased [profile guide](docs/agent-profile.md) defines `GetAgentProfile` and its read-only handler, returning
immutable `AgentProfileView` with exactly `{agent_id, name}` from each fresh authoritative ACTIVE Agent lookup.
Missing and revoked targets both return null; operational failures propagate for consumer boundary sanitization.
No administrative projection, Permission resolution, name cache, mutation, transaction or event is used. Explicit
query target identity grants no authority: consumers protect every entry point, and TASK-00071 must derive the
self-service target solely from the current authenticated Agent, enforce its Permission and reject caller targets.

`AgentProfilePermissions::definitions()` supplies reusable `AGENT_PROFILE_READ` and `AGENT_PROFILE_UPDATE` managed
`ADMIN_SAFE` definitions from distinct consumer-owned stable IDs, without persistence or grants. Consumers generate
two `Uuid::comb()` values once, fix them in their seed/migration, and include definitions/code references in their
complete managed policy; neither startup nor reconciliation regenerates identities. Package fixtures do not qualify
actual persistence, seeding, entry-point security or runtime composition. Focused checks pass **32 tests / 213
assertions**; the complete local gate passes **2080 tests / 40412 assertions**, exact **7000/7000** owned statements
and **34/34** new statements, without final warnings/skips. The TASK records the initial TTY failure, corrected
style/type issues, interrupted first build and normal ignored dev-only Rector **2.6.7 → 2.7.0** update; the successful
rerun has no further dependency drift. Final evidence prose retains equivalent executable inputs with targeted
planning/documentation checks. Independent review of `d75db98` requested F1, removal of constructor/private-property
layout assertions. The bounded test-only revision preserves all behavioral assertions and passes fresh focused
**31 tests / 210 assertions** and full **2079 tests / 40409 assertions**, still exact **7000/7000** owned and **34/34**
new statements, without warnings/skips or dependency package changes. The TASK owns the fresh receipt and Composer
metadata regeneration note. Independent re-review subsequently **accepts** clean `915323b` against unchanged
`develop` `2ee2597`, C1–C4 passing, F1 resolved and no remaining findings. Independent behavioral **QA PASS** covers
six groups, **27 executable cases / 116 checks**, **8 instruction walkthroughs** and fresh **31 tests / 210 assertions**.
The initial QA-only retired PHPUnit-option deprecation was corrected in its invocation; final runs have no issues.

TASK-00070 is done for accepted package implementation and required local/behavioral verification. Automatic parent
completion leaves TICKET-00019 and EPIC-00011 `ready-for-agent` for unfinished TASK-00071; its dependencies are now
satisfied without removing their edges. Fresh landing focused/full gates pass **31 tests / 210 assertions** and
**2079 tests / 40409 assertions**, exact **7000/7000** owned statements, without final warnings/skips or package drift.
John authorized [PR #112](https://github.com/johnnickell/fight-access-control/pull/112), open/draft against unchanged
`develop` at initial closeout head `229638a`. Sanitized nonvisual QA evidence is published/read back in the body;
final metadata gates/push and ready transition remain pending at this tracked checkpoint. The ignored
`.runs/logs/TASK-00070/landing/` receipt owns final delivery and the administrative-only acceptance/QA/runtime bridge.
Canonical reports remain unchanged. Hosted CI is optional/not checked. Main checkout and useful evidence are retained; no isolated TASK worktree or persistent service exists.
No MCP Tool, administrator endpoint, consumer migration, human approval/merge, release or deployment is claimed.

## Agent-protected MCP authorization — TASK-00035 accepted

John selected the main checkout for TASK-00035 from clean `develop` `4b6d083`, on
`feature/task-00035-agent-mcp-authorization`. At that checkpoint the
[MCP authorization guide](docs/agent-mcp-authorization.md) recorded three additive public Application contracts
(now relocated by TASK-00108): repeatable `RequiresAgentPermission` on Tool `handle()`, immutable
`AgentToolPermissionCatalog` built directly from one Common registry, and request-scoped `AgentToolAvailability`.
The catalog enumerates complete Common-resolved metadata without a second Tool list/naming algorithm; exact immutable
definition identity rejects foreign mappings. Missing/malformed requirements reject construction. One lazy provider
resolution, successful or rejected, stays request-local; only static metadata crosses requests. Common sees only bool
and retains filtering, selection, concealment and protected retry mechanics. Later requests get fresh authority;
same-request admitted work retains its snapshot. Consumers retain transport mapping, diagnostics at their authentication
boundary, fresh object lifetimes, persistence, cryptography and runtime protections. No self-profile Tool is supplied.

Composer now requires Common `^1.3`; installed v1.3.0 still matches the recorded revision. The public additions and
narrowed dependency minimum are unreleased; no compatibility shim or consumer migration is added. Real Common-boundary
package tests cover filtering/pagination, deny-before-validation/buses/progress/diagnostics and protected confirmation
retry after modeled revocation/rotation/Permission changes. HMAC/database/state-protector/confirmation ports remain
controlled fixtures, not real consumer qualification. Final focused checks pass **44 tests / 374 assertions**; the
canonical full gate passes **2069 tests / 40356 assertions**, exact **6966/6966 owned statements** and **36/36 new
statements**, with no final warnings/skips or resolved package drift. The TASK owns the complete receipt and early
fixture/style failure chronology. Final evidence prose retains the full gate through unchanged executable-input hashes
and targeted planning/documentation checks. Independent technical review subsequently **accepts** clean `38ca186`
against unchanged `develop` `4b6d083`, all C1–C8 passing with no findings. Independent behavioral **QA PASS** covers
six groups, **17 executable cases / 282 assertions**, eight labeled instruction walkthroughs and fresh **64 tests /
626 assertions**. Probes include exact-registry mismatch, stale discovery cursors, same-request/fresh-request authority,
unknown/denied public equality and protected retry/replay. No product defect or final warnings/skips were found;
controlled ports remain package evidence, not actual consumer qualification.

TASK-00035 is done for accepted package implementation and required local/behavioral verification. Automatic parent
completion closes TICKET-00006 and EPIC-00005 in the same operation. TASK-00071 retains its satisfied dependency
edges and still waits on TASK-00070. Fresh pre-publication landing checks pass **64 tests / 626 assertions** and
**2069 tests / 40356 assertions**, exact **6966/6966 statements**, with no warnings/skips or dependency package changes.
John's land invocation published [PR #111](https://github.com/johnnickell/fight-access-control/pull/111) against unchanged
`develop`, open/draft at initial head `b81e73f30e5f4b6143e765effc689816036f376a`. Sanitized nonvisual QA scenarios,
responses and state/effect evidence are published in the body and read back. Final PR-metadata gates/push and ready
transition remain pending at this tracked checkpoint. The ignored TASK landing receipt owns final delivery and the
administrative-only review/QA/runtime bridge; canonical reports remain unchanged. Hosted CI is optional/not checked.
Human approval, merge, signed package release, consumer adoption and deployment remain separate.

## MCP planning readiness — Fight Common v1.3.0 (prior checkpoint)

John requested a planning refresh after Common v1.3.0 publication. The local ignored lockfile and installed package
metadata already resolve `v1.3.0` at `7de6cad6e8a9752973ad9f8e27e285b0c1510582`; no dependency change was needed.
[TASK-00035](planning/tasks/00035-TASK.md#verified-fight-common-v130-contracts) owns the inspected method-level
`McpToolInfo`, canonical `McpToolRegistry`, boolean availability, discovery/invocation/protected-retry and safe
output/failure signatures. This satisfies the former external Common gate, not AccessControl implementation or
consumer qualification. TASK-00035 and TASK-00071 are now `ready-for-agent`; TASK-00071 waits on unfinished
TASK-00070 and TASK-00035, retaining its satisfied TASK-00069 edge. TICKET-00006/00019 and EPIC-00005/00011 are
ready for agent work, not complete. Their earlier `needs-info` checkpoints below are historical.

No priority was changed: order 35 now puts TASK-00035 before TASK-00070 in the generated Ready Frontier. The Board
owns execution selection; all Wayfinder decisions remain Closed and decomposition remains complete. Implementation
must recheck dependency drift, declare a sufficient Composer minimum for new v1.3 API use, prove behavior and pass
the full local gate plus independent review/applicable QA. John's branch/commit/push/PR request covers this
planning-only refresh, not implementation, merge, release or deployment.

## Human delivery expiry — v0.5.0 readiness prerequisite

John requires package-owned expired-work discovery and direct expiry cleanup before `v0.5.0` readiness; see the
[bounded Wayfinder map](planning/wayfinder/human-credential-delivery-expiry-map.md) and
[WF-025 approved contract decision](planning/wayfinder/tickets/WF-025-human-delivery-expiry-contract.md). Before TASK-00072,
source tracing confirmed due discovery excludes exact/post-expiry work, reset had a direct expiry handler, and invitation
lacked one. John approved invitation/reset delivery expiry and full email-change authority/reservation expiry, including
already-delivered/permanent-failure/material-free delivery and changed User account states. Disable/delete preserve
reservations while pre-TASK-00072 User expiry required active state; the bounded implementation closes that omission
without reactivation or unrelated authority changes. New expiry discovery is read-only/secret-free with limit 1–100
and runner guidance 50; complete state/race/restart evidence is required. John confirmed the single destination,
now written as [EPIC-00012](planning/epics/00012-EPIC.md), and the Wayfinder map is Closed. John approved its sole
requirement area, now [TICKET-00020](planning/tickets/00020-TICKET.md); TICKET decomposition is complete with every
WF-025 case retained. John approved the sole implementation slice, now
[TASK-00072](planning/tasks/00072-TASK.md); all planning phases are accepted and complete. The Board owns execution
selection; existing priority is unchanged. John explicitly selected TASK-00072, chose the main checkout and requested
inclusion of pending planning changes in its commit. Implementation proceeded on `feature/task-00072-credential-expiry`,
preserving checkpoint `59f2378` and unrelated Agent-profile records. New bounded expired discovery/direct invitation expiry,
full email expiry in every reachable User state and a mandatory grant-bound reservation revision are described in
[the current expiry guide](docs/credential-expiry.md). Reclaimed-history and inactive-reservation regressions reproduced
and now pass. At the pre-publication checkpoint, focused checks pass 206 tests / 2957 assertions; `./bin/build` passes
1993 tests / 37644 assertions, exact 6841/6841 product statements and 971/971 changed-production statements, with no
final warnings/skips. The TASK owns source-input/commit evidence and every matrix mapping. Independent technical
review of `8a96e2b` requested F1–F3: missing interleaved replacement/coupled-email/two-worker and distinguishing discovery
order evidence, plus six new docblocks; it demonstrated no sampled runtime defect. The revision adds deterministic
public-handler/reference-CAS schedules and real mixed-family ordered page/drain tests, corrects the initial handoff's
race overclaims and fixes only those docblocks. Revision focused checks pass 357 tests / 5780 assertions; fresh
`./bin/build` passes 2019 tests / 39818 assertions and exact 6841/6841 owned statements (971/971 across TASK-changed
production). No final warnings/skips or dependency drift; the TASK owns failed-attempt chronology and fresh provenance.
Independent re-review subsequently **accepted** clean implementation
`9bdf3822eb5f97505cca8bc76f2f1a14dc5d28d0` against unchanged `develop` `7ca8eec`, with F1–F3 resolved and no
remaining findings. Independent post-review behavioral **QA PASS** covers six scenario groups, **86 independent driver
cases / 795 checks** and fresh **357 conformance tests / 5780 assertions**, with no final warnings/skips or product defect.
TASK-00072 is done for accepted package implementation and required local/behavioral verification; automatic parent
completion closes TICKET-00020 and EPIC-00012 in the same administrative operation. John's land invocation authorizes
non-force PR publication and opened [PR #108](https://github.com/johnnickell/fight-access-control/pull/108) against
unchanged `develop`, draft at initial head `51a194100bca6fb62032e6d0589ef917d50a7f72`. Sanitized nonvisual QA evidence
is published/read back in the body; final PR-metadata verification and ready state remain pending at this tracked checkpoint.
The ignored TASK landing receipt owns fresh gates, the administrative-only review/QA bridge and final commit/remote
identity; original canonical reports remain preserved. Hosted CI is optional/not checked. The package expiry prerequisite
is satisfied, not release certification, approval, merge or actual consumer qualification. Agent OS `TASK-00024` is an external
consumer context ID, not an upstream TASK or local dependency. Explicit checkout/worktree
placement, implementation gates and independent review/behavioral QA precede the consumer handoff. Real PostgreSQL
qualification, adoption, tagging/signing/publication and deployment remain separate. Preserve concurrent Agent-profile planning changes.

## Current integration and evidence — TASK-00059 accepted

The [integration guide](docs/agent-integration.md) now connects the complete current public composition, finite
defaults, outcome handling, writer/admitted-call fences and restoration prerequisites. The
[scenario inventory](docs/agent-operation-evidence.md) retains all 25 pinned scenarios plus ratified D3/D4, with
current assertion references, tested-content provenance and explicit real-consumer gaps. Prior legacy/upgrade
requirements are superseded under ADR 0011, not missing tests or claimed migration passes. TASK-00068's accepted
removal supplies revised M1; TASK-00058's accepted package restoration supplies M4.

John selected the main checkout from `develop` `37a98f3` on `feature/task-00059-current-contract-evidence`.
Independent review accepted this documentation-only implementation at `ed516f9` against unchanged `develop`
`37a98f3`, with C1–C10 passing and no findings. Independent QA passed eight scenarios, **282 distinct probe checks**
and **239 conformance tests / 8707 assertions**, including executable package probes and labeled instruction
walkthroughs. TASK-00059 is done for accepted implementation/local verification; M5 package guidance is complete.
No runtime/API/test/dependency change or consumer run is introduced. Focused Agent/OpenAPI checks pass
**1103 tests / 30421 assertions**; the accepted candidate's complete gate passes **1675 tests / 34503 assertions**,
exact **6218/6218 owned statements**. The TASK retains the initial optional JUnit reporter failure. John authorized
landing and published [PR #98](https://github.com/johnnickell/fight-access-control/pull/98) against unchanged `develop`
at initial head `6939d4a`; it is open, not merged. Fresh landing focused/full gates retain the counts above. Its ignored
landing handoff owns the administrative-only review/QA bridge and final metadata/remote identity; sanitized nonvisual
QA evidence is in the PR body. Package tests and
consumer-bindable suite availability do not establish actual database concurrency, key/sink durability, trusted
restore, enrollment activation or broker-use authority. The v0.5.0 target remains unreleased; no Agent OS
TASK-00138 closure, dependency adoption, package publication or deployment is claimed. Earlier checkpoints below are history.

## Restoration safety — TASK-00058 independently accepted

John authorized TASK-00058 in the main checkout from `develop` `80e9add` on
`feature/task-00058-restoration-safety`. The existing `AgentOperationContract` now requires explicit nullable
`reconciledGeneration` from the trusted admission boundary outside the restored dataset. It must attest the exact
active storage incarnation and complete reconciled history under the cohort fence; missing/mismatched evidence
makes unsafe paths unavailable. Controlled restore invalidates readiness before replacement, and successful
reconciliation advances generation so old delivery/cleanup acknowledgements cannot complete. No production restore
workflow, historical migration or automatic rollback detector is introduced.

The [restoration guide](docs/agent-restoration-safety.md) defines evidence provenance, quiesce/forward-repair/resume
conditions and mandatory consumer rehearsals. Consumer-bindable tests restore modeled package state while retaining
external sink receipts/tombstones/high-water and a separate recovery journal. They prove guarded public outcomes,
not real database/tooling restore, actual writer exclusion or activation/use authority. The complete local gate passes
**1675 tests / 34503 assertions**, exact **6218/6218 owned statements**; focused Domain/cohort/restoration checks pass
**302 tests / 11891 assertions**. No final warnings/skips or dependency drift. The TASK owns the receipt, input manifest
and failure chronology. Independent review accepted `eeb6f5f` against unchanged `develop` `80e9add`, with all C1–C8
passing, no findings, **1095 fresh tests / 30016 assertions** and all **782/782** final build inputs verified.
TASK-00058 is done for accepted package implementation/local verification. John's landing request published
[PR #96](https://github.com/johnnickell/fight-access-control/pull/96) against unchanged `develop` at initial head
`65f12b3`; it is open at this publication checkpoint. Fresh landing focused/full gates retain the counts above and
exact coverage; the ignored TASK landing handoff owns the administrative-only bridge and final metadata/remote
identity. Hosted CI is optional/unchecked; interactive QA is N/A. No consumer adoption, merge or release is claimed;
TASK-00059 final integration and real consumer qualification remain separate.

## Current contract — TASK-00068 implementation

John's package-wide 2026-09-30 decision in [ADR 0011](planning/adr/0011-pre-v1-current-contract-only.md) supersedes
all earlier requirements to support old APIs, formats, legacy Agents or upgrades/migrations while pre-v1.
[TASK-00068](planning/tasks/00068-TASK.md) now removes the legacy Agent model, recovery marker/View/schema field,
adoption transition, raw aggregate rotation and retired lifecycle rotation stub. Every current lifecycle replacement
requires original-operation cancellation; validated hydration preserves authority without inventing issuance.
See the [current Agent contract](docs/agent-current-contract.md).

[User credential delivery](docs/credential-delivery.md) requires explicit due time, claim token, claim/lease and outcome
times, and retry failure classification. Historical inference, purpose-specific material predicate aliases and the
obsolete transport `ConfirmPasswordResetDelivery` command/handler/schema are removed. Delivery workers still emit
`PasswordResetDeliveryConfirmed` after a live-claim outcome commits. `ManagedPolicyPlanner` now requires its Agent
repository; no older constructor composition remains. Current protected-tier checks are unchanged.

Authentication/authorization, hydration, atomicity, retry/restart, retained-key/order/receipt/tombstone and restoration
safety remain. TASK-00058 covers current-contract stale-state restoration; TASK-00059 supplies final integration
and evidence, not a migration route. No data reset, release, consumer qualification or deployment follows.
Independent review accepted `90c22b5` against unchanged `develop` `668ef52`, with all C1–C7 passing, no findings,
**1227 fresh tests / 24149 assertions** and all 778 final-manifest entries verified. TASK-00068 is done for accepted
implementation/local verification. The local `./bin/build` passed **1496 tests / 25805 assertions** with exact
**6217/6217** owned statements; TASK-00068 records the receipt and package-wide removal/retention accounting.
John's landing request published [PR #95](https://github.com/johnnickell/fight-access-control/pull/95) against
unchanged `develop`; it is open at the initial publication checkpoint. Fresh landing gates retain the same complete
test/coverage counts. The ignored TASK landing handoff owns the metadata-only provenance bridge and final remote
identity. No approval, merge or release is claimed.
Earlier acceptance receipts and delivery checkpoints below are historical, not acceptance of TASK-00068.

## Prior canonical-contract simplification — TASK-00057

On 2026-09-30 John confirmed no consumers or persisted Agent operations require historical/backward compatibility.
[TASK-00057](planning/tasks/00057-TASK.md) is independently accepted on the existing feature branch. The revised code keeps
Unicode-aware normalization as the sole canonical contract and marker `2`, removes historical readers/version
selection and uses no-argument request canonicalization. Cohorts validate one canonical marker, not creation/reader
sets. Same-contract idempotency, retained keys/tombstones, authorization, atomicity and failure/order fences remain.
Fresh independent review accepted `2de4104` against unchanged `develop` `f0d872c`, with all revised C1–C8 passing,
no findings, 941 fresh tests / 21822 assertions and all 771 full-gate inputs verified. Prior acceptance at `04a2adf`
and the old PR #93 publication prove only superseded scope. The full local gate passes 1469 tests / 26053 assertions
and exact 6315/6315 statements. John requested landing of the revision through existing PR #93; final publication is
pending at this closeout checkpoint. The TASK's ignored simplification landing handoff owns fresh gates and final
commit/remote metadata. TASK-00058/00059 retain restoration/final-guidance ownership and their own execution authority;
no merge, release or consumer qualification is claimed.

## Vocabulary

- **User**: the stable human identity whose canonical email remains unique across pending, active, disabled, and
  deleted states.
- **Grant**: a purpose-bound, hashed, expiring, single-use credential for activation, password reset, or email
  change. Reissue revokes its predecessor.
- **Recoverable credential delivery**: package-owned activation, password-reset, or email-change work whose encrypted
  material commits with its grant. Workers discover secret-free due state, commit an exact leased claim, invoke a
  provider outside every transaction with the stable delivery-generation ID, then commit one typed expected-state
  outcome. Expired leases may repeat invocation, so the guarantee is at-least-once rather than exactly-once.
- **Refresh session**: authoritative server-side session state owning credential rotation, revocation, activity,
  lifetime, authentication version, and coarse device information.
- **Authenticated principal**: an immutable framework-neutral identity and authorization snapshot revalidated
  against authoritative storage once per request.
- **Agent**: a machine principal with direct Permission authority; it is not a User, refresh session, framework
  security user, or AI persona.
- **Agent credential**: one active HMAC authority belonging to an Agent. It has a public credential ID and a
  consumer-encrypted shared secret; the raw secret exists only when issued or during authentication verification.
- **Agent credential operation**: a caller-scoped, versioned request binding retaining original issuance metadata
  and a separately encrypted prepared delivery copy. Retrying an authorized retained key resolves that outcome;
  retiring its material never makes the key reusable. Current unreleased implementation supports provisioning and
  rotation of current correlated Agents; retries retain the original predecessor request.
- **Agent operation cohort**: one compatible writer set validating the single persisted storage/canonical/destination
  contract and sharing a monotonic generation. Qualified local capabilities and the transaction-duration
  cohort fence are mandatory across Agent and operation repositories. Delivery/cleanup authority binds the generation;
  a switch invalidates old acknowledgements, not already disclosed bytes. Consumers separately exclude unchecked old
  binaries and qualify actual shared-connection writer races. No startup-ready boolean grants admission.
- **Agent canonical request version**: marker `2` identifies the sole supported operation-request representation.
  Provisioning consumes AgentName's fixed Unicode-edge rule, shared with rename, preserving case/internal whitespace
  without NFC conversion; rotation binds its original target/predecessor/destination tuple. Retained keys keep original request/issuance.
  No historical reader, selectable version or old-data migration is required before first adoption. Marker `1` and
  unknown markers reject without fallback. See the [canonical contract](docs/agent-canonical-upgrades.md);
  actual persistence/sink/authority qualification and restoration safety remain separate.
- **Agent operation status**: an authorized secret-free read snapshot separating confirmed original issuance from
  recorded delivery and original-credential disposition. An absent record is indeterminate, not proof of rollback.
  Current scope/delegation/destination checks precede lookup and repeat with target checks before disclosure; reads
  neither commit nor access material and grant no delivery, activation or use authority.
- **Agent protected delivery**: one separately committed claim and current-authority admission, followed by an
  outside-transaction fixed-sink invocation and exact verified receipt acknowledgement. Original bytes/tuple and
  delivery ID survive retry/takeover; current credential, destination order, authority epochs and deadlines fence
  completion. Late admitted bytes remain inert, not enrollment activation or use permission. Current unreleased
  implementation delivers one exact provisioned or rotated operation; TASK-00052 adds bounded delegated discovery,
  scheduler passes and receipt-first restart recovery. Discovery excludes obsolete destination write reservations
  before limiting, so rejected predecessors cannot starve the current slot write. TASK-00053 adds maintenance.
- **Agent delivery maintenance**: authorized ciphertext-only rewrapping, original-retention expiry and separately
  confirmed inert sink cleanup. Keyset discovery includes obsolete slot copies; global key-reference counts are
  diagnostic, never permission to destroy keys. All material writers share key-version admission/reference fences.
  Cleanup excludes current delivered credentials and retains operation correlation, sink tombstones and slot order;
  physical key management and actual sink erasure remain consumer capabilities.
- **Agent issuance**: original Agent/credential/revision, global delivery ID and registered destination binding/write
  order. Confirmed issuance does not establish credential delivery, enrollment activation or permission to launch.
- **Agent credential revision**: the monotonically advancing version of an Agent's single credential authority.
  Rotation replaces the active credential at a new revision; revocation is terminal and removes authentication
  authority.
- **Agent direct Permission assignment**: an Agent-owned, duplicate-free set of stable Permission identities; it
  grants direct authority without introducing Agent Roles or a policy engine. A Permission cannot be removed while
  any Agent has it assigned.
- **Agent Permission-assignment revision**: the monotonically advancing version of an Agent's direct Permission
  assignment. It advances only when that set changes and is independent of the Agent credential revision.
- **Agent hydration**: validated reconstruction of complete current authority without generation or revision reset.
  Repositories also validate exact operation correlation. Unknown or inconsistent state rejects, never creates issuance.
- **Agent profile**: the minimal immutable `{agent_id, name}` read of current ACTIVE state, separate from the
  administrative Agent result. Missing/revoked profiles are equally unavailable; target identity is not authority.
- **Agent read result**: an immutable, secret-free record of an Agent's ID, lifecycle state, credential ID and
  revision, assigned Permissions by ID and canonical name and Permission-assignment revision. There is no legacy or
  recovery marker. Administrative reads decide neither action permission nor delivery, activation or use authority.
- **Authenticated Agent principal**: an immutable authoritative Agent identity and direct-Permission snapshot,
  resolved as one authentication flow rather than from an `AgentView` or a follow-up query.
- **Current Agent principal provider**: a consumer-composed, request-scoped module that authenticates one signed
  Agent request and returns its cached immutable Authenticated Agent principal for that request. Authentication,
  authority revalidation, Permission snapshot resolution, safe diagnostics, and request caching form one flow.
- **Agent continuation binding**: opaque local provenance rooted in admitted authentication or independently verified
  trusted worker handoff, bound to the original Agent/credential/revision and fixed original five-minute window. It
  carries no continuing Permission/scope grant and is not serializable as a bearer credential.
- **Agent continuation admission**: fresh current cohort/Agent/original-credential/direct-Permission/scope checks and
  final time sample, followed immediately by one bounded protected action inside a qualified composite writer fence.
  Consumers own actual synchronization, effects, lifetimes and every later invocation; no detached allow survives it.
- **Agent-aware MCP tool availability**: an AccessControl-owned, request-scoped `AgentToolAvailability` decision
  using `AgentToolPermissionCatalog` built from the actual Common registry and repeatable method-level
  `RequiresAgentPermission`. Exact Common definitions bind static conjunctive Permission requirements to one current
  Authenticated Agent principal snapshot. Common receives only available or unavailable; later MCP requests resolve
  current authority again, and unavailable and unknown tools remain publicly indistinguishable.
- **Security context**: one request-specific, consumer-selected Authenticated User or Authenticated Agent authority.
  It is constructed with exactly that one authority and provides the common Permission and Role checks used by
  consuming code. Consumers select the authentication path through their framework adapters; the package does not
  inspect transports or choose between User and Agent authentication. Every authenticated authority exposes whether
  it is a User or Agent through the package-owned authenticated-principal type. Authenticated Agents have direct
  Permissions and no package-level Roles.
- **Principal Permission**: the narrow immutable Permission identity and canonical name captured in either an
  Authenticated User or Authenticated Agent snapshot. It is authorization data, not the authoritative Permission
  aggregate or the richer administrative Permission view.
- **Desired authorization state**: assigning or granting authority that is already present, or removing or revoking
  authority that is already absent, succeeds as an idempotent no-op. A no-op does not write persistence, advance an
  authority revision, or publish a success event. Missing identities, invalid definitions, stale expected revisions,
  and violated invariants still fail hard. Complete-set inputs are normalized as sets, so repeated identities in one
  request do not change the desired state or cause a failure.
- **Agent authentication diagnostic**: a secret-free, server-observable classification and correlation identifier
  for a failed Agent authentication. It is not disclosed to an untrusted caller.
- **Signed Agent request**: the transport-neutral representation of the Fight Common HMAC v1 canonical request;
  consumer applications map transport data into and out of this representation.
- **Managed Permission and Managed Role**: stable version-controlled authorization definitions reconciled
  exactly and atomically.
- **Super Admin Role**: the uniquely authoritative managed Role named exactly `ROLE_SUPER_ADMIN`; its name is
  reserved and cannot be used by a custom Role. Only this Role may hold protected Permissions. User Role assignment
  and removal use ordinary revision and reference semantics, including pending-User bootstrap; application builders
  authorize all caller entry points and protect last-admin removal.
- **Permission tier**: the non-null classification of a Permission. `ADMIN_SAFE` makes it eligible for delegation
  through consumer-protected command entry points; `SUPER_ADMIN_ONLY` reserves its authority for the designated managed
  Role and human Users.
- **Custom Permission**: a runtime-owned Permission classified `ADMIN_SAFE` from creation. It cannot carry the
  protected tier; delegation still requires authority over the actor and target.
- **Protected managed Permission**: a managed Permission the consuming project classifies `SUPER_ADMIN_ONLY`. The
  accepted v0.4.0 policy reserves its membership for managed `ROLE_SUPER_ADMIN` and its authority for human Users
  through that Role; policy definitions and promotion reject forbidden Role and Agent membership. Consumers fence
  promotion and grants through their persistence adapters.
- **Conformance suite**: reusable tests of observable Domain and Application outcomes which consumer repositories
  bind to their own adapters.
- **OpenAPI schema component**: an opt-in reusable description of a package-owned payload that a consumer's OpenAPI
  generator scans and incorporates into its own document. It does not create an endpoint, HTTP response, or runtime
  transport contract. Its stable OpenAPI key begins with `Fight.AccessControl.` and does not need to match its PHP
  class name.
- **OpenAPI schema contract**: the stable name and validation shape of a published schema component. It is a public
  package API even though consumers choose where and how to reference it.
- **OpenAPI credential-delivery values**: reusable worker-facing message and safe-result schemas, not endpoints.
  `CredentialDeliveryStatus` describes the operational PHP View (including required nullable history), while
  `DueCredentialDeliveries` is an unpaginated array. Invitation and operational statuses share the seven serialized
  Domain enum values. Generated contract tests run in the default PHPUnit/build pipeline and are reused by release
  composition qualification. The unreleased invitation enum correction requires a new minor release under ADR 0007;
  it does not change runtime delivery or authorization. See [composition guidance](docs/openapi-composition.md).
- **OpenAPI metadata distribution**: the package's non-autoloaded `openapi/` directory, containing schema-anchor
  attributes and a bootstrap file for a consumer-owned OpenAPI generator. It is neither Domain nor Application
  production code and does not define a package-owned document.
- **Browser authentication response**: the public authentication body containing access-token material and session
  metadata, but never a refresh credential. A consumer may deliver that credential through an `HttpOnly` cookie.
- **Portable token set response**: the authentication response for a non-browser client or an explicit body-token
  profile. It includes the sensitive opaque refresh credential as well as the browser-response fields.

## Package Boundary

Dependencies flow `Adapter → Application → Domain`. Domain owns invariants and lifecycle state. Application owns
synchronous secret-bearing use cases, explicit non-sensitive commands and queries, portable ports, immutable
token/read results and transaction orchestration. Current secret-bearing operations use TASK-00112's
[direct synchronous owners](docs/user-authentication.md) under the
[Architecture rule](docs/engineering/standards/Architecture.md#application-mirrored-ownership-and-cohesive-capabilities). TASK-00103 adds the first optional PSR-15 Permission
adapter with separately qualified Slim/Yii 3 composition under capability-first `src/Adapter`, approved in
[WF-035](planning/wayfinder/tickets/WF-035-framework-permission-adapter-ownership.md). Domain/Application retain their
DDD structure; native frameworks remain optional. The [profile](planning/agents/project-profile.md#package-boundary-and-contracts)
owns that narrow boundary, dependency isolation and later verification obligations. EPIC-00015 additionally approves
aggregate-oriented neutral MCP presentation in Adapter/Mcp; TASK-00108 has not moved its current Application source.
Consumers retain application policy/grants/scope, persistence, client/route/bootstrap composition, cryptographic keys, mail, queues, realtime
operations, hosting and runtime. The HTTP guide limits actual qualified native composition; approval alone supplies
neither another adapter nor blanket framework support.

Application builders protect every command entry point using controls suited to HTTP, CLI, workers, or other
adapters. Consumer outer layers normally authenticate, validate, authorize, choose synchronous or asynchronous
invocation, and translate failures. Application and Domain code may therefore assume those outer checks succeeded
and fail hard when their own invariants are violated. For v0.4.0 planning, AccessControl retains its narrow
Application authorization ports for cross-user session access, email-change administration, and pending-invitation
correction, which concern ownership of a particular User's resource. The actor-only Role administration and User
Role-assignment and actor-only Agent Permission administration ports have been retired. Custom-Role grants and
direct Agent assignments require authoritative `ADMIN_SAFE` Permissions even for no-ops, fenced through their
respective repository writes. Consumer-managed caller Permissions and package-owned tier and managed-policy
invariants remain distinct.
Package-owned workflow coordinators are final implementation details marked `@internal`; consumers depend on the
public commands, services, authenticated principals, and Security context rather than implementing those coordinators.
Credential-delivery providers receive only one short-lived sensitive invocation after a committed claim and return a
typed outcome. Consumer schedulers use the package's secret-free due-work/status queries and direct delivery commands;
they do not copy claim, retry, terminalization, or stale-generation policy.

## Recoverable Agent operations — historical delivery checkpoints

The following chronology preserves prior implementation/review evidence. TASK-00055's legacy model and TASK-00057's
original cross-version scope are superseded by the current contract above; old counts and verdicts do not accept
TASK-00068. Current integration guides, rather than these historical checkpoints, own present API instructions.

[EPIC-00009](planning/epics/00009-EPIC.md) records the separately planned breaking replacement for recoverable Agent
provisioning and rotation. John ratified D1–D4 and confirmed the complete destination and boundaries on 2026-09-27.
The approved TICKET decomposition assigns issuance/resolution to [TICKET-00012](planning/tickets/00012-TICKET.md),
protected delivery/recovery to [TICKET-00013](planning/tickets/00013-TICKET.md), and migration/compatibility plus
scenario traceability to [TICKET-00014](planning/tickets/00014-TICKET.md). TICKET-00012 now has approved
[TASK-00046](planning/tasks/00046-TASK.md) through [TASK-00049](planning/tasks/00049-TASK.md): provision, safe reads,
rotation and issuance conformance. TICKET-00013 now has approved [TASK-00050](planning/tasks/00050-TASK.md) through
[TASK-00054](planning/tasks/00054-TASK.md): retirement fences, protected delivery, restart recovery, material
maintenance and delivery/lifecycle conformance. TASK-00048/00049 now have concrete cross-TICKET prerequisites and
wait on unfinished dependencies rather than missing information. TICKET-00014 now has approved
[TASK-00055](planning/tasks/00055-TASK.md) through [TASK-00059](planning/tasks/00059-TASK.md): existing-Agent
compatibility, contract cohorts, a single canonical contract, restoration safety and migration/evidence guidance. All three
TICKET decompositions are complete; TASK-00046 is independently accepted and done for implementation/local
verification, not release; its PR #79 is now merged. John authorized TASK-00047 in the main checkout; its safe
status path is independently accepted and done for implementation/local verification, not merge or release.
John subsequently requested landing; PR #80 is merged into the current develop base. John authorized TASK-00050
in the main checkout on `feature/task-00050-retirement`. Independent re-review accepted its R1 failure-trace
redaction correction and complete retirement slice; TASK-00050 is done for implementation/local verification.
John authorized PR publication; PR #81 is now merged into `develop` at `6d52e8d`. John subsequently authorized
TASK-00051 in the main checkout on `feature/task-00051-protected-delivery`. Its protected delivery attempt is
independently accepted and done for implementation/local verification (840 tests / 7276 assertions, exact
5742/5742 statements). Review accepted `5f3f596` with 216 focused tests / 2232 assertions and verified all 674
full-gate inputs. John's landing request published
[PR #82](https://github.com/johnnickell/fight-access-control/pull/82) against `develop`; it is now merged at the
TASK-00048 base `4b6a554`. John authorized TASK-00048 in the main checkout on `feature/task-00048-rotation`.
Its [recoverable rotation](docs/agent-rotation-operations.md) now commits one correlated successor with atomic
predecessor cancellation, original-request resolution, current target authority and safe publication/uncertainty
outcomes. Independent review accepted `e667116` with all TASK criteria passing and no findings; TASK-00048 is
now done for implementation/local verification. John's landing request published
[PR #83](https://github.com/johnnickell/fight-access-control/pull/83) against `develop`; it is open at this checkpoint,
with no merge or release authorized.
John authorized TASK-00052 in the main checkout on `feature/task-00052-delivery-recovery`. Its
[discovery/recovery contract](docs/agent-delivery-recovery.md) adds read-only bounded selection, an event-independent
scheduler pass, optional receipt lookup before decryption, receipt-only recovery of current admissions and safe
reconciliation of lost delivered sink material. Independent review requested R1: obsolete slot reservations could
starve the bounded batch. The revision requires authoritative current-reservation selection before limiting, with
regression proof through the actual scheduler. Independent re-review accepted `c9e23ab`, with all criteria passing,
269 focused tests / 2140 assertions and all 696 full-gate inputs verified. TASK-00052 is done for implementation/local
verification. John requested landing; [PR #85](https://github.com/johnnickell/fight-access-control/pull/85) is open against
`develop` at its initial publication checkpoint after mechanical integration of the TASK-00060 planning-only base
update. The fresh landing gate passes 957 tests / 8350 assertions and exact 5971/5971 statements. This is not consumer
qualification, merge or release.
John authorized TASK-00053 in the main checkout on `feature/task-00053-delivery-material`. Its
[maintenance contract](docs/agent-delivery-maintenance.md) adds bounded read-only selection, global key accounting,
protected rewrapping, retention expiry and outside-transaction sink cleanup with exact-state acknowledgement.
The full local gate passes 1047 tests / 8980 assertions and exact 6245/6245 statements. Independent review accepted
`9a0a391` with all nine criteria passing, no findings, 526 fresh focused tests / 5057 assertions and all 718 gate/bridge
inputs verified. TASK-00053 is done for implementation/local verification. John's landing request published
[PR #87](https://github.com/johnnickell/fight-access-control/pull/87) against `develop` at initial publication head
`4b79cee`; the fresh landing gate retains 1047 tests / 8980 assertions and exact 6245/6245 statements. The ignored
landing handoff owns final PR-metadata verification and remote identity. No consumer qualification, merge or release
is claimed. Other slices retain their remaining dependencies and require separate
execution authority.
John authorized TASK-00049 in the main checkout on `feature/task-00049-issuance-conformance`, from `develop`
`47ffd42` (including merged PR #87). Its implementation and local verification pass 1100 tests / 12626 assertions
and exact 6245/6245 statements. Independent review accepted `3fcf2c5` with all ten criteria passing, no findings,
572 fresh Agent tests / 8313 assertions and 723/723 final gate inputs verified. TASK-00049 is done for accepted
implementation/local verification, not consumer qualification or release. John subsequently requested landing;
[PR #88](https://github.com/johnnickell/fight-access-control/pull/88) is open against unchanged `develop` at initial
publication head `eb91435`, with no implementation reconciliation. The fresh landing gate retains 1100 tests /
12626 assertions and exact 6245/6245 statements. The ignored landing handoff owns final metadata/remote verification;
no merge or release is authorized.
John authorized TASK-00054 in the main checkout from `develop` `f4d43e5` (including merged PR #88), on
`feature/task-00054-delivery-conformance`. Its [delivery/lifecycle conformance](docs/agent-delivery-conformance.md)
adds reusable public-port suites for authority and lifecycle interleavings, uncertain delivery commits, real package
restart/takeover, maintenance/cleanup and exact sink binding/order. Package-controlled bindings run with and without
optional receipt lookup; no production API or behavior changes. These are modeled persisted-state/interleaving tests,
not real database/process, cryptographic sink or consumer activation/use qualification. Independent review accepted
`7b58fbe` with all ten criteria passing and no findings, 624 fresh Application Agent tests / 12608 assertions,
134 Domain Agent tests / 766 assertions and all 734 gate inputs verified. TASK-00054 is done for accepted
implementation/local verification. John's landing request published
[PR #89](https://github.com/johnnickell/fight-access-control/pull/89) against unchanged `develop` at initial head
`255d0c7`; the fresh landing gate passes 1286 tests / 17687 assertions and exact 6245/6245 statements. Its ignored
handoff owns final metadata/remote verification and the metadata-only review bridge. No consumer qualification,
merge or release is authorized.
John authorized TASK-00055 in the main checkout from `develop` `ea1e316` (including merged PR #89), on
`feature/task-00055-legacy-compatibility`. Its [existing-data/API contract](docs/agent-existing-data-v0.5-migration.md)
adds validated explicit reconstitution, safe recovery-marker reads and legacy adoption through the existing authorized
rotation transaction. Behavioral tests preserve active/revoked historical authentication, original-key semantics,
rollback, uncertainty, safe publication warnings and nonce/current-authority fencing. The full local gate passes
1318 tests / 18169 assertions and exact 6270/6270 statements. Independent review accepted `ebc396c` with all seven
criteria passing, no findings, 797 fresh focused tests / 14246 assertions and all 736 gate/bridge inputs verified.
TASK-00055 is done for accepted implementation/local verification. John's landing request published
[PR #90](https://github.com/johnnickell/fight-access-control/pull/90) against unchanged `develop` at initial head
`46658f1`; the fresh landing gate retains 1318 tests / 18169 assertions and exact 6270/6270 statements. Its ignored
handoff owns final metadata/remote verification and the administrative-only review bridge. Consumer migration
qualification, merge and release are not claimed.
John authorized TASK-00056 in the main checkout from `develop` `eb20472`, on
`feature/task-00056-contract-cohorts`. Its [cohort contract](docs/agent-operation-cohorts.md) adds mandatory persisted
compatibility snapshots on both repository contracts, guarded actual writer paths and cohort-bound delivery/cleanup
authority. Consumer-bindable M2 scenarios and controlled reference interleavings cover denial and restart. The full
local gate passes 1442 tests / 21365 assertions and exact 6307/6307 statements. Independent review accepted `ff5c035`
with all eight criteria passing, no findings, 913 fresh Agent tests / 17039 assertions and all 764 final gate inputs
verified. TASK-00056 is done for accepted package implementation/local verification. John's landing request published
[PR #92](https://github.com/johnnickell/fight-access-control/pull/92) against unchanged `develop` at initial head
`a0897e6`; the fresh landing gate retains 1442 tests / 21365 assertions and exact 6307/6307 statements. The ignored
TASK handoff owns final metadata/remote verification and the administrative-only review bridge.
Real consumer old-binary exclusion, writer races and deployment remain unqualified; no merge or release is authorized.
John authorized TASK-00057 in the main checkout from `develop` `f0d872c`, on
`feature/task-00057-canonical-upgrades`, including the pending WF-023 planning decision in its next commit.
At the original, now-superseded checkpoint, its canonical upgrade implementation froze historical readers and
integrated v2 with existing issuance/status/
delivery/maintenance paths. Consumer-bindable scenarios exercise both versions through restart and retained states;
the full local gate passes 1501 tests / 32816 assertions and exact 6317/6317 statements. Independent review accepted
`04a2adf` with all eight criteria passing, no findings, 973 fresh tests / 28585 assertions and all 771 final gate
inputs verified. That original scope was marked done for accepted package implementation/local verification. John's landing request
published [PR #93](https://github.com/johnnickell/fight-access-control/pull/93) against unchanged `develop` at initial
head `53debe0`; the fresh landing gate retains 1501 tests / 32816 assertions and exact 6317/6317 statements.
The TASK's ignored handoff owns final metadata/remote verification and the metadata-only acceptance bridge.
This was not a real consumer upgrade, merge or release. John's 2026-09-30 amendment and subsequent work invocation
reopen TASK-00057: current code removes that speculative compatibility, retains one Unicode-aware contract and
replaces cross-version fixtures with same-contract restart/lifecycle/fail-closed proof. Earlier counts/acceptance
are historical, not current evidence. Fresh independent review now accepts revised `2de4104` with all criteria passing,
no findings, 941 fresh tests / 21822 assertions and 771 verified gate inputs; TASK-00057 is done for accepted package
implementation/local verification. John requested updating PR #93; the ignored simplification landing handoff owns
final delivery evidence and its metadata-only review bridge. TASK-00058 retains restoration ownership.
Each downstream execution needs authorization. John separately authorized TASK-00046
execution and PR landing. Planning does not qualify a consumer or authorize migration, release or adoption.
The replacement requires caller-scoped operation correlation, protected delivery and consumer authorization
participating in the package-owned transaction under shared authority fences. Consumers still own policy, adapters,
keys and the sink; unsupported integrations fail closed.

TASK-00047 adds [safe operation queries](docs/agent-operation-status.md) using the original key and destination,
`AgentOperationRepository::getStatusByKey()` and `AgentOperationAuthorization::authorizeRead()`. Consumers implement
current read authority independently of transaction-only issuance authorization; the second check includes the
original Agent target. Safe views retain persisted version and separate delivery/credential dispositions, including
tombstones. Unknown versions deny without reinterpreting requests. Storage failures are sanitized unavailable;
authoritative absence is indeterminate. Tests exercise real provision-to-read recovery and controlled persisted
fixtures for later dispositions, not actual downstream lifecycle/delivery writers or consumer database proof.

TASK-00046 implements provision and same-key service resolution, not the entire replacement. The old provisioning
signature and raw result are removed; its new safe result distinguishes confirmed issuance, publication warning and
indeterminate commit. Domain operation storage retains request version, safe issuance and separate protected material;
Application coordinates current authorization, reservation and atomic writes. See the
[public provisioning contract](docs/agent-provisioning-operations.md) for concrete defaults and adapter obligations.
Every Agent requires current operation correlation, including after Permission changes; there is no legacy marker
or raw rotation path. [TASK-00050 retirement](docs/agent-credential-retirement.md) allows terminal revocation while
preserving original correlation. `AgentRepository::replace()` validates exact lifecycle successors and atomically cancels the
original credential operation through same-connection `AgentOperationRepository::retireCredential()`. Retirement
removes delivery material, advances the operation state revision and preserves original correlation/delivery history;
no old claim/admission snapshot can restore it. Concrete replacement/cancellation implementations must annotate both
Agent arguments as sensitive; transaction implementations must also redact the callback argument, whose captured or
bound state can expose authentication material. Interface annotations alone are not inherited by implementations.
All authority and delivery writers must share those fences/epochs.
TASK-00050's original evidence is controlled expected-state package proof, not a delivery worker or real consumer
qualification. TASK-00051 now adds the [protected delivery service](docs/agent-credential-delivery.md) using those
actual retirement fences: committed claim, separately committed admission with persisted authority/deadline,
sensitive fixed-slot invocation outside transactions, exact receipt verification and fresh fenced completion. The
operation stores immutable attempt/policy and receipt or closed failure evidence; completion removes only delivery
material. Defaults are a 60-second lease, 15-second admission, 30-second retry delay, 86400-second original retention
and 100 attempts, with validated bounded overrides pinned on first claim. Current scope/target/slot checks and
exact state revisions reject stale effects. Unconfirmed admission never decrypts; uncertain completion never guesses
success. Deterministic in-memory sink/authority and lifecycle tests do not qualify consumer databases or activation/use.
TASK-00048 adds `AgentCredentialRotationService`, `AgentRotationRequest` and mandatory transactional
`authorizeRotation()` scope/target/destination authorization. Results have no raw-secret getter. TASK-00068 removes
the old lifecycle `rotate()` and legacy aggregate path; the revocation service retains its failure semantics.
Every predecessor requires atomic cancellation and every successor its own new operation/delivery/audit. Current
hydration and safe reads create no issuance. This work remains unreleased and does not qualify a consumer deployment.
TASK-00058's restoration behavior is accepted at the package boundary; final guidance review, release and real
consumer qualification remain separate.

For those two replacement operations only, confirmed issuance commits return safe operation metadata with a typed,
sanitized publication warning if post-commit publication fails. Pre-commit failure and indeterminate commit are
distinct outcomes. Same-key resolution survives both publishers failing; TASK-00052 now supplies authorized scheduler discovery
through the actual delivery path. TASK-00049 adds the [consumer-bindable issuance conformance](docs/agent-issuance-conformance.md)
and combined provision/rotation tests with both publishers failing and modeled caller termination. The actual scheduler
recovers pending and abandoned-claim work without caller retry. Behavioral-adapter runs are not real process/database,
consumer activation/use or adoption qualification; TASK-00046 alone did not establish this combined outcome. The complete replacement requires that
retry does not repeat issuance or its audit fact. Committed issuance is neither confirmed credential delivery,
enrollment activation nor permission to launch: each requires its own confirmed outcome and current authorization.
This exception does not change revocation, synchronous User capabilities or other CommandHandlers. Failure/restart behavior
tests and consumer conformance are required before acceptance of the relevant implementation/integration. Package
provisioning tests are not proof of delivery, real database concurrency, consumer qualification or runtime readiness.

Credential-operation limits have documented finite defaults and optional validated consumer overrides; routine
operation/recovery needs neither manual configuration nor an extra human approval step. Current authorization still
applies. Capacity exhaustion rejects or defers new work with a clear retryable outcome while preserving safe status
lookup and recovery of existing operations. Cleanup retains the evidence needed to prevent duplicate issuance and
stale delivery. Provisioning defaults are 512 raw name bytes, 100 pending operations per scope and 10000 globally,
with validated bounded overrides; existing resolution precedes new-work admission. TASK-00052 adds a default batch of 50 (valid 1–100) and 30-second polling guidance (valid 1–3600 seconds),
with at most one batch per pass and no capacity check on existing recovery. Selection excludes obsolete reservations
using the stable slot's committed counter across all scopes/bindings, before ordering or limiting; no manual cleanup
is needed to reach the current write. Delivery policy remains pinned on
first claim; pending/retry/expired-claim due selection respects both lease and retry, capped by original retention.
TASK-00053 uses maintenance batches of 50 (valid 1–100), exclusive delivery-ID cursors, and a default inert-entry
cleanup grace of 86400 seconds (valid 1–604800) after original delivery retention. Current delivered authority is never
cleanup-eligible. Rewrap preserves the pinned delivery policy, or pins the existing default before first claim;
expiry and cleanup cannot extend original delivery retention. Reference counts include every retained copy across
scopes/batches/versions; physical retirement also requires closed write admission, fenced recount and independent
key-use/authentication-envelope accounting. This is not a wider quota, key-vault or approval system.

## Permission-based feature flags — package acceptance complete

[EPIC-00010](planning/epics/00010-EPIC.md) owns the approved package-only destination from the closed
[Wayfinder map](planning/wayfinder/permission-based-feature-flags-map.md). A planned Feature has a unique immutable
lowercase hyphenated name, a required existing testing Permission by ID, and OFF/PREVIEW/ON status. Preview checks
existing User/Agent Permission authority; availability never replaces business authorization. Automatic registry
provisioning creates missing Features OFF using the configured default Permission and preserves existing choices.
Current references guard deletion; checks read fresh Feature state without shared caching or in-flight cancellation.
Consumers own adapters, scanning, enforcement, UI, authorization, and deployment integration; actual CMS migration
is separate. TASK-00062 supplies stored Feature creation/provisioning. TASK-00063 adds read-only complete-candidate
preparation validation against authoritative Feature and bound Permission IDs; a valid OFF Feature passes preparation,
not runtime availability or deployment authorization. Evaluation and TASK-00066 management are independently
accepted at their respective package checkpoints; guarded retirement remains TASK-00067.
TASK-00061 implements the
[declaration and discovery contract](docs/feature-references.md): Domain `FeatureName` validates without normalization;
Application `FeatureFlag` is method-only/nonrepeatable and `FeatureReferences` merges name-only references.
`FeatureDiscoveryResult` exposes only complete references in the explicitly requested `CANDIDATE` or `CURRENT`
scope; failed/incomplete discovery and scope mismatch reject instead of becoming empty results. Consumers own
scanning, code identity and completeness; this is not enforcement or preparation/retirement implementation.
The local gate passes **1737 tests / 34624 assertions**, exact **6242/6242 statements**; focused Feature tests
pass **62 tests / 121 assertions**. Independent review accepted `0408f72` against unchanged `develop` `e8244b0`,
with all C1–C7 passing and no findings. Independent behavioral QA passed six scenarios and **202 checks**, including
native target failures, incomplete discovery, scope misuse and isolated hook cleanup. TASK-00061 is done for accepted
implementation/local verification. John's landing request published
[PR #99](https://github.com/johnnickell/fight-access-control/pull/99) against unchanged `develop` at initial head
`ff10dbb`; it is open at this publication checkpoint. Fresh landing gates retain the counts above, without dependency
drift or warnings/skips; sanitized nonvisual QA results are in the PR body. The ignored TASK landing handoff owns final
metadata verification, the administrative-only provenance bridge and final remote identity. No consumer scanner,
provisioning, retirement, merge, release or deployment is qualified.
John approved the split into
[TICKET-00015](planning/tickets/00015-TICKET.md) (registration/provisioning),
[TICKET-00016](planning/tickets/00016-TICKET.md) (availability evaluation), and
[TICKET-00017](planning/tickets/00017-TICKET.md) (management/retirement). The latter two build on TICKET-00015's shared
contracts; each owns its evidence and consumer integration obligations. TICKET-00015 now has an approved chain:
[TASK-00061](planning/tasks/00061-TASK.md) declares/registers references,
[TASK-00062](planning/tasks/00062-TASK.md) provisions atomically, and
[TASK-00063](planning/tasks/00063-TASK.md) validates preparation before activation. John approved a method-only,
nonrepeatable Attribute and one atomic database transaction per provisioning pass; retries reread stored state,
including after post-commit publication failure, without resetting choices or promising event delivery.
TICKET-00016's approved [TASK-00064](planning/tasks/00064-TASK.md) depends only on TASK-00062 and supplies the complete
evaluator: existing User/Agent snapshot or null, Permission-ID matching without changing the shared authority
interface, and boolean ordinary availability with distinguishable unknown-Feature/broken-binding errors and separate
operational failures. TICKET-00017's approved [TASK-00065](planning/tasks/00065-TASK.md) protects referenced Permissions,
[TASK-00066](planning/tasks/00066-TASK.md) supplies creation/read/update management, and
[TASK-00067](planning/tasks/00067-TASK.md) supplies guarded retirement and final cross-TICKET lifecycle evidence.
One Feature revision starts at 1 and advances only on real status/Permission changes; updates/deletion require the
expected revision. Valid no-ops do not write/increment/publish success; stale attempts reject. Management reads retain
broken Permission IDs with an explicit missing marker and reuse existing pagination. The graph is 61 → 62;
62 → 63/64/65; 65+64 → 66; 66+63 → 67. All three TICKETs now have approved TASK plans. TASK-00061 implementation is on
`feature/task-00061-feature-references` in the main checkout, with independent review and QA accepted at `0408f72`.
John subsequently requested renaming the Attribute to `FeatureFlag`, reopening TASK-00061 on the same branch/main
checkout: no compatibility alias and no change to validation, native targets or discovery semantics. Independent
review now accepts renamed implementation `a8bdf75` against unchanged `develop` `e8244b0`, all C1–C7 passing with no
findings. Independent post-review QA passes six scenarios and **208 checks**, including old-alias absence and native
malformed declarations after partial discovery. Focused/full gates retain **62 tests / 121 assertions** and
**1737 tests / 34624 assertions**, exact **6242/6242 statements**. TASK-00061 is done for this accepted implementation
and required local evidence. John authorized updating existing PR #99; publication remained pending at that closeout
checkpoint. The ignored `.runs/handoffs/TASK-00061/feature-flag-landing.md` owns final gates, the administrative-only
bridge and remote/evidence verification. Earlier acceptance/publication above is historical. At that checkpoint,
TASK-00063–00067 remained unimplemented and needed separate execution authority; their later checkpoints follow.
No Feature release version is selected.

John authorized TASK-00062 in the main checkout on `feature/task-00062-feature-provisioning` from clean `develop`
`02ef72a`, including merged PR #99. The [provisioning guide](docs/feature-provisioning.md) describes the new Feature
model (immutable identity/name, stored Permission/status, positive revision), Domain repository creation-side
uniqueness/reference fences and `ProvisionFeatures` handler. Complete CANDIDATE discovery precedes one atomic
creation pass; only missing names become OFF with the lazily resolved default Permission. Existing settings survive
retries/default changes. Post-commit `FeatureCreated` publication is best effort: failure/uncertain commit throws
and a fresh pass rereads storage without duplicate creation or event replay. Completion is not activation readiness.
The command has an additive opt-in OpenAPI schema; no existing API or schema is narrowed. Builder verification passes
**112 focused tests / 435 assertions**, **1787 full tests / 34938 assertions**, exact **6305/6305 owned statements**,
with no final warnings/skips or dependency drift. The TASK owns the local receipt and failure chronology. Independent
review and behavioral QA were outstanding at that builder checkpoint. Independent technical review subsequently
accepted implementation `ff93b60` against `develop` `02ef72a` (C1–C10, no findings), and independent behavioral
QA passed eight scenarios / 337 checks and a clean final 120-test / 840-assertion focused run. TASK-00062 is done
for accepted package implementation/local verification; John requested PR landing separately. Controlled
repository/interleaving probes are not consumer database, discovery, removal-guard or deployment qualification.
TASK-00063–00067 retain their validation/evaluation/integrity/management/retirement scope; this is not a
supported partial release.

## Feature preparation builder checkpoint — TASK-00063

John authorized the clean main checkout from `develop` `1a1e81e` on
`feature/task-00063-feature-preparation`. The [preparation query and integration guide](docs/feature-preparation.md)
now check a complete candidate inventory against fresh stored Feature and exact Permission identities, distinguishing
invalid configuration from incomplete discovery and operational failure. Consumer-bindable package-port scenarios
compose declaration, provisioning and validation, including rollback/retry and post-commit publication loss. The
builder's full gate passes **1802 tests / 35027 assertions**, exact **6348/6348 owned statements**. Independent
technical review requested a documentation-only correction at `9f60836`: the declarations guide incorrectly
listed preparation as outstanding. The revision corrects that statement without changing executable gate inputs.
Independent re-review accepted `5cb1a376` against unchanged `develop`, C1–C9 passing with no findings; independent
post-review QA passed five
scenarios / 26 real-library probe checks plus 14 focused tests / 75 assertions. TASK-00063 is done for accepted
package implementation and required local/behavioral verification. This is not a partial Feature release, actual
scanner/database qualification, permission to activate, consumer adoption or completion of the downstream
integrity/management slices. John's landing request published
[PR #101](https://github.com/johnnickell/fight-access-control/pull/101) against `develop` at the initial
publication checkpoint; approval, merge and release remain separate. The ignored TASK landing handoff owns
final metadata/remote verification.

## Feature availability — TASK-00064 implementation checkpoint

The unreleased [Feature availability guide](docs/feature-availability.md) describes a read-only evaluator of current
Feature settings against existing User/Agent Permission-ID snapshots or anonymous input. OFF denies, PREVIEW checks
captured IDs, and ON removes only the Feature restriction; every status first validates the stored Permission binding.
Unknown Features, broken bindings, malformed definitions and operational failures remain distinct, never implicit
availability. Each explicit check requires authoritative fresh repository reads; existing principal lifetimes and
already-admitted work are unchanged. Consumer enforcement and action authorization remain separate. Controlled
package and consumer-bindable tests do not qualify a real database, framework or worker runtime. The first independent
review requested corrections to entry-point/parent guidance and direct admitted-work completion proof; the revision
addresses both with a fresh complete local gate (1812 tests / 35070 assertions, exact 6369/6369 statements).
Independent re-review accepted `fc1cc89` against unchanged `develop` `b2fc4ce`, C1–C10 with no findings after
F1/F2 corrections. Independent behavioral QA passed six scenarios / 30 public-port probe checks and a clean
74-test / 416-assertion focused run. TASK-00064 is done for accepted package implementation and required
local/behavioral verification; hosted CI is optional/not checked. John's landing request opened
[PR #102](https://github.com/johnnickell/fight-access-control/pull/102) against unchanged `develop` at initial
publication head `a0aae5a`. Its ignored landing receipt owns the administrative-only bridge and final publication
verification. Approval and merge remain separate. TASK-00065's accepted package proof does not close TASK-00066–00067;
complete Feature acceptance, actual consumer qualification, adoption and release remain outstanding.

## Feature Permission references — TASK-00065 builder checkpoint

The [reference integrity guide](docs/feature-permission-references.md) records the supported removal inventory:
`PermissionRepository::remove()` is the final shared-fence boundary, also used by managed-policy reconciliation.
Preview queries all stored Feature references by Permission ID; no status or list-page filter applies. The final
remove check covers Feature, Role and Agent references, including references introduced after planning. Feature
creation validates the same identity and fence; controlled package conformance exercises both winner orders and
rollback, not real database concurrency. Feature binding is not a grant or promotion blocker. TASK-00066's
unreviewed management implementation adds rebinding under that same fence; its acceptance and actual consumer proof
remain pending. Consumer persistence/schema/authorization,
retirement (TASK-00067), adoption and release remain outstanding. TASK-00065's second F1 correction places the
sole target binding after 101 unrelated bindings in insertion, ID and name order, beyond the ordinary 100-record
page; the fresh gate passes 1821 tests / 35123 assertions and
exact 6374/6374 statements. Independent re-review accepted `f6438ae` against `develop` `5ab3d38`,
C1–C8 with F1 resolved and no findings. Independent behavioral QA passed six scenarios / 30 public-port checks
plus 50 focused tests / 405 assertions. The branch's non-rewriting merge of later planning-only `develop` `c691b57`
preserves the reviewed effective PHP/tests/guide diff; the ignored landing handoff owns reconciliation and fresh
checks. TASK-00065 is done for accepted package implementation and local QA, not actual consumer database fencing,
rebinding, retirement, merge or release. John's landing request opened
[PR #104](https://github.com/johnnickell/fight-access-control/pull/104) against `develop` at initial head `ddd2c3e`;
its ignored landing receipt owns final metadata/remote verification. TASK-00066/00067 and consumer qualification
remain separate.

## Feature management — TASK-00066 builder checkpoint

John selected the clean main checkout from `develop` `91102bb` for TASK-00066 on
`feature/task-00066-feature-management`. The unreleased [management guide](docs/feature-management.md) describes
manual OFF creation, safe paginated reads with missing-binding markers and shared optimistic revision transitions.
The package handlers commit real edits before publishing facts; no-ops perform no writes or success publication and
still validate revisions/Permission references at the final repository boundary. The consumer must implement actual
transaction-duration reference fences and qualify schema/ORM concurrency plus UI confirmation and entry-point
security. The complete local gate passes **1833 tests / 35243 assertions** with exact **6586/6586 statements**;
its ignored TASK receipt and log retain provenance. The first independent technical review of `2f45dca` requested F1/F2 evidence for rebinding/removal winner orders
and fail-closed evaluation after broken-binding repair, without identifying a production defect. A builder revision
adds public-handler/consumer-bindable reference-conflict scenarios and same-principal evaluation across a broken
binding, management read and authorized repair. The fresh revision gate passes **1834 tests / 35275 assertions**,
exact **6586/6586 statements**; its ignored receipt records the content snapshot and failed-attempt chronology.
At the builder checkpoint independent re-review and QA were pending. Independent re-review subsequently **accepted**
clean `0eec869` against unchanged `develop` `91102bb`, with C1–C9 passing, no blockers and F1/F2 evidence gaps
resolved. Independent post-review behavioral QA **passed** eight scenarios including 45 executable checks plus a
consumer instruction walkthrough. TASK-00066 is done for accepted package implementation and local verification;
publication/merge remain separate. This is not actual consumer database, UI or entry-point security qualification
and is not a release. TASK-00067 retains guarded retirement and final lifecycle evidence.

## Feature retirement — TASK-00067 implementation checkpoint

John selected the clean main checkout from `develop` `09abe1f` for TASK-00067 on
`feature/task-00067-feature-retirement`. The [retirement guide](docs/feature-retirement.md) describes `RemoveFeature`
with stable ID and positive expected revision, complete CURRENT discovery of native and explicit references, and
mandatory full-expected-state `FeatureRepository::remove()` under the shared transaction/reference fence. Current
references in any status, incomplete/wrong-scope discovery and stale writes reject. Removal/reference release roll
back together; `FeatureRemoved` publishes only after commit and publication faults rethrow without restoring state.
No Permission/grant mutation, name reservation, deployment fence, scanner or production adapter is introduced.
Later ordinary provisioning allocates a new identity OFF/1 with the then-current default; old delete/update IDs
cannot retarget it. Real composed package tests exercise deletion, unknown lookup, failed then valid reprovision,
preparation and OFF evaluation. Public-handler reconciliation after retirement still respects other Feature/Role/Agent
references. Consumer-bindable retirement/final-state scenarios and the [complete evidence map](docs/feature-evidence.md)
retain previous TASK subjects and actual consumer gaps; previous independent acceptance does not accept this slice.
Builder verification passes **174 focused tests / 977 assertions**, **1849 full tests / 35482 assertions**,
exact **6627/6627 owned statements**. No final warnings/skips; the resolver's two ignored PHPUnit dev-dependency
patch updates and early corrected invocation/static/fixture failures are retained in the TASK evidence. At that
builder checkpoint independent technical review and behavioral QA remained pending. Independent technical review subsequently **accepted** clean
`8e33f0847d41aff562e6258b1a360d70d152dfe1` against unchanged `develop` `09abe1f` (C1–C10 pass, no findings).
Independent post-review QA **passed** seven scenarios / **260 executable checks** plus a ten-case instruction
walkthrough, with clean **174 tests / 977 assertions**. TASK-00067 is done for accepted package implementation and
required local/behavioral verification. Automatic completion closes TICKET-00017 and EPIC-00010 in this same
administrative operation; TICKET-00015/00016 are already done. This completes package acceptance, not actual
consumer qualification. John's landing request opened
[PR #107](https://github.com/johnnickell/fight-access-control/pull/107) against unchanged `develop`, draft at initial
head `e83bd9935ec9981b0dbdf291fe70137f3cc42814`, with sanitized nonvisual QA scenarios/state/events published and
read back. Final metadata delivery verification/ready state remain pending at this tracked checkpoint. The ignored
TASK landing handoff owns fresh gates, the administrative-only acceptance/QA and resolved-runtime bridge, and final
remote/evidence verification. Original independent reports are preserved; no approval or merge is claimed. Consumer scanning/database/UI/security/runtime qualification, release and deployment remain separate.

## Optional Agent name audit — TASK-00156 accepted checkpoint

[Name-update composition](docs/agent-name-updates.md#optional-atomic-name-change-audit-unreleased) now supports a final
nullable audit repository. Enabled `UpdateAgentHandler` explicitly inserts typed `agent.name_changed` evidence inside
its existing rename transaction after a real persisted change. Existing required User/Agent provenance, name-only
last-committed behavior, post-admission clock and all writer fences remain. Evidence uses exact persisted changedAt,
actual message correlation and only `{name_changed: true}` facts, not names, snapshots or invented revisions.
Omitted/null intentionally disables durable audit. Validated no-op adds none; audit failure rolls back staged state
and evidence, uncertainty remains uncertain, and post-commit publication failure leaves both committed without a
success acknowledgement. Independent Review ACCEPT and nonvisual QA PASS cover candidate
`cd3b4ca0f0204f7ec30f44eceb16eddc1a907651`: 29 scenarios / 195 driver checks and fresh 63 tests / 781 assertions,
with no findings. TASK-00156 is done; unfinished siblings keep TICKET-00045 and EPIC-00017 open. Earlier full gate
is retained for unchanged application inputs. Publication/final delivery checks remain pending at this administrative
checkpoint; canonical `.runs/handoffs/TASK-00156/land.md` owns subsequent delivery/resource outcomes. Original
reports and minimum-lane warnings remain preserved. Consumer connection/mapping/fences/entry-policy qualification,
merge, release and deployment are separate.

## Agent name updates — TASK-00069 accepted

John selected the clean main checkout from `develop` `913f373` for TASK-00069 on
`feature/task-00069-agent-rename`. The [name update guide](docs/agent-name-updates.md) describes generic `UpdateAgent`
with typed User/Agent `AgentUpdateInitiator`, void synchronous `UpdateAgentHandler` and post-commit `AgentNameChanged`.
The mandatory Domain repository `rename(id, name, now)` capability invokes a lazy clock callback after current writer
admission and applies `Agent::rename()` to fenced ACTIVE state, including no-ops. It returns exact persisted time or
null for a validated no-op, preserving all credential/Permission authority and exact operation correlation. Last committed
name wins with no expected old name or revision; no-op changes no timestamp, persistence or success fact. Real changes
commit before publication; post-commit publication failure rethrows without rollback or success acknowledgement.
All consumer dispatch paths require caller/target authorization; initiator identity is provenance only. Successful
synchronous void dispatch permits the same input-derived `{agent_id, name}` acknowledgement for changes and no-ops,
not a fresh read of a later concurrent name. New opt-in command/provenance schemas preserve that public contract.
At the initial pre-review checkpoint `614c34b`, focused checks pass **1124 tests / 30771 assertions**; the complete
`./bin/build` passes **2037 tests / 40062 assertions**, exact **6930/6930** owned statements (243/243 across TASK-changed
production files). No final warnings/notices/skips; the canonical resolver upgraded ignored dev-only swagger-php
6.11.0 to 6.12.0 before the full gate. Final verification prose retains equivalent executable inputs plus targeted
planning/documentation checks. Independent review of `614c34b` requested F1: pre-fence clock sampling falsely rejects
a waiting rename after another writer advances time. Three regression cases reproduced this for competing rename,
Permission and real rotation before repair. The corrected contract samples under writer admission and returns the
exact persisted event time; genuine-backdating rejection and no-op/authority safety remain. Revision focused checks
pass **1127 tests / 30796 assertions**; fresh canonical `./bin/build` passes **2040 tests / 40087 assertions**, exact
**6930/6930 owned statements** (243/243 across TASK-changed whole production files). No final warnings/skips or dependency
drift; all 905 gate inputs stayed byte-identical. The unchanged review probe also passes all four builder-rerun cases.
Final evidence prose retains equivalent executable inputs with targeted planning/documentation checks. Independent
re-review subsequently **accepted** `fb12b0592f45bb97758ef54457ccd34a53702c91` against unchanged `develop`, with
C1–C6 passing, F1 resolved and no findings. Independent post-review behavioral **QA PASS** covers five groups,
**40 executable cases / 375 checks**, **8 instruction walkthroughs** and clean **21 tests / 267 assertions**.
TASK-00069 is done for accepted package implementation and required local/behavioral evidence. Automatic parent
completion closes TICKET-00018 in the same landing operation; EPIC-00011 remains needs-info for unfinished
TICKET-00019/TASK-00070/00071. John's land invocation authorizes non-force PR publication; final delivery remains
pending at this tracked closeout checkpoint. The TASK's ignored landing receipt owns fresh gates, final commit/PR
identity and the administrative-only acceptance/QA bridge; canonical independent reports remain unchanged.
The landing gate subsequently failed after its normal resolver upgraded PHPStan 2.2.17 to 2.3.0: 12 static-analysis
errors in eight existing test files outside this TASK's diff. Focused 1127 tests / 30796 assertions and planning
passed, but that attempt produced no fresh complete gate, commit, push or PR. Closeout edits were preserved.
John then authorized a bounded eight-test repair through work on the same branch. PHPStan 2.3.0 now passes;
focused repaired-file checks pass **264 tests / 5261 assertions** and fresh canonical `./bin/build` passes
**2040 tests / 40091 assertions**, exact **6930/6930 statements**, with no final warnings/skips or further dependency
drift. Test assertions preserve or strengthen prior contracts; production code, dependency policy and analyzer
configuration are unchanged. The accepted-product completion/parent rollup remains the prior checkpoint, not
independent approval of this test-only follow-up at that builder checkpoint. Independent review subsequently
**accepts** clean repaired `aa5ea1466dc555863f30b7b6cee775f872d318bc`, all C1–C6 passing with no findings; fresh
checks pass **285 tests / 5528 assertions**, PHPStan and **4/4** contention cases. The canonical review verifies the
full-gate inputs and preserves the prior independent QA PASS through unchanged behavior/QA fixtures/public guides,
reviewed test changes and an explicit resolved-dependency/runnable-image bridge. This is not a new QA verdict.
The old review is retained in review history; canonical QA is unchanged. John resumed land; remote develop is
unchanged and no feature publication or PR exists at intake. TASK/TICKET remain done; EPIC-00011 remains needs-info.
Fresh landing focused checks pass **285 tests / 5528 assertions** and the complete gate passes **2040 tests /
40091 assertions**, exact **6930/6930 statements**, with no final warnings/skips or package drift. John authorized
[PR #109](https://github.com/johnnickell/fight-access-control/pull/109), open against unchanged `develop` at initial
head `6fb04b0f770c0a5619402fa8047d28e369aab78b`. Sanitized nonvisual QA evidence is published and publicly read back.
At this PR-metadata checkpoint it remains draft pending final verification/push and ready transition; the TASK's
ignored `landing/resumed-accept/` receipt owns final delivery and the administrative-only provenance bridge.
No approval, merge or release is claimed.
Hosted CI is optional/not checked.
Controlled in-memory writer schedules are not actual consumer database,
security wiring or runtime qualification. TASK-00070/00071/00035 retain profile reads/Permissions/MCP Tool integration;
no Tool, administrator endpoint, consumer adapter, merge, package release or deployment is delivered here.

## Agent profile output — planning amendments

John simplified the [WF-024](planning/wayfinder/tickets/WF-024-self-service-agent-profile-contract.md) update
acknowledgement to exactly `{agent_id, name}` from the target and `AgentName::fromString()`-normalized command input
only after successful synchronous void CommandBus dispatch. Real rename and no-op responses share that shape and
Common's matching derived JSON text; no `changed` field, separate changed/no-op sentence, result-bearing rename
boundary or compensating read is required. This acknowledges the successful request, not a fresh read of the latest
name after competing writes. No-op write/timestamp/event guarantees and active-state/authority fences remain;
post-commit publication failure still reports failure without a success acknowledgement or rollback claim.
EPIC-00011, TICKET-00018/00019 and TASK-00069/00071 reflect this planning-only amendment. John then removed the
profile read's custom sentence too: both tools use Common's standard structured output with matching derived JSON
text. The read still performs a fresh lookup, enforces its own Permission and returns only `{agent_id, name}`;
privacy and authorization guarantees are not response sentences and remain unchanged. No implementation, dependency
change, readiness change, publication or release is authorized by these decisions.

## Planning and Completion

Local TASK files under `planning/tasks/` are canonical for implementation scope, status, dependencies,
acceptance, and evidence. The Board ranks ready work. A TASK is executable only under the rules in
`planning/agents/issue-tracker.md`. Run `./bin/planning-check` for planning integrity and `./bin/build` for the
complete package gate.
