# Synchronous User authentication (Unreleased)

Secret-bearing operations use direct Application capabilities, not serializable Commands or Queries. This is a
breaking pre-v1 replacement of `User\Security\AuthenticationService`; that class is removed without an alias,
forwarding facade or compatibility mode. Update imports, constructor bindings and named arguments directly.
The supported JWT profile and existing token/conflict/void result shapes remain unchanged.
[User/session type contracts](user-session-type-contracts.md) describe current message validation, typed Role lists,
hydration and integer-counter limits without changing these synchronous capability homes.

## Direct owners

All paths below are relative to `Fight\AccessControl\Application\AccessControl`.

| Owner | Synchronous inputs and result | Atomic effects and failure boundary |
| --- | --- | --- |
| `User\Authentication\UserAuthentication` | `activate(UserId, activationCredential, plainPassword, remember): TokenSet`; `login(email, plainPassword, remember): TokenSet` | Single-use activation or verified/throttled login, complete User successor and coupled session insertion. Publish `UserActivated`/`UserLoggedIn` only after commit; return tokens only after publication. |
| `RefreshSession\SessionLifecycle` | `refresh(refreshCredential): RefreshResult`; `logout(refreshCredential): void` | Rotation or secret-free bounded conflict; terminal replay commits revocation before outward refusal. Logout revokes only the selected current session and publishes `CurrentSessionLoggedOut` after commit. |
| `User\PasswordManagement` | `changePassword(authenticatedUserId, currentPassword, newPassword): void`; `resetPassword(userId, resetCredential, plainPassword, actorId = null): void` | Verify the current password or consume the current eligible reset grant, replace User authority, cancel recovery as applicable, revoke sessions and audit together. Publish `PasswordChanged`/`PasswordResetCompleted` after commit. |
| `EmailChangeGrant\EmailChangeConfirmation` | `confirmEmail(userId, emailChangeCredential, actorId = null): void` | Consume the current email grant, promote the pending identity, invalidate reset authority, revoke sessions and audit together. Publish `EmailChangeConfirmed` after commit. No token issuance. |

Passwords and presented credentials remain `SensitiveParameter` inputs. Keep them out of message DTOs, application
logs and diagnostic responses. Token results are immediate sensitive transport material, not serializable CQRS
messages. Existing browser/portable response projections are unchanged. Entry-point authentication, authorization,
throttling beyond the configured login port, response/log sanitization and secure transport remain consumer-owned.
An `authenticatedUserId` argument is a trusted caller assertion, not a new authentication mechanism.

The in-progress [typed audit replacement](audit-evidence.md) additionally requires explicitly admitted
`UserId|AgentId|SystemId|AnonymousId` provenance for reset completion/email confirmation with current mandatory
audit wiring. Their nullable last input is not permission to fabricate origin from the target User or credential.
Missing provenance rejects generically after credential admission and before mutation; trusted callers establish
`$originatingActorId` in the current examples. Declared public unauthenticated entry semantics may explicitly
supply AnonymousId. This task does not make these constructors optional or add audit to other synchronous paths.

## Explicit composition

These examples assume already qualified repositories and ports. All repositories, revocation collaborators and
`TransactionalUnitOfWork` must use the same persistence connection and required transaction-duration fences.
Do not use test doubles as production adapters.

Before, an email-only caller needed the broad 17-dependency `AuthenticationService` construction, including password
hashing/validation, throttle, activation grants, refresh generation, token encoding/policy and a dummy hash:

```php
$authenticationService->confirmEmail($userId, $emailChangeCredential);
$authenticationService->changePassword($authenticatedUserId, $currentPassword, $newPassword);
$authenticationService->resetPassword($userId, $resetCredential, $plainPassword);
$refreshResult = $authenticationService->refresh($refreshCredential);
$authenticationService->logout($refreshCredential);
```

After, construct only the owner needed by the caller. Email confirmation has eight direct dependencies and needs
no password/token ports, throttle, activation repository or dummy hash:

