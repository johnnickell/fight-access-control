# User lifecycle Application contracts (Unreleased)

The eight account handlers remain registered, success-only **void** Commands. Use their `commandRegistration()`
with Common's configured command router/bus; canonical message decoding does not grant permission or prove commit.
This correction changes intentional Application failure categories, not wire fields, Domain repository nullability,
account policy or the separate generic Command/Event inference contract.

## Optional account audit composition

Unreleased TASK-00123 changes all eight constructors to a last nullable
`?AuditEvidenceRepository $auditEvidenceRepository = null`. Omitted and explicit null both disable durable audit;
there is no no-op repository, enable flag or later backfill. Supplied evidence storage remains required within the
handler's actual mutation transaction/connection. A failing configured writer cannot downgrade to disabled mode.

The preceding required arguments, in order, are:

| Handler | Required arguments before optional audit |
| --- | --- |
| InvitePendingUser | userRepository, activationGrantRepository, unitOfWork, activationCredentialGenerator, invitationDeliveryCipher, clock, eventDispatcher |
| RegisterUser | userRepository, roleRepository, activationGrantRepository, unitOfWork, activationCredentialGenerator, invitationDeliveryCipher, clock, eventDispatcher, defaultRoleNames |
| CorrectPendingInvitation | userRepository, activationGrantRepository, invitationAdministrationAuthorization, unitOfWork, activationCredentialGenerator, invitationDeliveryCipher, clock, eventDispatcher |
| RecoverPendingRegistration | userRepository, activationGrantRepository, registrationRecoveryAuthorization, unitOfWork, activationCredentialGenerator, invitationDeliveryCipher, clock, eventDispatcher |
| EnableUser | userRepository, unitOfWork, eventDispatcher, clock |
| DisableUser / DeleteUser | userRepository, sessionRevocationService, clock, unitOfWork, eventDispatcher, passwordResetGrantRepository |
| RestoreUser | userRepository, activationGrantRepository, activationCredentialGenerator, invitationDeliveryCipher, clock, unitOfWork, eventDispatcher |

Update positional callers directly; named `auditEvidenceRepository` callers retain that name. No old-order facade
or migration is supplied. Existing business-required actor fields stay required even when disabled. Invitation
retains its explicit typed origin; registration deliberately establishes AnonymousId, never its target as an
authenticated actor. The other six retain business-required UserId actors and existing target/entry authorization.
This slice needs no additional audit-only actor input or missing-origin substitution.

Enabled evidence records one meaningful outcome per successful command: initial User/activation issuance; pending
correction/recovery with original and retained cancelled predecessor plus successor; or actual lifecycle transition.
Same-address expired registration recovery still issues a new generation and gets one record, not a User-change
record plus a helper record. Existing invalid/repeated transitions reject; no new idempotent no-change branch is
introduced. Read/validation alone produces none. Default-Role and expected-state fences remain in disabled mode too.
Bulk session/reset cancellation belongs to disable/delete's single account outcome, not duplicate helper evidence.
Pending restoration records its fresh private activation relationship; active restoration has none. Evidence
uses actual User counters and message/grant/delivery IDs, with no email, credentials, reason or diagnostic capture.
See the [typed contract](audit-evidence.md) and [complete outcome matrix](audit-outcome-coverage.md).

## Intentional outcomes

| Operation | Absence / refusal / concurrency |
| --- | --- |
| `RegisterUser` | Invalid or unavailable configured default Roles are internal configuration faults, not caller not-found. An authoritative initial-Role reference loss or canonical-email claim is `StateConflict`. |
| `InvitePendingUser` | An existing canonical-email claim is `StateConflict`, never duplicate success or recovery. |
| `RecoverPendingRegistration` | After target authorization, missing User is `ResourceNotFound`; missing/unusable activation authority or ineligible User is `StateConflict`. A different current delivery ID from the explicit expected ID is `PreconditionFailed`. Final User/destination/grant CAS loss is `StateConflict`. |
| `CorrectPendingInvitation` | After target authorization, missing User is `ResourceNotFound`; missing/unusable/private-origin mismatch, same-address refusal and ineligible User are `StateConflict`. Final destination/grant CAS loss is `StateConflict`. |
| `EnableUser`, `DisableUser`, `DeleteUser`, `RestoreUser` | Missing User is `ResourceNotFound`; the Domain's rejected lifecycle transition and final lifecycle CAS loss are `StateConflict`. Pending-restoration successor rejection is also `StateConflict`. |

These classes are `Fight\Common\Application\Error\Exception` contracts. The two authorization ports retain their
explicit Domain denial exceptions; the calling handler translates **only that port call's declared denial** to
Application `PermissionDenied`. Unknown authentication-context, identity, configuration and provider faults must
propagate, not be disguised as denial. Consumers still authorize every administrative entry point and independently
apply public-registration admission. Actor IDs, invitation origin and expected generation values confer no permission.
Target-scoped recovery/correction authorization remains valid through transaction completion.

