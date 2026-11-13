# Audit outcome coverage

This is the living outcome inventory for [EPIC-00017](../planning/epics/00017-EPIC.md), established by
[TASK-00166](../planning/tasks/00166-TASK.md). It describes **observed source**, **approved targets** and **pending
proof** separately. It is not an audit API, executable registry, qualification score or additional gate.

## Current source versus approved destination

TASK-00122's [typed evidence replacement](audit-evidence.md) supplies direct typed actors, finite
subjects/actions, unique evidence identity, actual time/revisions/relationships/correlation and admitted facts.
All 22 current writer owners and their necessary callers/fixtures use the new construction contract. The TASK
and canonical Work handoff own package verification; this inventory is not independent acceptance.
Unreleased TASK-00123 supports optional last nullable audit storage on the eight account lifecycle handlers,
TASK-00124 on the four invitation handlers, TASK-00125 on reset request/delivery/expiry, and TASK-00154 on custom
Role Permission grant/revoke. Other current writers
still require storage; other unaudited owners remain unchanged. Constructor presence alone proves
neither complete branch coverage nor participation in the actual transaction. No release or consumer qualification
is implied.

The approved destination uses the existing cohesive evidence/repository boundary, explicit optional **last nullable**
audit injection per outcome owner, direct typed originating identities, package-defined actions, unique evidence
identity, trusted outcome time, precise subject/relationship references and bounded admitted context. Exact APIs
belong to [TASK-00121](../planning/tasks/00121-TASK.md)/[TASK-00122](../planning/tasks/00122-TASK.md) and the family
suppliers below; this guide deliberately supplies no speculative factories or constructor examples.

- Omitted/null means disabled, with **no durable-audit promise**. Configured write failure is not disabled and must
  not be swallowed to continue unaudited. Mixed per-owner opt-in is intentional; one binding does not enable all paths.
- Enabled work admits required originating attribution before its first mutation requiring evidence, including a
  terminal transition inside an apparent claim path. Discovery/validation may precede it. Disabled and pure no-change
  paths gain no new audit-only actor requirement; existing business-required provenance remains required.
- A UserId/AgentId identifies origin, not authority. TASK-00121 now supplies the distinct fixed-nil SystemId and
  AnonymousId values; [identity guidance](actor-identities.md) and the shared replacement distinguish
  value construction from completed writer/caller/consumer qualification. They are not accounts, fallback actors or privileges. Trusted callers establish
  origin; workers do not replace an admitted origin with their own identity. Real invoker authorization, target
  policy and transaction-duration fences remain.
- Meaningful committed changes receive enabled evidence inside the actual mutation transaction before commit.
  Validated no-change and rejected/rolled-back work receive no new committed-mutation record. Guaranteed durable
  rejected-attempt storage is excluded. An outward refusal can nevertheless follow a committed safety mutation.
- Concurrent losing work rolls back staged evidence, not a concurrent winner. Lost commit acknowledgement is
  uncertainty, not rollback proof. Post-commit publication faults leave state/evidence committed and retain the
  owner's failure policy. Correlation/evidence IDs do not provide universal command replay or deduplication.
- Later opt-in does not backfill old outcomes. Original retained-key resolution neither reissues nor fabricates old
  evidence; do not change retained keys to manufacture auditing. Direct consumer repository bypasses are not covered.

## Inventory reconciliation

The historical allocation was 37 handlers (20 User/credential/session + 11 administration + six Feature/policy),
seven synchronous User operations and eight Agent entries across six services: 52 accounted entry points, **not**
52 writers or an exhaustive branch count. Current source has **39 CommandHandlers + seven synchronous User entries
+ six remaining Agent service entries = 52**, counting recovery as a delegating entry, not another evidence writer.

Two historical Agent service entries are now `ProvisionAgentHandler` and `RotateAgentCredentialHandler` under
[TASK-00182](../planning/tasks/00182-TASK.md); the old provisioning/rotation services/results/warnings are removed,
not additional owners. Both use void Commands and independent authorized outcome Queries, sync or async. No caller
warning is returned or reconstructed. Confirmed issuance survives both publishers failing; uncertainty still throws.
[TASK-00162](../planning/tasks/00162-TASK.md) retains their audit-family ownership, not administration's four handlers.

The former AuthenticationService's seven entries now belong to UserAuthentication (activate/login), SessionLifecycle
(refresh/logout), PasswordManagement (change/reset) and EmailChangeConfirmation (confirm). Agent delivery/recovery/
maintenance now live under AgentCredentialOperation; revocation remains Agent/Credential. The PermissionAssignment
coordinator is internal and currently owns three transactions, not an extra entry or evidence supplier. Recovery
calls delivery, TokenIssuer and revocation helpers do not independently record owning outcomes, and `nextRunAt()` is
pure scheduling. No other handler additions/removals were found in the current tree. Historical manifests retain
historical counts; use this reconciliation rather than silently reinterpreting them as current source.

## How to read the matrices

Each row's linked name is its **actual source owner**. Handler names abbreviate the corresponding `handle()` entry.
The supplier headings name the family implementation and acceptance owner. **All rows inherit the approved optionality,
admission, failure and consumer rules above**, plus their fact/boundary profile below. Row-specific facts refine that
profile; they are requirements for future evidence, not claims those fields already exist in current AuditEvidence.

Observed audit: **W** = an explicit required current write on the meaningful path; **C** = only the stated conditional
paths write; **—** = no current package evidence insertion; **delegate** = no independent record. W is source evidence,
not a current optional-audit/conformance pass. Proposed actions below describe semantic outcomes, not invented enum
cases or factory names. Every family still needs candidate-compatible enabled/disabled/admission/atomicity evidence.