```php
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;

$sessionRevocationService = new SessionRevocationService($refreshSessionRepository);
$emailChangeConfirmation = new EmailChangeConfirmation(
    userRepository: $userRepository,
    emailChangeGrantRepository: $emailChangeGrantRepository,
    passwordResetGrantRepository: $passwordResetGrantRepository,
    sessionRevocationService: $sessionRevocationService,
    auditEvidenceRepository: $auditEvidenceRepository,
    unitOfWork: $unitOfWork,
    clock: $clock,
    eventDispatcher: $eventDispatcher
);
$emailChangeConfirmation->confirmEmail($userId, $emailChangeCredential, actorId: $originatingActorId);
```

Password consumers need ten direct dependencies, not email/activation grants, throttle, token encoding/policy or
refresh generation. The configured dummy hash preserves uniform failed verification behavior:

```php
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;

$passwordManagement = new PasswordManagement(
    userRepository: $userRepository,
    passwordResetGrantRepository: $passwordResetGrantRepository,
    sessionRevocationService: $sessionRevocationService,
    auditEvidenceRepository: $auditEvidenceRepository,
    unitOfWork: $unitOfWork,
    clock: $clock,
    passwordHasher: $passwordHasher,
    passwordValidator: $passwordValidator,
    dummyPasswordHash: $dummyPasswordHash,
    eventDispatcher: $eventDispatcher
);
$passwordManagement->changePassword($authenticatedUserId, $currentPassword, $newPassword);
$passwordManagement->resetPassword($userId, $resetCredential, $plainPassword, actorId: $originatingActorId);
```

Token creation is one cohesive collaborator shared by authentication and refresh. `TokenIssuer` creates session
lifetimes and JWT/refresh results; it does not persist, commit, publish or authorize. Keep one configured
`AuthenticationTokenPolicy` shared with `SessionLifecycle`. The existing policy/result types stay in `User\Security`.
Session consumers need nine direct dependencies and no password, activation/email/reset grant or audit repository:

```php
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;

$tokenIssuer = new TokenIssuer(tokenEncoder: $tokenEncoder, tokenPolicy: $tokenPolicy);
$sessionLifecycle = new SessionLifecycle(
    userRepository: $userRepository,
    refreshSessionRepository: $refreshSessionRepository,
    sessionRevocationService: $sessionRevocationService,
    unitOfWork: $unitOfWork,
    clock: $clock,
    refreshCredentialGenerator: $refreshCredentialGenerator,
    tokenIssuer: $tokenIssuer,
    tokenPolicy: $tokenPolicy,
    eventDispatcher: $eventDispatcher
);
$refreshResult = $sessionLifecycle->refresh($refreshCredential);
$sessionLifecycle->logout($refreshCredential);

$authentication = new UserAuthentication(
    userRepository: $userRepository,
    activationGrantRepository: $activationGrantRepository,
    unitOfWork: $unitOfWork,
    clock: $clock,
    loginThrottle: $loginThrottle,
    refreshCredentialGenerator: $refreshCredentialGenerator,
    passwordHasher: $passwordHasher,
    passwordValidator: $passwordValidator,
    tokenIssuer: $tokenIssuer,
    dummyPasswordHash: $dummyPasswordHash,
    eventDispatcher: $eventDispatcher
);
$tokens = $authentication->activate($userId, $activationCredential, $plainPassword, $remember);
$tokens = $authentication->login($email, $plainPassword, $remember);
```

Authentication has eleven direct dependencies; session insertion still uses
`UserRepository::replaceAuthenticationAuthorityAndAddRefreshSession()`. Supply the repository's coupled session
storage on the actual shared connection, not a second independent insert. Password/email owners remain substantive
use-case coordinators under their aggregate homes; no one-class helper directories or shared lifecycle facade are
needed. Normal non-sensitive Commands/Queries still traverse the configured buses, not injected handlers.

## Outcomes, races and evidence

The decomposition preserves dummy-hash/throttle/verified rehash, grant single use, security-versus-persistence
revisions, complete expected-state CAS, coupled insertion, session lifetimes and replay/conflict behavior.
[User transitions and recovery composition](operation-outcomes.md) define current authority and all-writer fences.
Audit remains required on the existing password/email paths; this change does not implement future optional audit.

