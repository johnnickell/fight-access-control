# Audit outcome coverage

This is the current-source outcome inventory for [EPIC-00017](../planning/epics/00017-EPIC.md), established by
[TASK-00166](../planning/tasks/00166-TASK.md) and reconciled by [TASK-00169](../planning/tasks/00169-TASK.md).
It separates implemented package behavior, original independent supplier acceptance and unexecuted consumer
qualification. It is not an audit API, executable registry, qualification score or additional gate.

## Current implemented contract

TASK-00122's [typed evidence replacement](audit-evidence.md) supplies direct typed actors, finite
subjects/actions, unique evidence identity, actual time/revisions/relationships/correlation and admitted facts.
All covered mutation owners now implement optional nullable audit storage, including formerly mandatory
writers. The family suppliers below own their original independent Review/QA and complete gate evidence;
TASK-00169's ignored Work handoff owns their source/dependency/runtime/log bridges to the integrated candidate.
Neither constructor presence nor ancestor acceptance alone proves current compatibility. The integrated checks
exercise the current package; independent Review and nonvisual QA remain separate. No release or real consumer
qualification is implied.

The implemented contract uses the existing cohesive evidence/repository boundary, explicit optional nullable audit injection per outcome owner, direct typed originating identities, package-defined actions, unique evidence
identity, trusted outcome time, precise subject/relationship references and bounded admitted context. Current APIs
belong to [TASK-00121](../planning/tasks/00121-TASK.md)/[TASK-00122](../planning/tasks/00122-TASK.md) and the family
suppliers below; use the [ordinary Role example](role-audit-composition.md) and each family's composition guide.

