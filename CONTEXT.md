# Fight AccessControl Context

## Purpose

Fight AccessControl owns framework-neutral identity, credential, session, authorization, and account-lifecycle
behavior shared by Fight applications. The repository-local behavioral and security authority is
[TICKET-00001](planning/tickets/00001-TICKET.md).

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
`feature/task-00035-agent-mcp-authorization`. The [MCP authorization guide](docs/agent-mcp-authorization.md) records
three additive public Application contracts: repeatable `RequiresAgentPermission` on Tool `handle()`, immutable
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
  Provisioning trims fixed Unicode edge whitespace and preserves case/internal whitespace without NFC conversion;
  rotation binds its original target/predecessor/destination tuple. Retained keys keep original request/issuance.
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

Production dependencies flow `Domain <- Application`. Domain owns invariants and lifecycle state. Application
owns a synchronous secret-bearing authentication service, explicit non-sensitive commands and queries, portable
ports, immutable token/read results, and transaction orchestration. This package has no production Adapter layer and no
framework dependency. Consumers own clients, persistence, HTTP and cookie adapters, cryptographic keys, mail,
queues, realtime delivery, hosting, and runtime composition.

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
This exception does not change revocation, AuthenticationService or other CommandHandlers. Failure/restart behavior
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