| Profile | Origin versus real-invoker authorization; first enabled attribution admission | Required evidence facts and trusted time | Actual transaction / publication / external-effect boundary |
| --- | --- | --- | --- |
| U — account | Existing actor input remains; registration deliberately establishes AnonymousId in the shared replacement. Consumer entry authorization, plus exact-target correction/recovery ports where present. Admit before first User/grant write. | User outcome, related activation delivery/origin and initial Roles where applicable; actual authentication/security/canonical-email/reservation counters only when changed. No invented universal User revision. Reuse actual transition/issuance Clock sample, distinguishing separately sampled times. | Named handler owns UoW: User/claims/grants/delivery/cancellation/sessions as applicable. Success fact after commit; ordinary publisher fault throws. Optional delivery subscriber may invoke provider only after issuance commit. |
| H — human delivery | Adapted delivery/retry/resend Commands preserve typed origin with explicit kind, not authenticated worker proof. Preserve original initiation where retained; actual worker/entry authority is separately consumer-owned. Enabled outcome attribution must precede its first required transition, not the external send's eventual acknowledgement. | User plus typed grant/delivery relationships, actual generation/revision/claim identity and invitation origin or reset recovery binding where applicable. Clock claim and outcome samples are distinct; no evidence for temporary claim alone. | Handler separately commits claim, invokes decrypt/provider outside transaction, then commits fenced outcome + evidence. Delivered fact only for successful delivery; committed retryable/permanent failure is meaningful too. Outcome rollback cannot undo a send or the prior claim. |
| X — human expiry | Trusted scheduler's explicit origin (autonomous System target), never infer authority from actor label. Existing entry/identity/expiry admission retained; attribute before first expiry mutation. | User, exact grant/delivery, actual revision/reservation/origin; use existing trusted caller-supplied command occurredAt, not a fabricated Clock sample or DB insert time. | Handler UoW cleans original expired material/authority; expiry fact after commit. Absent/stale/early/already-clean or losing no-work branches have no new outcome; preserve family-specific conflict behavior. No provider call. |
| S — sensitive User | Credential verification/current authenticated User/session/grant authority is independent of attribution. Public activation/reset must not infer authenticated initiator from target ID. Supplier explicitly admits appropriate origin before first evidence-requiring mutation; retain existing credential concealment. | User/session/grant references, actual authenticationAuthorityRevision/security/canonical-email/reservation and grant revisions where applicable, never invented session counters. Reuse authenticatedAt/changedAt/completedAt/confirmedAt or trusted revocation sample. No secret/token/hash evidence. | Narrow capability owns UoW, including coupled session/grant/User writes. Existing success facts after commit except refresh's direct result; ordinary publication fault throws. TokenIssuer/session/reset revocation helpers have no independent evidence ownership. |
| R — Role | Required UserId actor remains even disabled; consumer administrative entry authorization is separate. Keep managed/reserved/tier/reference policy and caller expected intent. Attribute before first Role write. | Typed Role and real RoleRevision (incarnation plus state revision); Permission relationship on membership. Clock created/renamed/removed/granted/revoked time. Creation has only successor; removal only predecessor. | Named handler UoW; final expected-state/name/reference/registration fences, including validated no-ops. Existing success fact only after change/commit; ordinary publication propagation, no interception. |
| M — User membership | Required UserId actor; consumer entry authority plus reserved Role/target rules retained. Attribute before changed membership write. | User primary, typed Role relationship and authoritative Role reference as applicable; do not invent User revision or mutate Role/authentication just for audit. Existing assignedAt/removedAt Clock sample. | Handler UoW and full-state/reference fence; same-membership no-op still validates. Fact only on change after commit. |
| A — Agent administration | Required UserId actor or UpdateAgent's UserId/AgentId initiator. Entry/self-target authority remains consumer/adapter-owned; ADMIN_SAFE is eligibility, not authorization. Attribute before required write inside actual owner transaction. | Agent plus changed Permission relationships; complete before/after assignment revisions/set where applicable. Rename uses exact repository-returned persisted changedAt from lazy post-admission Clock; no synthetic name revision/stale snapshot. | Update handler owns rename UoW. Three Permission handlers currently delegate transaction ownership to coordinator; TASK-00157 must reconcile explicit handler evidence inside that same transaction, not nested commits or post-return writes. Facts publish afterward. |
| F — Feature | All six current Feature/policy Commands lack actor input. Target adds optional direct typed provenance; autonomous provisioning supplies System once per Command, preserving real User/Agent origins otherwise. Admit before first changed subject, not necessarily discovery. Entry authority remains consumer-owned. | Typed Feature and Permission relation, actual Feature revision (creation OFF/revision 1; removal no successor). Current Feature handlers lack an outcome Clock; supplier adds trusted use-case time without claiming stored timestamps. Batch shares actual message correlation. | Handler owns one transaction; final state/revision/reference fences retained even no-op. Provision discovery precedes UoW, default Permission lazily resolved only for new Features. Existing facts after commit. |
| P — policy | Same actor gap/target rule as F; consumer protects preview and apply. Enabled admission before first changed Role/Permission, not read-only plan. | Each changed Role/Permission, related Permission memberships; actual Role incarnation/revisions, no invented Permission revisions. Reuse reconciliation occurredAt Clock sample and real command correlation. Feature references are guards, not mutated subjects. | Handler replans inside one UoW and applies complete plan under final state/reference/tier fences, including nonwriting unchanged comparisons. Existing ManagedPolicyReconciled still publishes for empty/unchanged plan, without invented mutation evidence. Planner/preview do not write audit. |
| I — Agent issuance | Current operationAuthorization authorizes actual invocation independently and returns nullable typed originating provenance; durable retention for later unaudited delivery remains family work. Target retains real typed admitted origin for workers separately from fresh invocation/read authority. Admit before reservation/new issuance; pure original-key resolution gains no audit-only actor, but retains existing authorization. | Agent, original scoped operation key/delivery/destination binding, credential identity/revision, original predecessor for rotation, issuedAt Clock sample and actual cohort/incarnation/generation where applicable. Never raw canonical request/cipher/secret. | Handler UoW includes reservation, Agent/operation/material and current audit. Same-key resolves original without write/publication; conflict refuses. Confirmed new issuance publishes afterward with contained publisher faults; lost acknowledgement throws sanitized uncertainty. No direct sink send here. |
| V — Agent revocation | Direct typed actor remains required; consumer caller policy is separate. No System fallback supplies missing origin. | Agent, actual credential/revision and cancelled original operation relationship, revokedAt Clock; preserve cohort/restoration evidence, not fabricated successors/history. | Lifecycle service UoW couples Agent revocation, repository-owned original-operation cancellation and audit. Ordinary post-commit publication fault throws. Already-revoked is conflict, not a new success. |
| D — Agent delivery | Fresh scope/delegated delivery authorization is independent of retained originating actor (typed retention still missing). Admit attribution before first meaningful transition, including claim-path expiry/exhaustion. | Agent/original scoped key/delivery, actual operation state/credential revisions, claim/authority epoch/cohort generation/incarnation and original origin. Trusted transition Clock time, not worker/message receipt time. | Delivery service separates claim, fresh admission, protected outside-transaction sink invocation/receipt lookup and outcome UoWs. Epoch/deadline/Agent/receipt fences stay mandatory. Safe result containment distinguishes unavailable/deferred/rejected/indeterminate, not issuance's publication exception. No outcome Event publisher. |
| T — Agent maintenance | Current maintenance scope/target/key/deadline authorization remains independent. Explicit System for autonomous maintenance target, never fallback for absent original User/Agent. Admit before first meaningful expiry/failure/rewrap/cleanup acknowledgement. | Original Agent/key/delivery relationships, actual material/state revisions, epoch/generation/incarnation and trusted Clock outcome time. Do not record ciphertext, key material or sink payload. | Service owns bounded fenced rewrap/expiry UoW; cleanup has admission UoW, external exact-tuple removal/tombstone, then separately fenced acknowledgement UoW. Already acknowledged/poll/helper work gets no duplicate. Safe maintenance results, no Event publisher; uncertainty cannot prove external removal did not happen. |

