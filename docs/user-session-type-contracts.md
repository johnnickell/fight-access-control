# User, session and audit type contracts (Unreleased)

User and RefreshSession messages retain their current field names, native identities, enum values and canonical
serialized outputs. Deserialization now validates the actual input types instead of casting arbitrary values.
This is a pre-v1 tightening of invalid-input handling, not a new message family or a compatibility mode.

## Message input and output

- Identity, email, enum and text fields require strings. Objects with `__toString()`, arrays, booleans and numbers
  are not substitutes. Existing identity/value validation still applies after the string check.
- Every required field must be present. `RevokeSession.reason` and `UserRestored.activation_delivery_id` remain
  required nullable fields in serialized data; their direct PHP constructors retain their existing defaults.
- Event instants require explicit RFC3339 timestamps with an offset, using the existing validated timestamp value.
  Empty, relative, date-only and calendar-normalized timestamps are rejected. Existing `DATE_ATOM` output fields
  keep second precision; `UserRegistered.issued_at` keeps its existing microsecond representation.
- `ListUsers` and `ListActiveSessions` require integer `page`/`per_page` values and named string ordering maps.
  Transport adapters must validate and map their own input before constructing messages. This change does not
  replace Common Pagination, adopt new query semantics or alter its existing bound/direction normalization.
- Output annotations describe each message's concrete array shape. Repository pages retain their existing element
  types; User/RefreshSession lookups still return the aggregate or `null`. Required-query absence policy remains
  separate work.

For example, a decoded session-revocation payload must carry explicit nullable data:

```php
$command = RevokeSession::fromArray([
    'actor_id' => $actorId->toString(),
    'current_session_id' => $currentSessionId->toString(),
    'target_session_id' => $targetSessionId->toString(),
    'reason' => null
]);
```

These are non-sensitive intent messages. Passwords, activation/reset credentials and refresh credentials remain
inputs to the [direct synchronous capabilities](user-authentication.md), never new serializable messages.

## Aggregate and persistence values

User validates its persisted Role list before building its duplicate-free `RoleId` set. Complete-set mutation
accepts an iterable, validates each element before changing current state, and preserves equal-set/no-op behavior.
`UserView` validates and retains a list of Role identities; it still exposes neither credentials nor persistence
revisions. Native ID, state and immutable timestamp values remain unchanged.

User authentication version is positive; authentication-authority, assignment, reservation and canonical-email
revisions are nonnegative. A transition requiring an exhausted integer counter fails with a Domain exception
before changing any state. Valid no-ops do not need another revision. Security version and login/persistence
revision remain distinct under the [transition contract](operation-outcomes.md#user-authentication-transitions-unreleased).
Persist all actual current values; do not infer them from lifecycle state or fill missing persisted fields from
invitation defaults.

RefreshSession validates positive authentication version, nonnegative revision, exact hexadecimal digest shapes
and an ordered, duplicate-free used-digest list disjoint from the current digest. Rotation history and its timestamp
must agree with last activity and the bounded lifetime. Rotation cannot reuse the current or a historical
credential. Before the first rotation, the most-recently-used credential predicate returns false without PHP
diagnostics for both current and unrelated credentials. After rotation, only the latest predecessor can match,
from the rotation instant inclusive to the conflict-window deadline exclusive; older history remains replay
evidence, not a benign conflict. Exhausted persistence revisions reject without producing a successor. Raw refresh
transport values must be exactly 64 lowercase hexadecimal characters, with no whitespace or newline suffix.

Retain all existing expected-state comparisons, exact revision advancement, coupled User/session insertion,
transaction-duration fences and required audit. Compare persisted immutable instants without discarding their
subsecond precision. Package test repositories now preserve that precision in User CAS comparisons; this is not
qualification of a consumer database, ORM mapping, shared connection or concurrent writers.

## Audit and redacted context

TASK-00122's in-progress [typed evidence replacement](audit-evidence.md) removes the actor/action strings and
arbitrary context map directly. It supplies direct typed provenance, finite subjects/actions, trusted outcome time,
identity/correlation and bounded revision/relationship facts. Existing constructors remain mandatory-wired; actual
optional family composition and expanded coverage are separate. Caller/fixture/schema reconciliation and full
qualification remain unfinished; this is not an accepted or released replacement.

`RedactedCommandFailed` retains named context with genuinely dynamic values, including existing booleans, nested
safe data and nulls. The field map and outer string fields are validated, not falsely annotated as all-string
context. Producer allowlisting and operation-constant notification text remain mandatory. Shape checks do not
sanitize arbitrary diagnostics or make rethrown exceptions safe. Preserve the existing
[notification, transaction and recovery composition](operation-outcomes.md).

## Verification boundary

Owning contract tests exercise all current User/RefreshSession Commands, Queries and Events, valid canonical round
trips, every missing field, mismatched types and invalid event instants. Hydration, typed collections, revision
exhaustion and timestamp-preserving test persistence have dedicated regressions. Existing authentication, lifecycle,
recovery, session, audit and OpenAPI scenarios remain in the canonical gate. Package models and static probes do
not qualify consumer persistence, crypto, provider delivery, process restart or transport policy; independent
technical Review and behavioral QA remain separate acceptance stages.
