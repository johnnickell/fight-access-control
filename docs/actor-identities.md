# Fixed actor identities (Unreleased)

`Fight\AccessControl\Domain\AccessControl\Audit\SystemId` and `AnonymousId` are direct Common `UniqueId`
values owned by audit attribution. They require no account, login, registry, singleton or actor wrapper.

```php
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\Common\Domain\Value\Identifier\Uuid;

$systemId = SystemId::generate();
$anonymousId = AnonymousId::fromString(Uuid::NIL);
```

Unlike ordinary freshly generated identities, both always contain canonical `Uuid::NIL`:
`00000000-0000-0000-0000-000000000000`. `generate()` creates a value, not a singleton or a random ID.
`fromString()` accepts only that exact string. Non-nil UUIDs, malformed input, whitespace, braces and URN
spellings throw Common Domain `DomainException`; no coercion or fallback actor is selected.

Common's supported value reconstruction contract is `fromString($id->toString())` on the **known concrete type**.
String casts and JSON serialization contain the same nil text, not a kind discriminator. Equality and hashes
retain the concrete type: SystemId, AnonymousId, UserId and AgentId are not interchangeable even with identical
UUID text. Same-kind equality/hash and comparison follow Common; no cross-kind ordering is promised.
Native PHP object deserialization/reflection are not persistence hydration contracts. Do not use them to admit
untrusted state. Consumers must retain and validate actor kind alongside the UUID and reconstruct through the
appropriate `fromString()` factory, never infer kind from nil text. The evolved evidence persistence contract is
implemented in progress by [TASK-00122](../planning/tasks/00122-TASK.md); see the
[typed evidence contract](audit-evidence.md). Its writer/caller/full-gate qualification is not yet complete.

Use SystemId for deliberate autonomous provisioning or maintenance and AnonymousId for a deliberately
unauthenticated initiating request. Preserve the original admitted UserId/AgentId when a worker executes that
initiator's work. Missing actor data is not permission to substitute either fixed identity. Attribution proves
neither authentication nor authorization and grants no permission; trusted callers and current entry/target/scope
policy remain responsible for those decisions.

These values are **not unique evidence-record IDs**, message IDs or deduplication keys. Audit evidence needs its
own unique non-nil AuditEvidenceId under TASK-00122. The original fixed-value prefactor changed no evidence
constructor or writer. The subsequent shared replacement now uses direct actors in current writers and kind-plus-ID
message round trips, without changing optional constructor wiring or expanding currently unaudited paths. The
broader [approved audit destination](../planning/epics/00017-EPIC.md) remains separately staged. Package value tests do not qualify consumer storage or provenance authentication.