## User account and credential handlers (20)

### Account — [TASK-00123](../planning/tasks/00123-TASK.md), profile U

All eight rows now support optional last nullable storage. `W` below means evidence **when enabled**, not a
promise for omitted/null composition. Existing business-required actors remain mandatory; registration explicitly
establishes AnonymousId. No additional audit-only actor field is needed. The existing complete typed outcome sites
remain inside the same mutation transactions; disabled execution retains all business admission and coupled effects.
There is no pure no-change command success branch here: same-email registration recovery replaces a generation;
invalid repeated lifecycle transitions reject. Rejections and lost transactions add no committed record. See
[current constructors](user-lifecycle-contracts.md#optional-account-audit-composition).

| Entry | Observed audit and meaningful/conditional/no-work disposition |
| --- | --- |
| [InvitePendingUser](../src/Application/AccessControl/User/CommandHandler/InvitePendingUserHandler.php) | W `user.invited`, direct typed actor. Pending User/private activation generation/encrypted delivery commit together; duplicate/invalid invitation rejects, not generic replay. |
| [RegisterUser](../src/Application/AccessControl/User/CommandHandler/RegisterUserHandler.php) | W `user.registered`, explicitly established AnonymousId. Pending User + configured nonempty default Role set + registration-origin grant/delivery under atomic Role/email fences; invalid defaults/duplicates reject. |
| [CorrectPendingInvitation](../src/Application/AccessControl/User/CommandHandler/CorrectPendingInvitationHandler.php) | W `user.pending_invitation_corrected`, direct UserId actor. Exact-target authorization; wrong origin/expected generation rejects. Corrected address and revoked predecessor/fresh private delivery are one outcome, not helper records. |
| [RecoverPendingRegistration](../src/Application/AccessControl/User/CommandHandler/RecoverPendingRegistrationHandler.php) | W `user.pending_registration_recovered`. Exact-target recovery port and expected delivery; wrong-address correction or expired same-email reissue retains User/Roles, revokes predecessor and creates registration-origin successor. Same-email validation is not itself another record; recovery still creates a meaningful new generation. |
| [EnableUser](../src/Application/AccessControl/User/CommandHandler/EnableUserHandler.php) | W `user.enabled`, User actor. Real lifecycle transition; invalid state rejects, no implicit new/reset recovery. |
| [DisableUser](../src/Application/AccessControl/User/CommandHandler/DisableUserHandler.php) | W `user.disabled`, User actor. Coupled security-version advance/session and recovery cancellation; enable cannot resurrect old authority. Invalid transition rejects. |
| [DeleteUser](../src/Application/AccessControl/User/CommandHandler/DeleteUserHandler.php) | W `user.deleted`, User actor. Coupled claims/security/session/recovery effects; unsupported pending deletion/invalid transition rejects. |
| [RestoreUser](../src/Application/AccessControl/User/CommandHandler/RestoreUserHandler.php) | W `user.restored`, User actor. Requested valid restored state; pending restoration creates fresh private invitation, not registration recovery or resurrection. Distinguish lifecycle time and separately captured issuance time. |

### Invitations — [TASK-00124](../planning/tasks/00124-TASK.md)

All four handlers now support optional final audit storage. Omitted/null intentionally disables evidence; configured
persistence failure aborts the actual staged outcome. Existing typed actors remain required, including expiry's
current typed Command/Event contract. See [composition](credential-delivery.md#optional-invitation-audit-composition-unreleased).

| Entry | Profile | Observed audit and outcome disposition |
| --- | --- | --- |
| [DeliverUserInvitation](../src/Application/AccessControl/ActivationGrant/CommandHandler/DeliverUserInvitationHandler.php) | H | W delivered/retryable/permanent-failure action on committed outcome, direct typed origin. Exact origin/generation/claim required; temporary claim is not another record. Invalid/stale outcome rejects; send cannot be rolled back. |
| [ExpireInvitationDelivery](../src/Application/AccessControl/ActivationGrant/CommandHandler/ExpireInvitationDeliveryHandler.php) | X | Optional final repository (TASK-00124): confirmed expiry records typed User/grant/delivery revisions, trusted admitted time and message correlation inside the outcome transaction. Exact original issued expired generation loses material, not activation authority. Wrong generation, early/already-clean or CAS loss returns no-work without evidence. |
| [ResendInvitationDelivery](../src/Application/AccessControl/ActivationGrant/CommandHandler/ResendInvitationDeliveryHandler.php) | U | W `user.invitation_delivery.resent`, direct typed origin. Fresh grant/delivery replaces predecessor while preserving origin; invalid User/state rejects, not same-key resolution. |
| [RetryInvitationDelivery](../src/Application/AccessControl/ActivationGrant/CommandHandler/RetryInvitationDeliveryHandler.php) | H | W `user.invitation_delivery.retry_requested`, direct typed origin. Existing usable same generation/ID becomes due; expired/unretryable rejects before mutation. No provider call within retry UoW; post-commit subscriber can fail after retry persists. |

### Password reset — [TASK-00125](../planning/tasks/00125-TASK.md)

All three handlers support optional final nullable storage. Public requests explicitly establish AnonymousId;
existing delivery and now-typed expiry provenance remain required independently of storage. See
[composition and failure boundaries](credential-delivery.md#optional-password-reset-audit-composition-unreleased).
Synchronous password change/reset remains exclusively TASK-00127; this slice does not complete that journey.

| Entry | Profile | Observed audit and outcome disposition |
| --- | --- | --- |
| [RequestPasswordReset](../src/Application/AccessControl/PasswordResetGrant/CommandHandler/RequestPasswordResetHandler.php) | U | W `user.password_reset_requested` only for eligible User, explicitly established AnonymousId. Unknown/inactive remains concealed no-work/no evidence. New generation replaces prior authority under User-before-grant fence and immutable RecoveryAuthority binding; no User security bump for request. |
| [DeliverPasswordReset](../src/Application/AccessControl/PasswordResetGrant/CommandHandler/DeliverPasswordResetHandler.php) | H | W committed delivery/failure, direct typed origin. Current User recovery binding revalidated in claim/outcome transactions; stale/cancelled/successor cannot be resurrected. Delivery success is not redemption authority. |
| [ExpirePasswordResetDelivery](../src/Application/AccessControl/PasswordResetGrant/CommandHandler/ExpirePasswordResetDeliveryHandler.php) | X | W when enabled: committed original material expiry records exact grant/delivery revisions, EXPIRED status, trusted admitted time and message correlation. Absent/stale/early/already-clean/CAS loss is no-work with no evidence. Does not impose delivery success on redemption or invent a successful reset. |

### Email change — [TASK-00126](../planning/tasks/00126-TASK.md)

| Entry | Profile | Observed audit and outcome disposition |
| --- | --- | --- |
| [RequestEmailChange](../src/Application/AccessControl/EmailChangeGrant/CommandHandler/RequestEmailChangeHandler.php) | U | W optional `user.email_change_requested` for both self-service and administrative requests. Required User actor; cross-User target authorization port. Reservation revision and new delivery commit together, retaining the unchanged terminal predecessor where present, without confirmed-address/security revision change. |
| [CancelEmailChange](../src/Application/AccessControl/EmailChangeGrant/CommandHandler/CancelEmailChangeHandler.php) | U | W optional `user.email_change_cancelled` for both self-service and administrative cancellation. Actor/target authorization and reservation-bound grant cancellation retained; absent/inconsistent pending authority rejects, not a fabricated no-op. |
| [DeliverEmailChange](../src/Application/AccessControl/EmailChangeGrant/CommandHandler/DeliverEmailChangeHandler.php) | H | W optional committed delivery/failure, original direct UserId actor. Claims/external send stay separate from outcome evidence; confirmation is a separate outcome. |
| [ExpireEmailChange](../src/Application/AccessControl/EmailChangeGrant/CommandHandler/ExpireEmailChangeHandler.php) | X | W optional `user.email_change_expired` with direct typed provenance. Full reservation/authority/material expiry, including delivered/permanent-failure/material-free and inactive User states. Missing/stale/early/already-terminal is no-work; coupled User/grant persistence conflict must not become partial cleanup. |

### Session Command — [TASK-00127](../planning/tasks/00127-TASK.md), profile S

| Entry | Observed audit and outcome disposition |
| --- | --- |
| [RevokeSession](../src/Application/AccessControl/RefreshSession/CommandHandler/RevokeSessionHandler.php) | C administrative only `refresh_session.administratively_revoked` with typed session/revision facts and no free-text reason capture; self-service other-session revocation missing. Required User actor; cross-User authorization and reason required. Current session is prohibited here (use logout); unusable session rejects. Business reason remains mandatory where required, but the shared replacement omits it from evidence. |

## Administration handlers (11)

| Supplier / profile | Actual entry | Observed audit and outcome disposition |
| --- | --- | --- |
| [TASK-00153](../planning/tasks/00153-TASK.md) / R | [CreateCustomRole](../src/Application/AccessControl/Role/CommandHandler/CreateCustomRoleHandler.php) | —. Create custom Role, authoritative new incarnation/revision; uniqueness/reserved-name refusal is not no-change. |
| TASK-00153 / R | [RenameCustomRole](../src/Application/AccessControl/Role/CommandHandler/RenameCustomRoleHandler.php) | —. Actual name/revision change; fresh same-name is validated no-change without timestamp/revision/event. Stale expected token rejects even same-name; name/reference fences retained. |
| TASK-00153 / R | [RemoveCustomRole](../src/Application/AccessControl/Role/CommandHandler/RemoveCustomRoleHandler.php) | —. Remove authoritative predecessor under caller revision and User/registration/reference fence; missing/managed/referenced/stale target rejects, no invented removal successor. |
| [TASK-00154](../planning/tasks/00154-TASK.md) / R | [GrantPermissionToCustomRole](../src/Application/AccessControl/Role/CommandHandler/GrantPermissionToCustomRoleHandler.php) | W when enabled: `role.permission_granted`, related Permission and exact before/after Role revisions. Already-held validates full Role/Permission eligibility then no-change without evidence. |
| TASK-00154 / R | [RevokePermissionFromCustomRole](../src/Application/AccessControl/Role/CommandHandler/RevokePermissionFromCustomRoleHandler.php) | W when enabled: `role.permission_revoked`, related Permission and exact before/after Role revisions. Already-absent validates then no-change without evidence. Existing protected-membership revocation remains allowed; managed/reserved Role policy remains. |
| [TASK-00155](../planning/tasks/00155-TASK.md) / M | [AssignRoleToUser](../src/Application/AccessControl/User/CommandHandler/AssignRoleToUserHandler.php) | —. Changed User/Role relationship; already-held no-change still full-state/reference fenced. Preserve reserved-role policy; no fabricated authentication/Role mutation. |
| TASK-00155 / M | [RemoveRoleFromUser](../src/Application/AccessControl/User/CommandHandler/RemoveRoleFromUserHandler.php) | —. Changed User/Role relationship; already-absent no-change still validates/fences; forbidden removal rejects. |
| [TASK-00156](../planning/tasks/00156-TASK.md) / A | [UpdateAgent](../src/Application/AccessControl/Agent/CommandHandler/UpdateAgentHandler.php) | —. Required AgentUpdateInitiator exposes real User/Agent ID. Atomic repository rename returns exact persisted changedAt; null is no-change. No pre-read/compensating read or synthetic name revision needed. |
| [TASK-00157](../planning/tasks/00157-TASK.md) / A | [GrantPermissionToAgent](../src/Application/AccessControl/Agent/CommandHandler/GrantPermissionToAgentHandler.php) | —. Coordinator grant transaction; changed assignment advances actual assignment revision. Already-held validates eligible Permission then no-change. |
| TASK-00157 / A | [RevokePermissionFromAgent](../src/Application/AccessControl/Agent/CommandHandler/RevokePermissionFromAgentHandler.php) | —. Coordinator revoke transaction; changed relationship/revision. Already-absent no-change; actual lookup/lifecycle/cohort refusals retained. |
| TASK-00157 / A | [ReplaceAgentPermissions](../src/Application/AccessControl/Agent/CommandHandler/ReplaceAgentPermissionsHandler.php) | —. Coordinator complete-set transaction; expected assignment revision and exact eligible set checked even unchanged. Real change records complete before/after set/revisions once, not each helper write. |

[AgentPermissionAssignmentCoordinator](../src/Application/AccessControl/Agent/PermissionAssignment/AgentPermissionAssignmentCoordinator.php)
currently owns grant/revoke/replace UoWs. TASK-00157 alone owns that transaction reconciliation; it is not another
handler, acceptance supplier or record. No evidence may be appended after its commit or duplicated by helpers.

## Feature and policy handlers (six)

| Supplier / profile | Actual entry | Observed audit and outcome disposition |
| --- | --- | --- |
| [TASK-00158](../planning/tasks/00158-TASK.md) / F | [CreateFeature](../src/Application/AccessControl/Feature/CommandHandler/CreateFeatureHandler.php) | —. New OFF/revision-1 Feature with validated Permission; duplicate/invalid reference refuses, not replay success. |
| TASK-00158 / F | [RemoveFeature](../src/Application/AccessControl/Feature/CommandHandler/RemoveFeatureHandler.php) | —. Expected revision plus complete CURRENT discovery/retirement and final CAS; candidate absence is insufficient. Record removed predecessor only; refused referenced/managed/current Feature is not a mutation. |
| [TASK-00159](../planning/tasks/00159-TASK.md) / F | [SetFeatureStatus](../src/Application/AccessControl/Feature/CommandHandler/SetFeatureStatusHandler.php) | —. Changed status/revision; same status validates expected revision and final full state without event/write. Stale intent rejects even no-change. |
| TASK-00159 / F | [SetFeaturePermission](../src/Application/AccessControl/Feature/CommandHandler/SetFeaturePermissionHandler.php) | —. Changed binding/revision and typed Permission relation; same binding still validates reference/state. Any existing Permission tier remains eligible; audit grants no Permission. |
| [TASK-00160](../planning/tasks/00160-TASK.md) / F | [ProvisionFeatures](../src/Application/AccessControl/Feature/CommandHandler/ProvisionFeaturesHandler.php) | —. One record per newly created Feature, shared real command correlation; mixed/all-existing/empty discovery distinguish changes from no-work. Lazy default Permission, explicit one-per-Command origin target, atomic batch. |
| [TASK-00161](../planning/tasks/00161-TASK.md) / P | [ReconcileManagedPolicy](../src/Application/AccessControl/ManagedPolicy/CommandHandler/ReconcileManagedPolicyHandler.php) | —. Per changed Role/Permission create/reconcile/remove, not unchanged subjects, whole raw plan or incidental calls. Mixed batch rolls back together on loss. Summary Event remains even all-unchanged/empty; Feature checks are reference guards only. |

## Seven synchronous User operations

All seven belong to [TASK-00127](../planning/tasks/00127-TASK.md), profile S. These remain sensitive synchronous
contracts, not serializable Commands; token results are not evidence or arbitrary wire payloads.

| Actual owner / entry | Observed audit and outcome disposition |
| --- | --- |
| [UserAuthentication::activate](../src/Application/AccessControl/User/Authentication/UserAuthentication.php) | —. Valid single-use grant activates same User and starts session atomically, advances actual authority revision but preserves authenticationVersion. Credential/expiry/identity refusal is not committed outcome; deliberate public origin is not inferred authenticated User. |
| UserAuthentication::login | —. Successful login with or without verified rehash is meaningful: exactly one authority revision and coupled new session. Throttle/dummy-hash/rejection retains concealment; ordinary login/rehash does not revoke reset authority. |
| [SessionLifecycle::refresh](../src/Application/AccessControl/RefreshSession/SessionLifecycle.php) | —. Successful rotation is meaningful; bounded most-recent credential conflict is no-change. Terminal replay commits compromised-session revocation before outward AuthenticationRequired: target evidence is revocation, never successful refresh or rejected-attempt logging. Concurrent helper/winner no-op must not duplicate it. |
| SessionLifecycle::logout | —. Credential-selected current-session revocation and post-commit CurrentSessionLoggedOut; invalid/unrelated credentials reject. Preserve helper's concurrent/already-revoked resolution rather than inventing another mutation. |
| [PasswordManagement::changePassword](../src/Application/AccessControl/User/PasswordManagement.php) | W `user.password_changed`, authenticated UserId. Password/security/authority revision, reset cancellation and session revocations are coupled. Same submitted password is not a generic no-change shortcut. |
| PasswordManagement::resetPassword | W `user.password_reset_completed`, explicit nullable typed provenance now required before enabled mutation; target User is not initiator proof. Valid single-use generation/binding consumption, password/security/authority and session changes commit together; no delivery-success prerequisite. Target origin admission must remain explicit. |
| [EmailChangeConfirmation::confirmEmail](../src/Application/AccessControl/EmailChangeGrant/EmailChangeConfirmation.php) | W `user.email_change_confirmed`, explicit nullable typed provenance now required before enabled mutation. Credential/reservation-bound consumption, canonical-email/security/authority revision, reset and session invalidation commit together. No tokens issued; expired/stale/wrong-purpose refuses. |

## Eight historical Agent entries, reconciled to current owners

These belong to [TICKET-00047](../planning/tickets/00047-TICKET.md), not the four Agent administration handlers.
The first two rows are already counted in the current 39 handlers; count them **once**.

| Supplier / profile | Actual owner / entry | Observed audit and outcome disposition |
| --- | --- | --- |
| [TASK-00162](../planning/tasks/00162-TASK.md) / I | [ProvisionAgentHandler](../src/Application/AccessControl/Agent/CommandHandler/ProvisionAgentHandler.php) (formerly provision service) | W `agent.provisioned` only for new retained operation. Original-key resolve neither reissues nor republishes; conflicting request refuses. Authorization now supplies typed originating provenance for this issuance; later worker retention remains family work. Void completion/independent Query, no returned warning. |
| TASK-00162 / I | [RotateAgentCredentialHandler](../src/Application/AccessControl/Agent/CommandHandler/RotateAgentCredentialHandler.php) (formerly rotate service) | W `agent.credential_rotated` for new rotation. Exact original predecessor/expected credential revision, retained key and cancelled old delivery remain; same-key original resolution no duplicate. Void/Query/publication-containment contract as provision. |
| [TASK-00163](../planning/tasks/00163-TASK.md) / V | [AgentCredentialLifecycleService::revoke](../src/Application/AccessControl/Agent/Credential/AgentCredentialLifecycleService.php) | W `agent.credential_revoked`, required direct typed actor. Coupled original-operation cancellation; already revoked rejects. Ordinary publisher fault still throws after commit. |
| [TASK-00164](../planning/tasks/00164-TASK.md) / D | [AgentCredentialDeliveryService::deliver](../src/Application/AccessControl/AgentCredentialOperation/Delivery/AgentCredentialDeliveryService.php) | —. Confirmed receipt/delivery, committed retryable/permanent failure and expiry/exhaustion reached inside claimDelivery are meaningful. Temporary claims/admission/poll, unchanged recorded-result reconciliation and deferred/no-work are not separate mutation records. Receipt-first restart must not blindly restage; audit failure cannot unsend. |
| TASK-00164 / D | [AgentDeliveryRecoveryService::recover](../src/Application/AccessControl/AgentCredentialOperation/Delivery/AgentDeliveryRecoveryService.php) | delegate. Authorized bounded due Query then actual deliver per original tuple; only delivery owns resulting evidence. Discovery failure is not empty success; restart preserves retained origin/key. `nextRunAt()` remains pure, no actor/audit admission. |
| [TASK-00165](../planning/tasks/00165-TASK.md) / T | [AgentDeliveryMaintenanceService::rewrap](../src/Application/AccessControl/AgentCredentialOperation/Maintenance/AgentDeliveryMaintenanceService.php) | —. Meaningful rewrap, expired-original branch or permanent source-loss terminal transition. Same target key/no material unchanged; temporary source failure retryable without invented success. Rewrapper call remains within bounded fenced transaction. |
| TASK-00165 / T | AgentDeliveryMaintenanceService::expire | —. Meaningful original-material expiry; not-yet-expired/already-material-free is unchanged. Original retained issuance/history remains; no reissue or historical evidence reconstruction. |
| TASK-00165 / T | AgentDeliveryMaintenanceService::cleanup | —. Newly acknowledged exact-tuple cleanup meaningful; already-cleaned returns CLEANED without new evidence, ineligible returns unchanged. External removal may succeed before acknowledgement/audit fails; retain tombstone/history/fences and reconcile uncertainty, never assert external rollback. |

## Evidence and conformance design

**Observed references are not optional-audit passes.** The following existing executable suites locate current
behavior and modeled fences for each supplier. None establishes the future typed/optional contract merely by
existing, injection, terminal planning status or earlier receipts. Family acceptance still requires independent
Review and behavioral QA on its actual candidate. This inventory's checks qualify documentation coverage only.
Detailed candidate/log identities belong in ignored TASK-owned handoffs, not a public evidence registry.

| Acceptance owner | Existing executable starting points (current behavior, not claimed future audit proof) | Remaining owned proof |
| --- | --- | --- |
| TASK-00123 | [Optional account audit](../tests/Application/AccessControl/User/CommandHandler/OptionalLifecycleAuditTest.php), [dispatch outcomes](../tests/Application/AccessControl/User/CommandHandler/UserLifecycleDispatchTest.php), [User lifecycle](../tests/Application/AccessControl/User/CommandHandler/UserLifecycleContractTest.php), [registration recovery races](../tests/Application/AccessControl/User/CommandHandler/RegistrationRecoveryRaceTest.php) | Eight handlers, enabled/null/omitted storage, typed/anonymous/admin provenance, actual revision/grant/message facts, retained admission, coupled state/evidence and failures. Independent acceptance and real consumer qualification remain separate. |
| TASK-00124 | [Invitation delivery](../tests/Application/AccessControl/ActivationGrant/CommandHandler/DeliverUserInvitationHandlerTest.php), [expiry recovery](../tests/Application/AccessControl/CredentialDelivery/QueryHandler/ExpiredCredentialRecoveryTest.php) | Four owners, both origins, retry/resend/expiry and committed failures versus temporary claims. |
| TASK-00125 | [Optional audit/expiry](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/PasswordResetAuditTest.php), [reset request](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/RequestPasswordResetHandlerTest.php), [delivery outcomes](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/DeliverPasswordResetHandlerTest.php), [recovery outcomes](../tests/Application/AccessControl/PasswordResetGrant/RecoveryOutcomeTest.php) | Enabled/disabled composition, anonymous/no-work concealment, typed delivery/failure/expiry, rollback/uncertainty/publication and preserved recovery binding. |
| TASK-00126 | [Email request](../tests/Application/AccessControl/EmailChangeGrant/CommandHandler/RequestEmailChangeHandlerTest.php), [expiry](../tests/Application/AccessControl/EmailChangeGrant/CommandHandler/ExpireEmailChangeHandlerTest.php) | Self-service plus admin, delivery/failure and full reservation expiry. |
| TASK-00127 | [Synchronous contracts](../tests/Application/AccessControl/User/Authentication/SynchronousContractTest.php), [sessions](../tests/Application/AccessControl/RefreshSession/SessionLifecycleTest.php), [RevokeSession](../tests/Application/AccessControl/RefreshSession/CommandHandler/RevokeSessionHandlerTest.php) | Seven synchronous entries and Command, terminal-replay committed revocation versus benign conflict, correct unauthenticated origin. |
| TASK-00153 | [Role revisions](../tests/Application/AccessControl/Role/CommandHandler/RoleRevisionHandlerTest.php) | Create/rename/remove exact incarnation/revisions, stale even no-op, all-writer/reference/rollback/publication. |
| TASK-00154 | [Role membership](../tests/Application/AccessControl/Role/CommandHandler/CustomRoleMembershipHandlerTest.php), [optional audit](../tests/Application/AccessControl/Role/CommandHandler/RolePermissionAuditTest.php) | Both relationships, actual Role revisions/time/correlation, enabled/disabled/no-change, audit/commit rollback, CAS/reference/tier loss, uncertainty and retained evidence after publication failure. |
| TASK-00155 | [User Role assignments](../tests/Application/AccessControl/User/CommandHandler/UserRoleAssignmentHandlerTest.php) | Both changed memberships and complete no-change/reference fences, no fabricated revisions. |
| TASK-00156 | [Agent rename](../tests/Application/AccessControl/Agent/CommandHandler/UpdateAgentHandlerTest.php) | Exact persisted changedAt/typed initiator, changed versus null/no-change. |
| TASK-00157 | [Agent assignments](../tests/Application/AccessControl/Agent/CommandHandler/AgentPermissionAssignmentHandlerTest.php) | Three handlers, one actual mutation/evidence transaction, complete-set revision/relationships and no duplicate helper record. |
| TASK-00158 | [Feature management](../tests/Application/AccessControl/Feature/CommandHandler/FeatureManagementTest.php), [retirement](../tests/Application/AccessControl/Feature/CommandHandler/RemoveFeatureHandlerTest.php) | Create/remove actual state, trusted time/provenance and current-discovery retirement fences. |
| TASK-00159 | [Feature mutation contracts](../tests/Application/AccessControl/Feature/CommandHandler/FeatureMutationContractTest.php) | Both settings, changed versus final-fenced unchanged, no audit-only actor for no-change. |
| TASK-00160 | [Feature provisioning](../tests/Application/AccessControl/Feature/CommandHandler/ProvisionFeaturesHandlerTest.php) | Mixed/new/empty/existing batch, one origin, per-created-subject evidence/correlation. |
| TASK-00161 | [Policy apply](../tests/Application/AccessControl/ManagedPolicy/CommandHandler/ReconcileManagedPolicyHandlerTest.php), [unchanged fences](../tests/Application/AccessControl/ManagedPolicy/CommandHandler/ManagedPolicyUnchangedFenceTest.php) | Per changed Role/Permission, truthful revisions, entire-batch atomicity and retained summary Event. |
| TASK-00162 | [Provision](../tests/Application/AccessControl/Agent/CommandHandler/ProvisionAgentHandlerTest.php), [rotate](../tests/Application/AccessControl/Agent/CommandHandler/RotateAgentCredentialHandlerTest.php), [dispatch](../tests/Application/AccessControl/Agent/CommandHandler/AgentIssuanceDispatchTest.php) | Paired void handlers, typed retained-origin hydration/restart, new versus original-key, uncertainty and both publisher faults without warning resurrection. |
| TASK-00163 | [Revocation](../tests/Application/AccessControl/Agent/Credential/AgentCredentialLifecycleServiceTest.php) | Typed origin, correlated cancellation/evidence transaction and ordinary publication throw. |
| TASK-00164 | [Delivery](../tests/Application/AccessControl/AgentCredentialOperation/Delivery/AgentCredentialDeliveryServiceTest.php), [recovery](../tests/Application/AccessControl/AgentCredentialOperation/Delivery/AgentDeliveryRecoveryServiceTest.php), [uncertainty](../tests/Application/AccessControl/AgentCredentialOperation/Delivery/AgentWorkerUncertaintyTest.php) | Retained origin from TASK-00162, claim expiry/exhaustion, receipt-first restart, committed outcomes versus polls, external-effect truth. |
| TASK-00165 | [Maintenance](../tests/Application/AccessControl/AgentCredentialOperation/Maintenance/AgentDeliveryMaintenanceServiceTest.php) | All three methods, rewrap expiry/source loss and external cleanup/acknowledgement split; unchanged/uncertain dispositions. |

[TASK-00167](../planning/tasks/00167-TASK.md) owns reusable executable conformance after the actual shared contract,
not this document. Design the supported test binding around ordinary shared transactions and complete mapping:

1. Persist a meaningful mutation and complete typed evidence in the **same actual connection/transaction**; reopen
   storage and assert modeled durable contents, not only an active flag or collaborator order.
2. Inject configured audit failure and concurrent loss: neither staged mutation nor its evidence survives. Include
   negative controls where audit is misbound to another connection/transaction, independently committed or silently
   discards unsupported facts; the qualification must detect these wrong compositions.
3. Fail publisher/subscriber after confirmed commit: both state and evidence remain. Preserve ordinary primary throw,
   the narrow void Agent issuance containment exception, and both actual outcomes of lost commit acknowledgement.
4. Exercise enabled versus omitted/null, missing origin before first required mutation, existing required actors even
   disabled, pure no-change without new audit-only actor, same-key resolution and later opt-in without backfill.
5. Assert complete changed-subject batch records/shared correlation, no records for unchanged subjects, and actual
   committed replay revocation/failed delivery/claim expiry/exhaustion rather than mere return-status classification.
6. Separate external send/cleanup and their acknowledgement: database failure cannot undo them. Rewrap remains inside
   its bounded transaction. Helpers/recovery cannot duplicate evidence. Preserve real epochs/revisions/restoration fences.

[TASK-00168](../planning/tasks/00168-TASK.md) owns an executable ordinary Role create/rename/remove demonstration,
including real revisions/incarnation/removal references, using the actual upgraded mapping and TASK-00167 binding.
[TASK-00169](../planning/tasks/00169-TASK.md) owns final live-source/candidate-compatible cross-family reconciliation
and remaining integrated proof/current guide/Unreleased alignment. No family postpones its own checks to that task.
[TASK-00150](../planning/tasks/00150-TASK.md)'s [structural inventory](contract-inventory.md) remains separate:
declaration/member coverage is not outcome qualification, and this matrix introduces no scanner or second gate.

## Consumer mapping, access and qualification boundary

An enabled consumer adapter must preserve actor **kind plus ID** (equal nil UUID text must not collapse System and
Anonymous), evidence identity, typed subject/action, trusted occurrence time, all applicable revision/incarnation
facts, typed relationships and scope-preserving correlation. Missing/unsupported evidence must fail, not disappear.
Creation/removal cannot manufacture absent states. Map complete current persisted shapes directly under
[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md); no old actor-string reader, compatibility facade,
migration/backfill or generic audit reporting API is supplied by this work.

Protect evidence reads, storage and retention. Admit bounded action-specific safe fields, including any free-text
reason: PHP string types, UUID appearance and regex alone do not sanitize content. Exclude raw Commands/plans/entities,
credentials/tokens/hashes/ciphertext/keys, sink payloads, diagnostic exceptions and unnecessary personal data. Neither
evidence nor identifiers are automatically safe public/log response data. Audit attribution grants no permission.

Actual consumer qualification remains **unexecuted here**: real adapter/schema round trips, actual shared connection/
transaction identity, both concurrent winner orders/all writers/locks, rollback and uncertain commit, process restart,
real clocks/crypto/keys/sinks/receipts/tombstones, restoration/reconciliation/old-binary exclusion and current activation/
use authorization. Package fixtures and this document do not establish any of these. Consumers retain transport,
bootstrap, policy/grants/target scope and operational ownership; no sibling implementation or schema operation follows.

Agent OS's business-aware RoleMutationUnitOfWork/RoleMutationAudit/PostgresRoleMutationAudit workaround may be retired
only after the upgraded general mapping/schema, explicit package injection/provenance and **actual** shared-connection
failure/concurrency/publication qualification are established. Current adapters are not assumed compatible. This is a
prerequisite description, not authority to edit consumers or remove the workaround.

## Maintaining this inventory

Refresh the complete actual source inventory on owner additions/removals/conversion, not a sample of injected writers.
Reconcile old-to-new ownership once, retain conditional branches and profiles, then link candidate-compatible family
acceptance/check evidence when it actually exists. Do not replace pending proof with task status or historical totals.
Keep detailed logs/candidate identities private in TASK-owned ignored evidence; tracked docs/checks require no private
run files. Documentation-only validation follows [Testing](engineering/standards/Testing.md); do not add product tests
for this Markdown. Independent technical Review and nonvisual QA challenge completeness/source truth separately.
