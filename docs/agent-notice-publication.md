# Optional Agent notice publication

TASK-00096 supplies `AgentNoticePublicationPolicy` and consumer `AgentNoticePublicationScope` under
`Application/AccessControl/Agent/Notice`. They implement Common's existing publication-policy seam; they add no
business Command, mutation, broadcaster, notice store, worker, retry loop or automatic service registration.

## Explicit composition

Use Common **^1.5.0**, sufficient tagged source `06fb5cd3720af9cf8295198f74040d95b64c5155`; see the
[contract inventory](common-contracts.md). Optional Mercure composition uses **symfony/mercure ^0.7** (qualified
minimum 0.7.0). Mercure is development-only here for real adapter integration tests, suggested rather than a
mandatory runtime dependency. All transport implementation stays in Common `Adapter/Socket`, not Application.

```php
$policy = new AgentNoticePublicationPolicy(
    new AgentNoticeAuthority(
        $trustedOriginalBinding,
        $agentRepository,
        $permissionRepository,
        $clock,
        $configuredPublicationRequirements // Nonempty list of PermissionName, all required
    ),
    $consumerPublicationScope
);
$publication = new NoticePublication(
    $trustedInspectionQueryBus,
    $policy,
    $privateDiagnostics,
    new MercureHubPublisher($hub),
    new PrivateMercureHubPublisher($hub)
);
$recordAndPublish = new RecordNoticeAndPublish($synchronousRecordingCommandBus, $publication);
$status = $recordAndPublish->record($recordNotice);
// A later explicit attempt uses retained committed content, never caller-supplied replacement data:
$status = $publication->publish(new GetNotice($scope, $producer, $noticeId));
```

Types above are AccessControl's `AgentNoticeAuthority`/`AgentNoticePublicationPolicy`, Common's
`Application/Mcp/Notice/Publication/{NoticePublication,RecordNoticeAndPublish}` and
`Domain/Mcp/Notice/Query/GetNotice`, plus Common `Adapter/Socket/{MercureHubPublisher,PrivateMercureHubPublisher}`.
Consumers supply collaborators explicitly. The recording bus must synchronously route Common's real
`RecordNoticeHandler`, not a queue whose dispatch return is not commit acknowledgement. The inspection bus must
route Common `GetNoticeHandler` with its actual committed reader and separately configured inspection admission.
It need not reuse the remote caller's read grants. Caller recovery uses its own independently admitted bus;
publication grants never imply recovery authority.

### Source-linked runtime trace

This is a bounded call-order view of the new composition, not a new transaction or static outward dependency.
Common source links below are pinned to the qualified supplier; AccessControl links describe this TASK's candidate.

