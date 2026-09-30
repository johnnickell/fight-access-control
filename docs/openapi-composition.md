# OpenAPI composition

Fight AccessControl provides only reusable value schemas. It does not provide an
OpenAPI document, paths, routes, status codes, cookies, error envelopes, security
schemes, or a documentation UI. Install the suggested generator in the consuming
application, then scan this package's opt-in bootstrap with the consumer's own
anchors:

```php
require __DIR__.'/vendor/johnnickell/fight-access-control/openapi/bootstrap.php';
```

For a disposable local proof, create a consumer anchor containing `#[OA\OpenApi]`
and one consumer schema, require the bootstrap above, and scan both files:

```bash
vendor/bin/openapi --bootstrap consumer.php consumer.php vendor/johnnickell/fight-access-control/openapi -o openapi.json
```

In a development checkout of this package, run the generated-contract checks with:

```bash
./bin/phpunit --no-coverage tests/OpenApi
```

The default PHPUnit suite, normal CI, and `./bin/build` run these same integration tests. They compose the catalog
with test-only consumer roots, compare delivery schemas with owning enums and real serialized values, and check
required/nullable fields, formats, constraints and references. The retained authentication, Permission and collection
composition assertions also run here rather than only during release. No source-text or schema-count assertions are
used. The fixtures use OpenAPI 3.1; generation skips complete-document validation because these component-only
consumers deliberately define no paths. This is not qualification of a consumer's endpoints or generated clients.

For a release candidate, `./bin/release certify <version>` reuses `tests/OpenApi` for its separate composition log,
plus planning integrity and the complete package gate. It requires a clean checkout and a matching dated
`## [<version>] - YYYY-MM-DD` changelog heading, then records the exact `HEAD`
and command logs under ignored `.runs/`. Certification does not merge, tag, push,
or publish anything.

The catalog uses canonical snake-case `toArray()` keys. UUID identifiers use
`uuid`; `*_at` values use `date-time`; paginated administrative results have `page`, `per_page`,
`total_pages`, `total_records`, and typed `records`.

### Feature provisioning (unreleased)

`Fight.AccessControl.ProvisionFeatures` describes the required nullable `default_permission_name` string. This is
raw consumer configuration: a missing Feature requires a canonical resolvable Permission name, but a no-creation
pass ignores unused configuration. The schema therefore adds no name-pattern constraint. Candidate references come
from consumer-composed discovery, not an HTTP payload or serialized scanner. See [atomic provisioning](feature-provisioning.md).
This additive component introduces no endpoint or activation-readiness result. Generated-schema tests compare it
with actual command serialization in the default suite; no Feature release version is selected.

### Credential-delivery values

Worker-facing Commands, Queries and safe results are part of the catalog even when a consumer never exposes them
over HTTP. All names below have the `Fight.AccessControl.` prefix:

| Component | Canonical value |
| --- | --- |
| `DeliverPasswordReset` | `actor_id` (an arbitrary string, including worker provenance), UUID `user_id` and `password_reset_delivery_id` |
| `FindCredentialDeliveryStatus` | `purpose` (`activation`, `password_reset`, `email_change`) and nonempty UUID `delivery_id` |
| `FindDueCredentialDeliveries` | `at` (`date-time`, including fractional seconds) and positive integer `limit`; no default or upper bound is imposed |
| `CredentialDeliveryStatus` | `CredentialDeliveryStatusView`: purpose, delivery/User IDs, revision, status, due/expiry times, attempt count and nullable attempt/outcome/failure history |
| `DueCredentialDelivery` | Purpose, delivery/User IDs, due time, revision and status |
| `DueCredentialDeliveries` | An unpaginated array of `DueCredentialDelivery`; an empty array is valid |

All canonical fields are required, including `last_attempt_at`, `last_outcome_at` and `last_failure`, whose values
may be null. Non-null `last_failure` is only `retryable_provider`, `unexpected_provider` or `permanent_provider`;
arbitrary provider details are not exposed. Attempt counts start at zero. Revisions retain the integer value
supplied by the owning snapshot without adding a schema-only bound. The generic status Query checks a nonempty
string; its handler resolves a purpose-specific UUID, consistent with the catalog's identifier convention.

`InvitationDeliveryStatus`, `CredentialDeliveryStatus` and `DueCredentialDelivery` use exactly `pending`, `claimed`,
`retry_pending`, `delivered`, `permanent_failure`, `expired` and `invalidated`. The obsolete `failed` and `confirmed`
values are no longer admitted. These safe results describe no credential, ciphertext, destination email, or claim
token. The status View component intentionally omits the PHP `View` suffix, like the existing invitation component.

`JSend.Success.InvitationDeliveryStatus` keeps its existing reference. Optional
`JSend.Success.CredentialDeliveryStatus` describes a found status result and `JSend.Success.DueCredentialDeliveries`
wraps the array directly, without ResultSet pagination. The status handler may return null for an absent generation;
consumers own absence mapping rather than receiving a package-defined HTTP response. Schemas grant no access:
consumer entry-point authorization, routes, HTTP status codes and delivery invocation remain unchanged.

This unreleased correction narrows the invitation status enum. Under [ADR 0007](../planning/adr/0007-openapi-schema-metadata-distribution.md),
it needs the next minor `0.x` release, not an assumed patch or replacement of v0.4.0. Recompose consumer documents and
regenerate clients as appropriate when adopting that future release. Version approval and publication are separate.

### Agent delivery values

The unreleased `ListDueAgentDeliveries` component mirrors the bounded worker Query, including required `limit`
(1–100, constructor default 50), original scope and registered destination binding. `DueAgentDeliveries` is an
unpaginated array of confirmed safe `AgentOperationView` payloads, not a ResultSet. Its shared `AgentOperationKey`,
`AgentIssuance`, and confirmed/indeterminate `AgentOperation` schemas contain no delivery material, claims, receipts
or decryption handles. `JSend.Success.DueAgentDeliveries` is optional. These worker-facing value contracts introduce
no endpoint or authorization policy; see [recovery composition](agent-delivery-recovery.md).
`ListAgentDeliveryMaintenance` describes original scope/destination, `material` or `cleanup` work, bounded
`batch_size` (default 50; 1–100), `cleanup_grace_seconds` (default 86400; 1–604800) and required nullable UUID `after`.
`AgentDeliveryMaintenance` is a bounded array of confirmed safe operation views. `CountAgentDeliveryKeyReferences`
accepts only an opaque `key_version`; `AgentDeliveryKeyReferences` is a nonnegative global diagnostic integer, never
key-retirement permission. `AgentMaintenanceResult` mirrors the safe backed result enum. See
[maintenance composition](agent-delivery-maintenance.md); no key paths, ciphertext, endpoint or raw-secret output is added.
Generated Agent discovery/maintenance schema integration tests run in the default PHPUnit/build pipeline without
contributing incidental Domain/Application execution to statement coverage. Release certification remains separate.

Use `Authentication.BrowserResponse` for browser JSON: it has no refresh token,
so the consumer may issue that credential only in an `HttpOnly` cookie. Use
`Authentication.TokenSet` for an explicit portable body-token profile; it includes
the required `refresh_token`, with no example. Request-only passwords and
credentials are write-only and intentionally have no examples.

`CreatedResource` represents `{"id":"<uuid>"}`. A mutation may instead return
its relevant safe resource. `JSend.Success.Empty` represents
`{"status":"success","data":null}` for a body-bearing response; an HTTP 204
has no body or schema. Typed JSend success envelopes are optional consumer response
choices; failures and errors remain consumer-owned.