Domain exceptions stay independent of Application. Translation surrounds only the relevant aggregate transition,
authorization call or repository's documented canonical-email uniqueness outcome. It never classifies an entire
transaction by exception class, diagnostic text, integer code or previous cause. For example, an unexpected
`UserLifecycleException` thrown by a repository remains the exact internal fault; it is not a lifecycle refusal.
`UserRepository::add()` / `addWithRoleAssignments()` declare `DuplicateEmailException` specifically for a claimed or
reserved canonical email. Other identity/storage failures must not use that exception to fabricate a known conflict.

Every returned User must have the requested ID before related reads or mutation. A latest activation result must
own its returned delivery for that User, including matching expiry; a wrong identity or broken aggregate correlation
is an internal `LogicException`, not absence or business refusal. Registration verifies the returned Role name;
its existing final complete-Role fence protects identities, names, incarnation/revision and assignments. Separate
User/grant reads do not promise an atomic snapshot: ordinary state/destination changes still reject through Domain
admission and final complete-state CAS.

With Common's explicitly enabled safe HTTP composition, deliberate missing/denied/conflict/precondition outcomes
map to generic 404/403/409/412. Unknown faults, invalid configuration, missing bus routing and broken results map to
safe 500. No package route, response policy, MCP/OAuth mapping or consumer bootstrap is supplied. Never expose
rethrown diagnostics or infer rollback from an HTTP failure.

## Preserved effects

- Registration resolves nonempty trusted default Role names on each execution, excludes the reserved Super Admin
  name, and commits the exact pending User/Role references, registration-origin grant/delivery and adopted audit.
  Private invitation remains a separate origin. All-state canonical-email and reservation uniqueness remain atomic.
- Registration recovery retains the User and exact Roles. Same-canonical-email recovery at/after expiry leaves User
  state unchanged but intentionally issues a new generation and records recovery; before expiry it refuses. Private
  correction still refuses the same address and never corrects registration-origin work. No synthetic no-op fact is added.
- Domain owns every transition. Disable/delete retain monotonic security authority, atomic reset cancellation and
  session revocation. Enable/restore restore neither old sessions nor old reset authority. Pending restoration retains
  its existing fresh private activation semantics; pending deletion remains unsupported.
- Mutation, grant/session changes and adopted audit share the real transaction/fences. Success facts publish only
  after confirmed commit. Unknown commit acknowledgement remains unresolved until authoritative inspection; no
  handler guesses rollback or retries issuance. Publication failure still throws with committed state retained,
  including when failure notification also fails. Operation-constant failure text never sanitizes the rethrown fault.

The existing [operation outcomes](operation-outcomes.md), [registration](user-registration.md),
[registration recovery](registration-recovery.md) and [delivery](credential-delivery.md) guides remain authoritative
for complete composition. Other audit families and broader credential/session helper corrections retain separate
owners; account optionality does not implement them or claim consumer persistence qualification.

## PHP caller changes and evidence

Update affected Application catches to the categories above; retain Domain construction/transition catches at their
own boundaries. Update named constructor arguments on registration/invitation/recovery to
`activationCredentialGenerator`, `invitationDeliveryCipher`, and recovery's `registrationRecoveryAuthorization`.
Success wire shapes are unchanged. TASK-00123's current positional order is documented above. Apply the pre-v1 current-contract policy directly,
without aliases, nullable legacy modes, result DTOs or automatic stale-intent retries.

Native `commitTransactional()` remains `mixed`; each handler annotates its known owned callback result locally
with the actual Event or delivery-ID type. This is not an assertion over unrelated external data or proof of generic
bus inference. The [static fixtures](type-contracts.md) assert all eight void method callbacks and both exact UserId
ports, and reject incompatible identities/using void as a result. `UserLifecycleDispatchTest` exercises all eight
real registered paths, serialized envelopes, late audit rollback, both modeled commit-acknowledgement outcomes and
post-commit double publication failure. Existing registration/recovery/lifecycle/race suites retain their detailed
behavioral proof. `OptionalLifecycleAuditTest` additionally exercises explicit null/omitted composition, typed
invitation origins, exact persisted User/grant/revision/message evidence, admission with audit disabled, rollback,
primary audit fault identity, rejected repeats and pending restoration. Independent Review and nonvisual QA remain separate.

Consumers must qualify actual authorization lifetime, storage identity mapping, shared connection/locks, uniqueness,
all-writer CAS, audit atomicity, process restart/uncertainty, provider behavior and safe response/log boundaries.
Package in-memory schedules and routing tests are not those consumer passes, a release or deployment authorization.