- The email-change request and confirmation owners can separately configure
  [consumer notification participation](credential-delivery.md#atomic-email-change-notification-intent-unreleased).
  It does not enable or replace audit. Enabled audit follows consumer staging in the same transaction; a failure
  rolls back both. The new request origin's mailbox is never copied into audit evidence.
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
coordinator stages changes inside each of its three public handlers' transactions; it owns neither commits nor
evidence and is not another public entry. Recovery
calls delivery, TokenIssuer and revocation helpers do not independently record owning outcomes, and `nextRunAt()` is
pure scheduling. No other handler additions/removals were found in the current tree. Historical manifests retain
historical counts; use this reconciliation rather than silently reinterpreting them as current source.

## How to read the matrices

Each row's linked name is its **actual source owner**. Handler names abbreviate the corresponding `handle()` entry.
The supplier headings name the family implementation and original acceptance owner. **All rows inherit the
implemented optionality, admission, failure and consumer rules above**, plus their fact/boundary profile below.
Profiles describe available facts, not a requirement to capture every admission/fence value in audit.

Observed audit: **W** = an explicit write for the meaningful outcome **when enabled**; **delegate** = no independent
record. Validated no-change and rejected/rolled-back work add none. W is a source disposition, not proof of actual
consumer transaction participation. The executable references below cover each family's conditional outcomes;
complete candidate identities and original logs remain in ignored evidence, never mandatory public inputs.

| Profile | Origin versus real-invoker authorization; first enabled attribution admission | Required evidence facts and trusted time | Actual transaction / publication / external-effect boundary |
| --- | --- | --- | --- |
| U — account | Existing actor input remains; registration deliberately establishes AnonymousId in the shared replacement. Actor input and audit provenance are not authority. For invitation correction and registration recovery, every consumer entry, including non-HTTP and worker entries, authenticates its actual caller and checks current operation permission and exact target before trusted dispatch. No handler recheck; business-state and writer fences still apply. | Actual User lifecycle/authentication/security/canonical-email/reservation/assignment counters, plus applicable typed grant/delivery and retained predecessor. Initial Role assignments are represented by their real counter, not captured Role lists. Activation origin remains business state, not an audit field. No universal User revision. Reuse actual transition/issuance Clock sample, distinguishing separately sampled times. | Named handler owns UoW: User/claims/grants/delivery/cancellation/sessions as applicable. Success fact after commit; ordinary publisher fault throws. Optional delivery subscriber may invoke provider only after issuance commit. |
| H — human delivery | Adapted delivery/retry/resend Commands preserve typed origin with explicit kind, not authenticated worker proof. Preserve original initiation where retained; actual worker/entry authority is separately consumer-owned. Enabled outcome attribution must precede its first required transition, not the external send's eventual acknowledgement. | User subject plus typed grant/delivery identities, actual before/after grant revisions, committed delivery status and closed failure classification. Claim identity, activation origin and recovery binding remain admission/state obligations, not captured audit facts. Claim and outcome Clock samples are distinct; no evidence for temporary claim alone. | Handler separately commits claim, invokes decrypt/provider outside transaction, then commits fenced outcome + evidence. Delivered fact only for successful delivery; committed retryable/permanent failure is meaningful too. Outcome rollback cannot undo a send or the prior claim. |
| X — human expiry | Trusted scheduler's explicit origin (autonomous System target), never infer authority from actor label. Existing entry/identity/expiry admission retained; attribute before first expiry mutation. | User, exact typed grant/delivery and actual grant revisions; email expiry additionally has the actual User reservation counters. Origin is not an audit fact. Use existing trusted caller-supplied command occurredAt, not a fabricated Clock sample or DB insert time. | Handler UoW cleans original expired material/authority; expiry fact after commit. Absent/stale/early/already-clean or losing no-work branches have no new outcome; preserve family-specific conflict behavior. No provider call. |
| S — sensitive User | Credential verification/current authenticated User/session/grant authority is independent of attribution. Public activation/reset must not infer authenticated initiator from target ID. Supplier explicitly admits appropriate origin before first evidence-requiring mutation; retain existing credential concealment. | User/session/grant references, actual authenticationAuthorityRevision/security/canonical-email/reservation and grant revisions where applicable, never invented session counters. Reuse authenticatedAt/changedAt/completedAt/confirmedAt or trusted revocation sample. No secret/token/hash evidence. | Narrow capability owns UoW, including coupled session/grant/User writes. Existing success facts after commit except refresh's direct result; ordinary publication fault throws. TokenIssuer/session/reset revocation helpers have no independent evidence ownership. |
| R — Role | Required UserId actor remains even disabled; consumer administrative entry authorization is separate. Keep managed/reserved/tier/reference policy and caller expected intent. Attribute before first Role write. | Typed Role and real RoleRevision (incarnation plus state revision); Permission relationship on membership. Clock created/renamed/removed/granted/revoked time. Creation has only successor; removal only predecessor. | Named handler UoW; final expected-state/name/reference/registration fences, including validated no-ops. Existing success fact only after change/commit; ordinary publication propagation, no interception. |
| M — User membership | Required UserId actor; consumer entry authority plus reserved Role/target rules retained. Attribute before changed membership write. | User primary, typed Role relationship and authoritative Role reference as applicable; do not invent User revision or mutate Role/authentication just for audit. Existing assignedAt/removedAt Clock sample. | Handler UoW and full-state/reference fence; same-membership no-op still validates. Fact only on change after commit. |
| A — Agent administration | Required UserId actor or UpdateAgent's UserId/AgentId initiator. Entry/self-target authority remains consumer/adapter-owned; ADMIN_SAFE is eligibility, not authorization. Attribute before required write inside actual owner transaction. | Agent plus changed Permission relationships; complete before/after assignment revisions/set where applicable. Rename uses exact repository-returned persisted changedAt from lazy post-admission Clock; no synthetic name revision/stale snapshot. | Update handler owns rename UoW. Three Permission handlers own their transactions; the coordinator stages assignment and separate typed facts/time without committing or auditing. Evidence joins that handler transaction, never a nested commit or post-return write. Facts publish afterward. |
| F — Feature | All six Feature/policy Commands carry optional direct typed provenance; autonomous provisioning supplies System once per Command, preserving real User/Agent origins otherwise. Admit before first changed subject, not necessarily discovery. Entry authority remains consumer-owned. | Typed Feature and Permission relation, actual Feature revision (creation OFF/revision 1; removal no successor). Required Application Clock supplies trusted use-case time without claiming stored Feature timestamps. Batch shares actual message correlation. | Handler owns one transaction; final state/revision/reference fences retained even no-op. Provision discovery precedes UoW, default Permission lazily resolved only for new Features. Existing facts after commit. |
| P — policy | Optional direct typed origin as F; consumer protects preview and apply. Enabled admission before first changed Role/Permission, not read-only plan. | Each changed Role/Permission, related Permission memberships; actual Role incarnation/revisions, no invented Permission revisions. Reuse reconciliation occurredAt Clock sample and real command correlation. Feature references are guards, not mutated subjects. | Handler replans inside one UoW and applies complete plan under final state/reference/tier fences, including nonwriting unchanged comparisons. Existing ManagedPolicyReconciled still publishes for empty/unchanged plan, without invented mutation evidence. Planner/preview do not write audit. |
| I — Agent issuance | Current operationAuthorization authorizes actual invocation independently and returns nullable typed originating provenance. Operations retain that origin through hydration/transitions/tombstones independently of optional evidence and fresh invocation/read authority. Admit before reservation/new issuance; pure original-key resolution gains no audit-only actor, but retains existing authorization. | Agent, original scoped operation key and delivery, actual credential identity/revision/state, original predecessor for rotation, issuedAt and new operation revision. Destination/cohort/restoration remain admission fences, not captured snapshots. Never raw canonical request/cipher/secret. | Handler UoW includes reservation, Agent/operation/material and current audit. Same-key resolves original without write/publication; conflict refuses. Confirmed new issuance publishes afterward with contained publisher faults; lost acknowledgement throws sanitized uncertainty. No direct sink send here. |
| V — Agent revocation | Direct typed actor remains required; consumer caller policy is separate. No System fallback supplies missing origin. | Agent, actual credential identity/revision/state and revokedAt Clock. The Boolean replacement interface exposes no original operation key/delivery/revision; those facts remain absent. Repository-owned retirement and cohort/restoration fences remain mandatory. | Lifecycle service UoW couples Agent revocation, repository-owned original-operation cancellation and audit. Ordinary post-commit publication fault throws. Already-revoked is conflict, not a new success. |
| D — Agent delivery | Fresh scope/delegated delivery authorization is independent of retained typed originating actor. Admit attribution before first meaningful transition, including claim-path expiry/exhaustion. | Agent/original scoped key/credential/delivery, original credential revision, actual before/after operation revisions/dispositions and closed failure classification. Claims/epochs/cohort/incarnation remain fences, not captured facts. Trusted transition Clock time, not worker/message receipt time. | Delivery service separates claim, fresh admission, protected outside-transaction sink invocation/receipt lookup and outcome UoWs. Epoch/deadline/Agent/receipt fences stay mandatory. Safe result containment distinguishes unavailable/deferred/rejected/indeterminate, not issuance's publication exception. No outcome Event publisher. |
| T — Agent maintenance | Current maintenance scope/target/key/deadline authorization remains independent. Explicit System for autonomous maintenance target, never fallback for absent original User/Agent. Admit before first meaningful expiry/failure/rewrap/cleanup acknowledgement. | Original Agent/key/credential/delivery relationships, actual operation revisions, bounded wrapping labels/failure classification and trusted Clock outcome time. Epoch/generation/incarnation remain admission fences, not evidence snapshots. Do not record ciphertext, key material or sink payload. | Service owns bounded fenced rewrap/expiry UoW; cleanup has admission UoW, external exact-tuple removal/tombstone, then separately fenced acknowledgement UoW. Already acknowledged/poll/helper work gets no duplicate. Safe maintenance results, no Event publisher; uncertainty cannot prove external removal did not happen. |

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
| [CorrectPendingInvitation](../src/Application/AccessControl/User/CommandHandler/CorrectPendingInvitationHandler.php) | W `user.pending_invitation_corrected`, direct UserId actor. Consumer entry checks the exact target; the handler rejects wrong origin and compares current User/grant state at write. Corrected address and revoked predecessor/fresh private delivery are one outcome, not helper records. |
| [RecoverPendingRegistration](../src/Application/AccessControl/User/CommandHandler/RecoverPendingRegistrationHandler.php) | W `user.pending_registration_recovered`. Consumer entry checks the exact target; the handler checks expected delivery; wrong-address correction or expired same-email reissue retains User/Roles, revokes predecessor and creates registration-origin successor. Same-email validation is not itself another record; recovery still creates a meaningful new generation. |
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
| [RevokeSession](../src/Application/AccessControl/RefreshSession/CommandHandler/RevokeSessionHandler.php) | W self-service `refresh_session.revoked` and administrative `refresh_session.administratively_revoked`, with actual session/revision facts and no free-text reason capture. Required User actor; cross-User authorization and reason required. Current session is prohibited here (use logout); unusable session rejects. Business reason remains mandatory where required, but the shared replacement omits it from evidence. |

## Administration handlers (11)

TASK-00153's three lifecycle handlers support optional last nullable storage through ordinary shared transactions;
see [composition](role-revisions.md#optional-lifecycle-audit-unreleased). Their required actor inputs are unchanged.
Audit failure/commit loss rolls back staged Role/evidence, uncertain commit retains both actual possibilities, and
ordinary publication failure leaves committed evidence intact. Other administration rows remain separate suppliers.

| Supplier / profile | Actual entry | Observed audit and outcome disposition |
| --- | --- | --- |
| [TASK-00154](../planning/tasks/00154-TASK.md) / R | [GrantPermissionToCustomRole](../src/Application/AccessControl/Role/CommandHandler/GrantPermissionToCustomRoleHandler.php) | W when enabled: `role.permission_granted`, related Permission and exact before/after Role revisions. Already-held validates full Role/Permission eligibility then no-change without evidence. |
| TASK-00154 / R | [RevokePermissionFromCustomRole](../src/Application/AccessControl/Role/CommandHandler/RevokePermissionFromCustomRoleHandler.php) | W when enabled: `role.permission_revoked`, related Permission and exact before/after Role revisions. Already-absent validates then no-change without evidence. Existing protected-membership revocation remains allowed; managed/reserved Role policy remains. |
| [TASK-00153](../planning/tasks/00153-TASK.md) / R | [CreateCustomRole](../src/Application/AccessControl/Role/CommandHandler/CreateCustomRoleHandler.php) | W when enabled: `role.created`, direct required UserId, trusted createdAt and message correlation; null predecessor and authoritative new incarnation/revision. Uniqueness/reserved-name refusal adds none. |
| TASK-00153 / R | [RenameCustomRole](../src/Application/AccessControl/Role/CommandHandler/RenameCustomRoleHandler.php) | W when enabled: `role.renamed`, actual before/after incarnation/revision, renamedAt and message correlation. Fresh same-name validates without timestamp/revision/event/evidence; stale intent rejects even same-name. Complete-state/name fences remain. |
| TASK-00153 / R | [RemoveCustomRole](../src/Application/AccessControl/Role/CommandHandler/RemoveCustomRoleHandler.php) | W when enabled: `role.removed`, authoritative predecessor, null successor, removedAt and message correlation inside the removal transaction. Caller revision and User/registration/reference fences remain; missing/managed/referenced/stale target adds none. |
| [TASK-00156](../planning/tasks/00156-TASK.md) / A | [UpdateAgent](../src/Application/AccessControl/Agent/CommandHandler/UpdateAgentHandler.php) | W when enabled: `agent.name_changed`, required direct User/Agent initiator, typed Agent, unique evidence ID, exact persisted changedAt and incoming message ID inside rename transaction. Null/no-change adds none. Facts admit only `name_changed: true`; no raw names, snapshots, synthetic revisions or extra reads. |
| [TASK-00155](../planning/tasks/00155-TASK.md) / M | [AssignRoleToUser](../src/Application/AccessControl/User/CommandHandler/AssignRoleToUserHandler.php) | W when enabled: `user.role_assigned`, User primary/Role relationship, actual User assignment-counter transition and observed complete Role reference revision. Trusted assignedAt/message correlation. Already-held no-change retains reference validation/fences without evidence; no authentication/Role mutation. |
| TASK-00155 / M | [RemoveRoleFromUser](../src/Application/AccessControl/User/CommandHandler/RemoveRoleFromUserHandler.php) | W when enabled: `user.role_removed`, same typed facts with actual removal assignment revision/time/correlation. Already-absent no-change retains reference validation/fences without evidence. Existing User-state and reserved-identity policy remains. |
| [TASK-00157](../planning/tasks/00157-TASK.md) / A | [GrantPermissionToAgent](../src/Application/AccessControl/Agent/CommandHandler/GrantPermissionToAgentHandler.php) | W when enabled: `agent.permission_granted`, typed changed Permission relationship, actual before/after assignment revisions, transition time and message correlation inside the handler transaction. Already-held validates eligible Permission then no-change. |
| TASK-00157 / A | [RevokePermissionFromAgent](../src/Application/AccessControl/Agent/CommandHandler/RevokePermissionFromAgentHandler.php) | W when enabled: `agent.permission_revoked`, changed typed relationship and actual assignment revisions in the handler transaction. Already-absent no-change; actual lookup/lifecycle/cohort refusals retained. |
| TASK-00157 / A | [ReplaceAgentPermissions](../src/Application/AccessControl/Agent/CommandHandler/ReplaceAgentPermissionsHandler.php) | W when enabled: `agent.permissions_replaced`, only granted/revoked typed relationships and one actual revision advance in the handler transaction. Expected assignment revision and exact eligible set checked even unchanged. Empty removal is a real change; unchanged relationships are not claimed as changed. |

[AgentPermissionAssignmentCoordinator](../src/Application/AccessControl/Agent/PermissionAssignment/AgentPermissionAssignmentCoordinator.php)
stages grant/revoke/replace within each public handler's UoW and exposes separate authoritative facts/time alongside
the existing Event. It neither commits nor records evidence. The handler inserts one optional record before commit;
no Event inspection, helper duplication or nested transaction is required. See
[composition](agent-administration-contracts.md#optional-direct-permission-audit-composition-unreleased).

## Feature and policy handlers (six)

| Supplier / profile | Actual entry | Observed audit and outcome disposition |
| --- | --- | --- |
| [TASK-00160](../planning/tasks/00160-TASK.md) / F | [ProvisionFeatures](../src/Application/AccessControl/Feature/CommandHandler/ProvisionFeaturesHandler.php) | Optional final repository implemented. Each created Feature gets typed initial OFF/revision-1/Permission evidence and Clock time within the single batch transaction, sharing explicit originating actor and real message ID. Empty/all-existing passes need no audit-only actor; disabled execution makes no durability promise. Audit/reference/insertion loss rolls back the batch; uncertainty and ordinary publisher failure retain truthful state/retry semantics. See [composition](feature-provisioning.md#optional-atomic-audit-unreleased). |
| [TASK-00159](../planning/tasks/00159-TASK.md) / F | [SetFeatureStatus](../src/Application/AccessControl/Feature/CommandHandler/SetFeatureStatusHandler.php) | Optional explicit atomic audit. Changed status and actual Feature revisions/bound Permission; same status validates revision, full state and reference without evidence/event/write. Explicit originating actor required only for enabled changed work. |
| TASK-00159 / F | [SetFeaturePermission](../src/Application/AccessControl/Feature/CommandHandler/SetFeaturePermissionHandler.php) | Optional explicit atomic audit. Changed binding and actual Feature revisions with old/new typed Permission relationships; same binding validates reference/state without evidence. Any existing Permission tier remains eligible; audit grants no Permission. |
| [TASK-00158](../planning/tasks/00158-TASK.md) / F | [CreateFeature](../src/Application/AccessControl/Feature/CommandHandler/CreateFeatureHandler.php) | W when enabled: `feature.created`, typed originating actor/Feature/Permission, trusted Clock outcome time, unique evidence and actual message correlation; null predecessor and OFF/revision-1 successor. Duplicate/invalid reference refuses without committed evidence, not replay success. |
| TASK-00158 / F | [RemoveFeature](../src/Application/AccessControl/Feature/CommandHandler/RemoveFeatureHandler.php) | W when enabled: `feature.removed`, authoritative prior revision/status/Permission and null successor, explicit origin, trusted Clock time and message correlation inside removal/reference-release transaction. Expected revision, complete CURRENT discovery and final full-state CAS remain; candidate absence/refusal creates no committed evidence. |
| [TASK-00161](../planning/tasks/00161-TASK.md) / P | [ReconcileManagedPolicy](../src/Application/AccessControl/ManagedPolicy/CommandHandler/ReconcileManagedPolicyHandler.php) | W when enabled. One explicit record per meaningful Role/Permission create/reconcile/remove in the complete transaction; actual Role incarnation/revisions/memberships and admitted Permission facts, trusted shared time/message ID. Missing actor refuses before audited mutation; disabled/unchanged need no audit-only origin. Whole batch/evidence roll back on late loss, committed evidence survives publication failure. Summary Event remains even all-unchanged/empty; Feature checks are reference guards only. |

## Seven synchronous User operations

All seven belong to [TASK-00127](../planning/tasks/00127-TASK.md), profile S. These remain sensitive synchronous
contracts, not serializable Commands; token results are not evidence or arbitrary wire payloads.

| Actual owner / entry | Observed audit and outcome disposition |
| --- | --- |
| [UserAuthentication::activate](../src/Application/AccessControl/User/Authentication/UserAuthentication.php) | W optional `user.activated`, explicit nullable typed origin admitted before mutation; actual User/session revisions and consumed grant/delivery. Valid single-use grant activates same User and starts session atomically, advances actual authority revision but preserves authenticationVersion. Credential/expiry/identity refusal is not committed outcome; deliberate public origin is not inferred authenticated User. |
| UserAuthentication::login | W optional `user.logged_in`, verified User origin and actual User/session revisions. Successful login with or without verified rehash is meaningful: exactly one authority revision and coupled new session. Throttle/dummy-hash/rejection retains concealment; ordinary login/rehash does not revoke reset authority. |
| [SessionLifecycle::refresh](../src/Application/AccessControl/RefreshSession/SessionLifecycle.php) | W optional `refresh_session.refreshed` on real rotation with verified User origin; `refresh_session.replay_revoked` on actual terminal-replay revocation with explicit typed origin. Successful rotation is meaningful; bounded most-recent credential conflict is no-change. Terminal replay commits compromised-session revocation before outward AuthenticationRequired: target evidence is revocation, never successful refresh or rejected-attempt logging. Concurrent helper/winner no-op must not duplicate it. |
| SessionLifecycle::logout | W optional `refresh_session.logged_out` on actual revocation, with explicit typed origin and real session revisions. Credential-selected current-session revocation and post-commit CurrentSessionLoggedOut; invalid/unrelated credentials reject. Preserve helper's concurrent/already-revoked resolution rather than inventing another mutation. |
| [PasswordManagement::changePassword](../src/Application/AccessControl/User/PasswordManagement.php) | W `user.password_changed`, authenticated UserId. Password/security/authority revision, reset cancellation and session revocations are coupled. Same submitted password is not a generic no-change shortcut. |
| PasswordManagement::resetPassword | W `user.password_reset_completed`, explicit nullable typed provenance now required before enabled mutation; target User is not initiator proof. Valid single-use generation/binding consumption, password/security/authority and session changes commit together; no delivery-success prerequisite. Target origin admission must remain explicit. |
| [EmailChangeConfirmation::confirmEmail](../src/Application/AccessControl/EmailChangeGrant/EmailChangeConfirmation.php) | W `user.email_change_confirmed`, explicit nullable typed provenance now required before enabled mutation. Credential/reservation-bound consumption, canonical-email/security/authority revision, reset and session invalidation commit together. No tokens issued; expired/stale/wrong-purpose refuses. |

## Eight historical Agent entries, reconciled to current owners

These belong to [TICKET-00047](../planning/tickets/00047-TICKET.md), not the four Agent administration handlers.
The first two rows are already counted in the current 39 handlers; count them **once**.

| Supplier / profile | Actual owner / entry | Observed audit and outcome disposition |
| --- | --- | --- |
| [TASK-00162](../planning/tasks/00162-TASK.md) / I | [ProvisionAgentHandler](../src/Application/AccessControl/Agent/CommandHandler/ProvisionAgentHandler.php) (formerly provision service) | W `agent.provisioned` only for new retained operation. Original-key resolve neither reissues nor republishes; conflicting request refuses. Authorization supplies typed originating provenance retained independently of audit through current operation hydration/transitions/tombstones for restarted workers. Void completion/independent Query, no returned warning. |
| TASK-00162 / I | [RotateAgentCredentialHandler](../src/Application/AccessControl/Agent/CommandHandler/RotateAgentCredentialHandler.php) (formerly rotate service) | W `agent.credential_rotated` for new rotation. Exact original predecessor/expected credential revision, retained key and cancelled old delivery remain; same-key original resolution no duplicate. Void/Query/publication-containment contract as provision. |
| [TASK-00163](../planning/tasks/00163-TASK.md) / V | [AgentCredentialLifecycleService::revoke](../src/Application/AccessControl/Agent/Credential/AgentCredentialLifecycleService.php) | Optional last nullable writer for `agent.credential_revoked`, required direct typed actor even disabled. Exact credential states/revision and trusted time; operation correlation/revision remain unavailable at this interface, not guessed. Coupled original-operation cancellation; already revoked rejects. Ordinary publisher fault still throws after commit. |
| [TASK-00164](../planning/tasks/00164-TASK.md) / D | [AgentCredentialDeliveryService::deliver](../src/Application/AccessControl/AgentCredentialOperation/Delivery/AgentCredentialDeliveryService.php) | W optional `agent.delivery.confirmed`, `agent.delivery.failed`, `agent.delivery.expired` or `agent.delivery.exhausted`, using retained typed origin, real transition time/revisions/dispositions and original relationships/scoped key. Confirmed receipt/delivery, committed retryable/permanent failure and expiry/exhaustion reached inside claimDelivery are meaningful. Temporary claims/admission/poll, unchanged recorded-result reconciliation and deferred/no-work are not separate mutation records. Receipt-first restart must not blindly restage; audit failure cannot unsend. |
| TASK-00164 / D | [AgentDeliveryRecoveryService::recover](../src/Application/AccessControl/AgentCredentialOperation/Delivery/AgentDeliveryRecoveryService.php) | delegate. Authorized bounded due Query then actual deliver per original tuple; only delivery owns resulting evidence. Discovery failure is not empty success; restart preserves retained origin/key. `nextRunAt()` remains pure, no actor/audit admission. |
| [TASK-00165](../planning/tasks/00165-TASK.md) / T | [AgentDeliveryMaintenanceService::rewrap](../src/Application/AccessControl/AgentCredentialOperation/Maintenance/AgentDeliveryMaintenanceService.php) | W optional `agent.material_rewrapped`, `agent.material_expired` or `agent.material_failed`, with explicit typed User/Agent/System origin. Meaningful rewrap, expired-original branch or permanent source-loss terminal transition. Same target key/no material unchanged; temporary source failure retryable without invented success. Rewrapper call remains within bounded fenced transaction. |
| TASK-00165 / T | AgentDeliveryMaintenanceService::expire | W optional `agent.material_expired` with explicit typed User/Agent/System origin. Meaningful original-material expiry; not-yet-expired/already-material-free is unchanged. Original retained issuance/history remains; no reissue or historical evidence reconstruction. |
| TASK-00165 / T | AgentDeliveryMaintenanceService::cleanup | W optional `agent.sink_cleaned` with explicit typed User/Agent/System origin. Newly acknowledged exact-tuple cleanup meaningful; already-cleaned returns CLEANED without new evidence, ineligible returns unchanged. External removal may succeed before acknowledgement/audit fails; retain tombstone/history/fences and reconcile uncertainty, never assert external rollback. |

## Executable family evidence and conformance

The following executable suites locate each supplier's current behavior and modeled fences. Original family
Review/QA reports retain their exact historical subjects; the integrated candidate requires its own current checks
and independent assessment. Passing package models do not qualify consumer adapters. Detailed candidate/log/exit,
source/dependency/runtime and drift bridges belong in ignored TASK-owned handoffs, not a public evidence registry.
The final column lists the covered risk, not unfinished family implementation.

| Acceptance owner | Current executable references | Covered outcomes and limits |
| --- | --- | --- |
| TASK-00123 | [Optional account audit](../tests/Application/AccessControl/User/CommandHandler/OptionalLifecycleAuditTest.php), [dispatch outcomes](../tests/Application/AccessControl/User/CommandHandler/UserLifecycleDispatchTest.php), [User lifecycle](../tests/Application/AccessControl/User/CommandHandler/UserLifecycleContractTest.php), [registration recovery races](../tests/Application/AccessControl/User/CommandHandler/RegistrationRecoveryRaceTest.php) | Eight handlers, enabled/null/omitted storage, typed/anonymous/admin provenance, actual revision/grant/message facts, retained admission, coupled state/evidence and failures. Independent acceptance and real consumer qualification remain separate. |
| TASK-00124 | [Optional invitation audit](../tests/Application/AccessControl/ActivationGrant/CommandHandler/InvitationAuditTest.php), [invitation delivery](../tests/Application/AccessControl/ActivationGrant/CommandHandler/DeliverUserInvitationHandlerTest.php), [expiry recovery](../tests/Application/AccessControl/CredentialDelivery/QueryHandler/ExpiredCredentialRecoveryTest.php) | Four owners, both origins, retry/resend/expiry and committed failures versus temporary claims. |
| TASK-00125 | [Optional audit/expiry](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/PasswordResetAuditTest.php), [reset request](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/RequestPasswordResetHandlerTest.php), [delivery outcomes](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/DeliverPasswordResetHandlerTest.php), [recovery outcomes](../tests/Application/AccessControl/PasswordResetGrant/RecoveryOutcomeTest.php) | Enabled/disabled composition, anonymous/no-work concealment, typed delivery/failure/expiry, rollback/uncertainty/publication and preserved recovery binding. |
| TASK-00126 | [Optional email audit](../tests/Application/AccessControl/EmailChangeGrant/CommandHandler/EmailChangeAuditTest.php), [email request](../tests/Application/AccessControl/EmailChangeGrant/CommandHandler/RequestEmailChangeHandlerTest.php), [expiry](../tests/Application/AccessControl/EmailChangeGrant/CommandHandler/ExpireEmailChangeHandlerTest.php) | Self-service plus admin, delivery/failure and full reservation expiry. |
| TASK-00127 | [Synchronous audit](../tests/Application/AccessControl/User/Authentication/SynchronousAuditTest.php), [synchronous contracts](../tests/Application/AccessControl/User/Authentication/SynchronousContractTest.php), [sessions](../tests/Application/AccessControl/RefreshSession/SessionLifecycleTest.php), [RevokeSession](../tests/Application/AccessControl/RefreshSession/CommandHandler/RevokeSessionHandlerTest.php) | Seven synchronous entries and Command, terminal-replay committed revocation versus benign conflict, correct unauthenticated origin. |
| TASK-00153 | [Lifecycle audit](../tests/Application/AccessControl/Role/CommandHandler/RoleLifecycleAuditTest.php), [reference fences](../tests/Application/AccessControl/Role/CommandHandler/RoleLifecycleAuditReferenceTest.php), [Role revisions](../tests/Application/AccessControl/Role/CommandHandler/RoleRevisionHandlerTest.php) | Create/rename/remove exact incarnation/revisions, stale even no-op, all-writer/reference/rollback/publication. |
| TASK-00154 | [Role membership](../tests/Application/AccessControl/Role/CommandHandler/CustomRoleMembershipHandlerTest.php), [optional audit](../tests/Application/AccessControl/Role/CommandHandler/RolePermissionAuditTest.php) | Both relationships, actual Role revisions/time/correlation, enabled/disabled/no-change, audit/commit rollback, CAS/reference/tier loss, uncertainty and retained evidence after publication failure. |
| TASK-00156 | [Agent rename](../tests/Application/AccessControl/Agent/CommandHandler/UpdateAgentHandlerTest.php), [bounded facts](../tests/Domain/AccessControl/Audit/AuditAgentNameOutcomeTest.php), [Tool composition](../tests/Adapter/Mcp/Tool/Agent/AgentProfileToolsTest.php) | Enabled/disabled User/Agent origins, exact persisted changedAt/correlation, no-change/admission, competing writer preservation, audit rollback, both uncertain outcomes and committed evidence without success acknowledgement after publication faults. |
| TASK-00155 | [User Role assignments](../tests/Application/AccessControl/User/CommandHandler/UserRoleAssignmentHandlerTest.php), [optional audit](../tests/Application/AccessControl/User/CommandHandler/UserRoleAuditTest.php) | Both relationships across all User states/ordinary and Super Admin Roles; real assignment counters and observed Role incarnation/revision, enabled/disabled/no-change, audit/commit rollback, reference/CAS losers, uncertainty and retained committed evidence after publication failure. |
| TASK-00157 | [Assignment audit](../tests/Application/AccessControl/Agent/CommandHandler/AgentPermissionAuditTest.php), [Agent assignments](../tests/Application/AccessControl/Agent/CommandHandler/AgentPermissionAssignmentHandlerTest.php) | Three handlers, one actual mutation/evidence transaction, complete-set revision/relationships and no duplicate helper record. |
| TASK-00158 | [Lifecycle audit](../tests/Application/AccessControl/Feature/CommandHandler/FeatureLifecycleAuditTest.php), [Feature management](../tests/Application/AccessControl/Feature/CommandHandler/FeatureManagementTest.php), [retirement](../tests/Application/AccessControl/Feature/CommandHandler/RemoveFeatureHandlerTest.php) | Enabled/disabled provenance, mapped persisted evidence/state/reference rollback, uncertain commits, both publication failures and preserved discovery/CAS fences. Actual consumer storage remains separate. |
| TASK-00159 | [Setting audit](../tests/Application/AccessControl/Feature/CommandHandler/FeatureSettingAuditTest.php), [Feature mutation contracts](../tests/Application/AccessControl/Feature/CommandHandler/FeatureMutationContractTest.php) | Both settings, changed versus final-fenced unchanged, no audit-only actor for no-change. |
| TASK-00160 | [Provisioning audit](../tests/Application/AccessControl/Feature/CommandHandler/ProvisionFeaturesAuditTest.php), [Feature provisioning](../tests/Application/AccessControl/Feature/CommandHandler/ProvisionFeaturesHandlerTest.php) | Mixed/new/empty/existing batch, one origin, per-created-subject evidence/correlation. |
| TASK-00161 | [Batch audit](../tests/Application/AccessControl/ManagedPolicy/CommandHandler/ManagedPolicyAuditTest.php), [policy apply/late reference](../tests/Application/AccessControl/ManagedPolicy/CommandHandler/ReconcileManagedPolicyHandlerTest.php), [unchanged fences](../tests/Application/AccessControl/ManagedPolicy/CommandHandler/ManagedPolicyUnchangedFenceTest.php) | Mixed create/reconcile/remove/unchanged: six changed Role/Permission records; every audit position, commit/uncertainty/double-publication failure; complete revisions/relationships/time/correlation, converged no-change and retained summary Event. |
| TASK-00162 | [Issuance audit/origin](../tests/Application/AccessControl/Agent/CommandHandler/AgentIssuanceAuditTest.php), [provision](../tests/Application/AccessControl/Agent/CommandHandler/ProvisionAgentHandlerTest.php), [rotate](../tests/Application/AccessControl/Agent/CommandHandler/RotateAgentCredentialHandlerTest.php), [dispatch](../tests/Application/AccessControl/Agent/CommandHandler/AgentIssuanceDispatchTest.php) | Paired void handlers, typed retained-origin hydration/restart, new versus original-key, uncertainty and both publisher faults without warning resurrection. |
| TASK-00163 | [Revocation](../tests/Application/AccessControl/Agent/Credential/AgentCredentialLifecycleServiceTest.php) | Omitted/null/enabled storage, four typed origins, exact persisted credential/time facts without invented correlation, cancellation/evidence rollback, both uncertain outcomes and ordinary/both-publication primary throw. See [current composition](agent-credential-retirement.md#optional-atomic-revocation-audit-unreleased); independent acceptance and real storage qualification remain separate. |
| TASK-00164 | [Delivery audit](../tests/Application/AccessControl/AgentCredentialOperation/Delivery/AgentDeliveryAuditTest.php), [delivery](../tests/Application/AccessControl/AgentCredentialOperation/Delivery/AgentCredentialDeliveryServiceTest.php), [recovery](../tests/Application/AccessControl/AgentCredentialOperation/Delivery/AgentDeliveryRecoveryServiceTest.php), [uncertainty](../tests/Application/AccessControl/AgentCredentialOperation/Delivery/AgentWorkerUncertaintyTest.php) | Retained origin from TASK-00162, claim expiry/exhaustion, receipt-first restart, committed outcomes versus polls, external-effect truth. |
| TASK-00165 | [Maintenance](../tests/Application/AccessControl/AgentCredentialOperation/Maintenance/AgentDeliveryMaintenanceServiceTest.php), [optional audit](../tests/Application/AccessControl/AgentCredentialOperation/Maintenance/AgentMaintenanceAuditTest.php) | All three methods: explicit provenance and actual time/revisions/key, rewrap expiry/source loss, state/evidence rollback, external cleanup/acknowledgement split, no-change and both uncertain outcomes. Independent acceptance and real consumer qualification remain separate. |

[TASK-00167](../planning/tasks/00167-TASK.md) supplies reusable executable conformance around the actual shared
contract. Its reference corpus is representative storage proof, not exhaustive family execution. The supported
binding uses ordinary shared transactions and complete mapping:

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

## Integrated composition and conditional proof

[RoleAuditCompositionTest](../tests/Application/AccessControl/Role/CommandHandler/RoleAuditCompositionTest.php)
now also binds Role and Feature owners to the same ordinary UoW and mapped audit storage. An enabled Role write
coexists with an actorless disabled Feature write. Later Feature opt-in validates an unchanged retry without
attribution or backfill, then records only the new System-origin transition from the actual current revision.
Disabling Feature audit again does not disable the independently configured User-origin Role writer. Evidence
identities and incoming correlations remain separate; each public Command owns its own transaction.

Other cross-family risks already have owning executable proof and are not duplicated here: explicit four-kind
origin and validation before mutation; anonymous ineligible-request concealment; committed terminal replay versus
benign refresh conflict; six-subject policy and changed-only Feature batches; retained-origin delegated restart and
same-key no-backfill; delivery claim expiry/exhaustion and receipt-first recovery; maintenance expiry/source loss,
fenced rewrap and external cleanup/acknowledgement. The table above identifies those complete owning suites.

Shared storage conformance deliberately supplies a representative populated corpus, not every current action/fact
combination. New family fact shapes retain their own complete round-trip/admission and writer tests; a consumer
must extend its adapter binding for every shape it advertises. Do not interpret the original eight-shape corpus
as certification of later facts. Ordinary Role proof and TASK-00168's later CI4 amendment retain distinct historical
subjects: isolated CI4 units do not establish native bootstrap journeys. Neither affects audit transaction ownership.

TASK-00182 supersedes older issuance-warning language in this TASK's original plan and parents. Current provision/
rotation Commands return void; independent authorized Queries resolve original outcomes. Confirmed issuance survives
both publishers failing without returned, stored or reconstructed publication warnings. Ordinary Role/User/Feature/
revocation paths retain primary-fault propagation; delivery/maintenance retain their safe-result contracts.

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
