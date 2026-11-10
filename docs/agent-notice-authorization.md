# Agent notice recording and bounded removal

Unreleased [TASK-00091](../planning/tasks/00091-TASK.md) composes direct Agent Permissions with Common's
`NoticeAdmission` and `NoticeRemovalAdmission`. Common **v1.5.0** is the sufficient supported minimum, already
required by the manifest; published source is `06fb5cd3720af9cf8295198f74040d95b64c5155`. There is no AccessControl
notice store, handler, scheduler, automatic grant or protocol engine. Independent Review and behavioral QA remain
required. The [contract inventory](common-contracts.md) records the supplier baseline.

## Authority and ownership flow

```mermaid
sequenceDiagram
    participant Caller as Trusted request or worker
    participant Common as Common routed Command / handler
    participant Storage as Common notice transaction / repository
    participant Admission as Agent notice admission
    participant Authority as Current Agent / Permissions
    participant Policy as Consumer policy
    Caller->>Common: RecordNotice or RemoveNotices
    Common->>Storage: Begin and acquire notice serialization lock
    Storage-->>Common: Lock acquired (possibly after waiting)
    Common->>Admission: admit(notice or exact selection)
    Admission->>Authority: Resolve original bound credential and current direct requirements
    Admission->>Policy: Check current producer / scope / content / references
    Note over Admission,Policy: No continuing allow and no authority-writer lock
    alt Recording
        Common->>Storage: Identity lookup; compare or append
    else Removal
        loop Every exact retained target, before any deletion
            Common->>Storage: Lookup exact identity and position
            Common->>Admission: admitRetained(full notice), resolving authority again
            Admission->>Policy: Independent retained-content / retention check
        end
        Common->>Storage: Remove selected incarnations with relationship / loss evidence
    end
    Common->>Storage: Actual commit (or rollback / uncertain outcome)
    opt Newly recorded, acknowledged commit only
        Common-->>Caller: Existing NoticeRecorded dispatch attempt
    end
```

Previously the package supplied no Agent implementation of these neutral mutation admissions. Now the configured
admissions run **inside** Common's storage-locked operation, before original/retry lookup and before any selected
deletion. A principal resolved before dispatch or a storage-lock wait is not the mutation authority. Nonvisual
Command/state/event tests are useful Before/After evidence; screenshots cannot establish this ordering.

## Explicit composition

Use the same admitted request provider to construct `AgentContinuationBinding::fromRequest($provider, $request,
$correlationId)`. For queued work use `AgentContinuationBinding::fromHandoff($trustedHandoff)` only after the consumer
verifies provenance, integrity and worker recipient. Preserve the original authentication time and original credential
identity/revision; never replay the signed request/nonce or adopt a successor credential. See
[continuation authorization](agent-continuation-authorization.md) and [request authority](authority-contracts.md).

Configure **separate** `AgentNoticeAuthority` instances for recording and removal. Their nonempty conjunctive
`PermissionName` lists are trusted application configuration, not request fields. Names below are illustrative;
the package defines or grants none. Consumer `AgentNoticePolicy` implements all three decisions independently:

- `allowsRecording`: trusted producer association, current scope, safe metadata, topic/type and **every** subject,
  recipient, author and causation relationship. Structural Common bounds cannot detect secrets.
- `allowsRemoval`: every exact selected scope/producer/ID/position and namespace before identity lookup, including
  missing and repeated requests. IDs, role labels, prior recording and read grants are not authority.
- `allowsRetainedRemoval`: every retained notice's complete content, relationships and retention eligibility.
  Common preflights all selected records before deleting any. Do not consult a cached selection allow instead.

```php
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAdmission;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeAuthority;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticeRemovalAdmission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeRepository;
use Fight\Common\Adapter\Mcp\Persistence\Notice\Dbal\DbalNoticeTransaction;
use Fight\Common\Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter;
use Fight\Common\Adapter\Messaging\Command\Sync\RoutingCommandBus;
use Fight\Common\Application\Mcp\Notice\CommandHandler\RecordNoticeHandler;
use Fight\Common\Application\Mcp\Notice\CommandHandler\RemoveNoticesHandler;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRecorder;
use Fight\Common\Application\Mcp\Notice\Service\NoticeRemover;

$recordAuthority = new AgentNoticeAuthority(
    $binding, $agentRepository, $permissionRepository, $clock,
    [PermissionName::fromString('RECORD_NOTICES')]
);
$removeAuthority = new AgentNoticeAuthority(
    $binding, $agentRepository, $permissionRepository, $clock,
    [PermissionName::fromString('REMOVE_NOTICES')]
);
// Consumer deployment installs the Common schema separately on an idle connection.
$transaction = new DbalNoticeTransaction($noticeConnection);
$noticeRepository = new DbalNoticeRepository($transaction);
$recordHandler = new RecordNoticeHandler(new NoticeRecorder(
    $noticeRepository, $transaction, new AgentNoticeAdmission($recordAuthority, $noticePolicy), $eventDispatcher
));
$removeHandler = new RemoveNoticesHandler(new NoticeRemover(
    $noticeRepository, $transaction, new AgentNoticeRemovalAdmission($removeAuthority, $noticePolicy)
));
$router = new InMemoryCommandRouter();
$router->registerHandlers([
    $recordHandler::commandRegistration() => $recordHandler,
    $removeHandler::commandRegistration() => $removeHandler
]);
$commandBus = new RoutingCommandBus($router);
// Execute actual Common RecordNotice / RemoveNotices through this configured bus.
```