Pre-commit faults roll back staged business/audit state. Lost commit acknowledgement remains indeterminate until
an authorized authoritative read resolves state. A post-commit publisher/subscriber fault still throws with committed
state retained; authentication does not inherit Agent issuance's returned-warning exception. Deliberate credential/state refusals remain generic, but unknown faults are not credential refusals.
Each owner emits best-effort `RedactedCommandFailed` with its current
fully qualified class and method, unchanged allowlisted context and operation-constant text. Notification faults
cannot replace the primary throwable. Consumers must still sanitize rethrown errors independently.

## Explicit failure and adapter contracts (Unreleased)

All seven direct calls use Common Application `AuthenticationRequired` for deliberate unusable credentials,
missing/ineligible identity or grant, expired/consumed authority and established generic password/email refusal.
No public response may distinguish those cases. Login throttle denial remains the same generic authentication
refusal, not an account-existence or rate-limit disclosure. Malformed presented credentials are translated only
at their value parser. Domain transition and hydration faults outside those known boundaries, storage/audit/token/
clock/provider faults and native contract failures stay internal; do not classify them by text or integer code.

Activation compare-and-replace loss uses `StateConflict`. Password change/reset and email confirmation retain their
existing generic refusal for expected authority loss. Refresh's benign bounded race remains `RefreshResult::conflict()`
with no tokens or revocation; terminal replay commits the actual family revocation before throwing
`AuthenticationRequired` outside the transaction. Neither a thrown fault nor a dispatch acknowledgement proves rollback.

`RevokeSession` remains a secret-free, successful-void Command on the configured Common bus. Its handler maps intended
absent/unusable targets to `ResourceNotFound`, current-session management refusal to `RuleViolation`, the authorization
port's declared denial to `PermissionDenied`, absent administrative reason to `InvalidInput` and CAS loss to
`StateConflict`. Unknown authorization failures propagate unchanged. Own-session management and consumer-authorized
cross-user management retain their existing scopes; supplied actor/current/target IDs do not authenticate or grant access.
Consumers protect the entry point and bind each identity to the actual authenticated caller.

Repository results must match the requested User ID/canonical email, grant owner/purpose, current/used credential and
session target. Wrong identities or broken promises are internal `LogicException`/native faults, never 401/404/409.
Email confirmation also checks the exact current reservation generation through the Domain's `matchesReservation()`.
Token issuance checks matching usable session ownership, authentication version and credential before encoding.
The session helper validates the complete active-session list (membership, usability and uniqueness) before mutation;
retries preserve immutable family correlation and monotonic revision, with at most three replacements. Disappearance
or exhausted contention during revocation is `StateConflict`. Helpers neither open nested transactions nor audit or
publish independent success facts; enclosing use cases retain rollback/uncertainty and adopted audit ownership.

Update consumer catches directly from the former Domain refusal exceptions to these Common Application categories;
there is no facade, alias or legacy failure mode. Domain exceptions remain inward and do not inherit Application
exceptions. Common `^1.4.1` supplies the consumed categories and password/token/UoW contracts; no generic UoW upgrade
is needed. Known callback tuples, timestamps, session IDs and refresh-result/refusal unions use matching local
annotations. Every callback branch agrees, and no external result is narrowed by such an assertion.

Common's opt-in safe HTTP composition produces generic 401/400/403/404/409/422 responses for the deliberate categories
and 500 for internal faults. Use its public projection/constants, never dependency messages, codes, causes or traces.
The real session Command routing tests exercise execute and serialized dispatch through that composition. This does
not select an endpoint, JWT profile, response transport, MCP/OAuth mapping or consumer persistence implementation.
No optional-audit redesign, new permission, Common/consumer edit or production qualification is implied.

Nonvisual before/after evidence uses real direct capability calls, persisted User/grant/session/audit outcomes,
redacted facts and deterministic competing writers, not delegation mocks or screenshots. Mirrored
`UserAuthenticationTest`, `PasswordManagementTest`, `SessionLifecycleTest` and `EmailChangeConfirmationTest` retain
the prior behavioral cases; recovery lifecycle/concurrency/outcome and registration journeys compose the new owners.
Controlled package repositories and restart models do not qualify real consumer SQL isolation, crypto, shared
connections, process restarts, transport security or deployment. Independent Review and behavioral QA remain required.