| Order | Owner / operation | Boundary and observed meaning |
| --- | --- | --- |
| 1 | Common [RecordNoticeAndPublish::record](https://github.com/johnnickell/fight-common/blob/06fb5cd3720af9cf8295198f74040d95b64c5155/src/Application/Mcp/Notice/Publication/RecordNoticeAndPublish.php) | Indirect synchronous CommandBus → actual recording handler, acknowledged commit then separate publication. Recording failure prevents this handoff. |
| 2 | Common [NoticePublication::publish](https://github.com/johnnickell/fight-common/blob/06fb5cd3720af9cf8295198f74040d95b64c5155/src/Application/Mcp/Notice/Publication/NoticePublication.php) | Indirect QueryBus → GetNoticeHandler/committed reader. Reconstruct current retained data, not an assertion that arbitrary payload was saved. |
| 3 | [AgentNoticePublicationPolicy::currentGrant](../src/Application/AccessControl/Agent/Notice/AgentNoticePublicationPolicy.php) | Fresh [AgentNoticeAuthority::resolve](../src/Application/AccessControl/Agent/Notice/AgentNoticeAuthority.php), then consumer scope. Null authority never reaches scope. |
| 4 | [AgentNoticePublicationScope::currentGrant](../src/Application/AccessControl/Agent/Notice/AgentNoticePublicationScope.php) | Consumer checks whole audience, exact finite topic, mode and fields and returns a Common grant or null. Faults propagate to Common's single diagnostics owner. |
| 5 | Common [NoticePublicationGrant::message](https://github.com/johnnickell/fight-common/blob/06fb5cd3720af9cf8295198f74040d95b64c5155/src/Application/Mcp/Notice/Publication/NoticePublicationGrant.php) then selected publisher | Complete encoded projection is bounded before the public OR private adapter sends. No public fallback, authority lock, transaction or recording mutation spans hub I/O. |

The [executable composition](../tests/Application/AccessControl/Agent/Notice/AgentNoticePublicationPolicyTest.php)
observes these seams with real Agent authority, routed Common handlers, SQLite and actual Mercure `Update` objects.
This small extension implements an existing inward port; it changes neither class ownership nor dependency direction.

## Independent current authority and consumer disclosure

A trusted request binding comes from `AgentContinuationBinding::fromRequest()` after real request admission;
a background worker reconstructs it only through `fromHandoff()` and a trusted `AgentContinuationHandoff` verifier.
Do not serialize a binding, trust arbitrary stored claims, retain the request provider as a singleton, replay its
nonce or switch to a replacement credential. See [current authority](authority-contracts.md).

Each attempt resolves the original Agent ID/credential/revision, current direct Permission definitions and the
original context's validity, using TASK-00088's existing facilities. All configured operation requirements are
conjunctive. Missing/revoked/rotated/expired authority denies; inconsistent snapshots/resolver faults are failures,
not Boolean denial. No read/listen/record Permission, role label, recipient, topic match or earlier success grants
publication. No Permission vocabulary or grants are installed by the package.

The consumer scope receives that fresh principal and the complete retained `NoticeView`. It must independently
admit the operation's exact topic, public/private mode, whole intended transport audience and every disclosed field.
It may require additional direct mode-specific Permissions. Deny unrecognized topic/audience combinations rather
than deriving authority from `recipients`, author or human roles. Hub/browser entitlement, subscriber configuration,
producer/content trust and the actual consumer policy remain consumer-owned. A private flag is not audience proof.

The scope must authorize the mandatory scope/producer/notice identity too. Select no protected bodies, full Task
state, raw subscription envelopes, hidden identities/topics, credentials or private exception material. Common
projects retained fields only and never automatically reads a linked target. Metadata structural bounds are **not**
a universal redactor: safe capture and audience-specific disclosure require consumer policy. The scope is fresh per
attempt; no cached lease is permitted. Permission/scope can change after the final check before the send. No
send-spanning lock, all-writer fence, atomic check-and-send or byte retraction is promised or required.

## Bounds, outcomes and reporting

Common grants accept one HTTPS topic, at most 256 bytes and its finite ASCII URL grammar (no query/fragment),
selected known fields and at most 16 declared metadata keys. Retained references remain bounded ASCII identities
(up to 96 bytes). The final JSON projection has at most **32 references and 4096 encoded bytes**, including mandatory
identity, selected fields, escaping and multibyte expansion. Oversize or unavailable fields reject, never truncate.

| Status / exception | Meaning |
| --- | --- |
| `PUBLISHED` | Selected publisher returned. Not browser delivery, processing, durable receipt or exactly-once proof. |
| `DENIED` | Current operation authority or consumer disclosure scope refused. No send. |
| `NOT_AVAILABLE` | Shared inspection concealed, lost or did not find the retained identity. No publication grant lookup. |
| `UNSUPPORTED` | The required selected publisher is absent. Private mode never tries public. |
| `INVALID` | Common Domain mapping/grant/projection/reference/encoded-size rejection. No send. |
| `FAILED` | Inspection, operational policy/authority or hub fault, including uncertain response. Never public fallback. |
| Recording exception | Distinct from publication status. Precommit failure is not saved; uncertain commit needs original-operation reconciliation. A committed recording dispatch failure is not rollback. |

The single `NoticePublicationDiagnostics` owner receives private failures. It must redact credentials and control
private exception/trace access; statuses contain no private details. Diagnostic failure cannot turn failure into
success or undo retained data. Do not add a competing logger or copy exception messages into public responses.

No transaction spans external publication. A real recording commit survives publication failure without compensating
deletion or rollback. Explicit retry can duplicate a send even when identical recording deduplicates storage; response
loss/partial external effects do not justify automatic retry or imply no send. A recording Event subscriber failure
can prevent the immediate publication handoff despite committed storage; later authorized inspection/publication
can recover it explicitly. This is not an outbox, guaranteed recording/publication or client receipt protocol.

## Retained recovery and disabled output

Use [TASK-00092's retained Queries/polling](agent-notice-recovery.md) after successful, failed or uncertain publication.
Every new caller request needs current independent read authority/scope; publication neither creates nor extends a
read lease. Retention and truthful access-history epochs still determine complete/incomplete/uncertain/restart
outcomes. Read revocation denies separately, and missing retained data never proves that a client processed it.

When Mercure is disabled, omit the publication operation and construct no hub/publisher collaborators. Recording,
retained Queries, polling Tool and existing MCP paths remain usable. If an operation is explicitly constructed
without a required publisher, Common returns `UNSUPPORTED`; that is not a signal to broaden mode or audience.
TASK-00093 Subscriptions, Prompt signals and subscriber inspection are not dependencies. Mercure output does not
qualify MCP notification wire support, deployed hub authentication or browser receipt.

## Qualification limits

Canonical `.runs/handoffs/TASK-00096/work.md` owns exact minimum/ordinary lock/source/runtime identities and check
results. Tests cover real public/private adapters and bounded `Update` inspection, original-bound current authority,
consumer changes/faults, safe failure statuses, actual precommit/commit/handoff behavior, retained recovery and
Mercure-disabled composition. Screenshots add no useful proof for this library boundary; executable state,
status/nonce/effect and hub-call traces are the Before/After evidence.

Controlled policies/hub returns and SQLite fixtures are package integration proof, not deployed consumer
policy/grants, crypto/storage races, hub/browser entitlement, transport delivery or exactly-once effects.
Independent Review and nonvisual behavioral QA remain required; no release, adoption or deployment is claimed.