The concrete DBAL example stays in consumer bootstrap/Adapter, never inward in Application. DBAL `^4.4` is a
**development-only** dependency for real package integration fixtures, not mandatory production persistence.
Production consumers select Common's supported storage composition and engine themselves. Protect alternate direct
Command/worker entry points using the same configured admissions, not only a Tool or HTTP route. MCP presentation
remains in Adapter/Mcp; these classes implement neutral Application admission ports and contain no protocol glue.

## Freshness, effects and failures

`AgentNoticeAuthority` resolves cohort/restoration readiness, current Agent existence and ACTIVE original credential,
exact complete direct ADMIN_SAFE Permission definitions, every configured requirement and the original five-minute
provenance window afresh on every call. Consumer policy then checks current scope/content. Ordinary request providers
retain their existing request-local snapshot; authentication signature/nonce consumption occurs once and is not undone
by later notice denial or faults. The notice transaction's no-change lock UPDATE is a separate effect from nonce
consumption and actual notice mutation.

This composition deliberately does **not** run `AgentContinuationGuard` around an outer Command or return its allow:
that would check before a notice-lock wait. It reuses the trusted binding at the actual Common admission seam, without
changing the guard's existing synchronized-action contract or adding an all-writer authority fence. Authority/scope
may change after the check; no package claim excludes that gap, interrupts work or qualifies production writer races.

Known absence/inactive/original-credential mismatch, missing operation Permission, expired provenance or deliberate
consumer refusal become fixed Common `NoticeRejected` / `NoticeRemovalRejected`, with no private previous cause or
existence/count output. Wrong Agent identity, malformed/incomplete/non-ADMIN_SAFE Permission snapshots, cohort,
repository, clock and policy faults remain **faults**, never Boolean refusal, healthy no-op or success. These classes
do not log or render. Configure one redaction-aware safe reporting/presentation boundary for the actual entry point;
never expose raw throwable messages/causes, dump the service graph or use exception-to-Boolean fallback. MCP, ordinary
HTTP and worker diagnostics keep their own protocols; do not install a second reporter or assume HTTP categories are
MCP Tool mappings. Common storage/dispatch faults may retain private causes for the trusted owner, not public output.

Common still owns deduplication/conflict, monotonic position allocation, comparison and relationship persistence,
namespace/position-pinned finite removal (1–100 distinct targets), all-target preflight, cascade/loss evidence,
commit/rollback/uncertainty and unchanged unselected survivors. Missing/repeated/wrong-position/stale-namespace removal
is fixed rejection, not successful absence. New acknowledged recording commits alone attempt `NoticeRecorded`;
removal emits no event. `CommittedNoticeDispatchFailed` means committed data plus failed publication, not rollback,
automatic retry, redispatch or receipt proof. Retrying an already committed equal notice does not redispatch.
`NoticeStorageFailed::outcomeUncertain()` never proves success or rollback; do not blindly retry or report absence.

## Package evidence and limits

Mirrored `Agent/Notice/AgentNoticeAdmissionTest` composes real authentication/binding and current authority with
Common's actual router, Commands, handlers, recorder/remover, DBAL transaction/repository/schema and SQLite storage.
It checks separate permissions and consumer policies, concealed retries, all-target rejection, exact data/positions,
relationship/comparison/loss state, nonce separation, rollback, both lost-commit-acknowledgement outcomes and
post-commit dispatch failure. Worker fixtures hold a real SQLite write lock in another PHP process, change controlled
authority/scope before release and prove fresh recording/removal checks after the wait, without reauthentication.

Exact-minimum and ordinary Composer lanes exercise this same composition. TASK/ignored Work evidence records the
actual installed tag/source/lock, complete logs/exits, owned coverage and canonical gate result. Controlled fixtures
qualify this package integration only: not production consumer policy, persistence/crypto, handoff durability,
authority-writer synchronization, engine races, scheduler, deployment, client receipt or sibling notice capabilities.
Read/recovery, listening, live disclosure, subscriber inspection and publication remain independent unfinished slices.
