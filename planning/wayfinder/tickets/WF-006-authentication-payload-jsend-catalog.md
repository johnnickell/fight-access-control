# Define authentication payload and JSend response catalog

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Map:** [OpenAPI schema components for v0.2.0](../openapi-schema-components-v0-2-0-map.md)
**Depends on:** [Choose OpenAPI metadata ownership and component discovery](WF-005-openapi-metadata-ownership.md)

## Question

Which public payloads and optional JSend response components should `v0.2.0` publish, and what exact secure shapes
will show a consumer the expected login, refresh, activation, and administrative outputs?

## Must decide

- The initial public catalog, including existing safe views and each authentication input, successful result, and
  bounded refresh-conflict result.
- Canonical field names, requiredness, formats, examples, timestamps, identifier representations, and enum values.
- The distinction between token-result data and consumer transport: whether a raw refresh credential is represented
  as a separately documented body payload, while cookie delivery remains consumer-owned.
- JSend success, fail, and error component boundaries; reusable per-payload envelopes; and which failures are safe
  to describe without leaking authentication details.
- Documentation of delete/no-content and other empty-success outputs without prescribing consumer routes or HTTP
  status choices.

## Decisions so far

1. **Authentication credential profiles are settled.** The browser response body excludes the raw refresh
   credential, allowing a consumer to issue it only through an `HttpOnly` cookie. A separately named portable
   token-set payload includes the refresh credential for non-browser clients or consumers that intentionally return
   it in a response body. Both payloads include the access token and its expiry; credential delivery stays
   consumer-owned.
2. **JSend is optional and bounded.** The catalog provides explicit JSend success envelopes for published payloads
   and the secretless refresh-conflict result. Consumers continue to own validation-failure and error mapping,
   status codes, and messages.
3. **Catalog scope is complete.** `v0.2.0` publishes request components for every package Command and Query, safe
   output components for every Query result, the synchronous authentication-service inputs and results, and named
   empty-success components where a package operation has no payload. Component metadata describes values only; a
   consumer chooses whether each field is a path, query, header, cookie, or request-body parameter.
4. **Canonical message fields are settled.** Each Command and Query component uses its existing `toArray()` field
   names and snake case. All constructor-required values are required by the schema, including actor and target
   identifiers that a consumer may source from a path or authenticated principal instead of a request body.
5. **Pagination and collection outputs are settled.** List components use the Fight Common result shape:
   `page`, `per_page`, `total_pages`, `total_records`, and typed `records`. The catalog provides shared pagination
   request and JSend success-envelope components as well as typed collection and payload envelopes.
6. **Mutation success supports safe read results or emptiness.** A consumer may return the applicable updated safe
   resource component and its JSend success envelope after a mutation, or refresh it separately. Deletion uses empty
   success. `JSend.Success.Empty` describes `{"status":"success","data":null}` for a body-bearing response; a
   real HTTP `204` has no body and therefore references no response-payload schema. Consumers choose the status and
   form; the package does not present a body as valid for `204`.
7. **Shared primitive conventions are settled.** Every context-owned identifier is a string with OpenAPI `uuid`
   format, every `*_at` timestamp has `date-time` format, and every enum lists its current serialized values exactly.
8. **Creation responses are settled.** `CreatedResource` is the shared successful response for a command that creates
   one externally addressable resource. Its body is `{"id":"<uuid>"}` and `JSend.Success.CreatedResource` wraps
   that body when a consumer uses JSend. Command-input components continue to use their exact package field names.
9. **Secret documentation is settled.** Request-only secrets are `writeOnly` with no examples. Browser responses
   exclude refresh credentials, while portable token-set responses include a required sensitive `refresh_token`
   without an example.

## Resolution boundary

This ticket settles the schema catalog and examples only. It does not implement attributes, alter runtime
serialization, create HTTP adapters, or select a consumer project's routes, cookies, error mapping, or UI.

## Resolution

Every schema key begins `Fight.AccessControl.`. Component fields use the existing `toArray()` representation; a
consumer maps them to path, query, header, cookie, or body locations as appropriate.

### Shared components

- `PaginationRequest`: `page`, `per_page`, and `orderings`.
- `CreatedResource`: required `id` UUID, with `JSend.Success.CreatedResource`.
- `JSend.Success.Empty`: `{"status":"success","data":null}`; a real `204` has no body or schema.
- Typed JSend success components wrap every published safe payload and collection as
  `{"status":"success","data":<payload>}`. The package publishes no generic fail or error component.

### Authentication components

`ConfirmEmailRequest`, `ChangePasswordRequest`, `ResetPasswordRequest`, `ActivateRequest`, `LoginRequest`,
`RefreshRequest`, and `LogoutRequest` describe all `AuthenticationService` inputs. Request-only credentials and
passwords are `writeOnly` and have no examples.

`Authentication.BrowserResponse` has required `user_id`, `refresh_session_id`, `access_token`,
`access_token_expires_at`, `refresh_expires_at`, and `remembered`. It intentionally excludes `refresh_token` so a
consumer can issue that credential only as an `HttpOnly` cookie. `Authentication.TokenSet` adds required sensitive
`refresh_token` for a non-browser or explicit body-token profile; it is not `writeOnly`, because this profile
returns it. `Authentication.RefreshConflict` is the secretless `{"outcome":"conflict"}` payload. Each has a
typed optional JSend success envelope.

### Command and query inputs

The catalog includes one input component for every message below, using its exact current canonical array shape:

| Context | Commands | Queries |
| --- | --- | --- |
| ActivationGrant | `DeliverUserInvitation`, `ResendInvitationDelivery`, `RetryInvitationDelivery` | `FindInvitationDeliveryStatus` |
| Agent | `GrantPermissionToAgent`, `ReplaceAgentPermissions`, `RevokePermissionFromAgent` | `GetAgentById`, `ListAgents` |
| EmailChangeGrant | `CancelEmailChange`, `DeliverEmailChange`, `ExpireEmailChange`, `RequestEmailChange` | — |
| ManagedPolicy | `ReconcileManagedPolicy` | `PreviewManagedPolicy` |
| PasswordResetGrant | `ConfirmPasswordResetDelivery`, `ExpirePasswordResetDelivery`, `RequestPasswordReset` | — |
| Permission | — | `GetPermissionById`, `ListPermissions` |
| RefreshSession | `RevokeSession` | `ListActiveSessions` |
| Role | `CreateCustomRole`, `GrantPermissionToCustomRole`, `RemoveCustomRole`, `RenameCustomRole`, `RevokePermissionFromCustomRole` | `GetRoleById`, `ListRoles` |
| User | `AssignRoleToUser`, `CorrectPendingInvitation`, `DeleteUser`, `DisableUser`, `EnableUser`, `InvitePendingUser`, `RemoveRoleFromUser`, `RestoreUser` | `GetUserById`, `ListUsers` |

All context-owned IDs use OpenAPI `string` and `uuid`; every `*_at` field uses `date-time`; all enums list their
current serialized values exactly.

### Safe query outputs

| Payload | Exact fields |
| --- | --- |
| `InvitationDeliveryStatus` | `user_id`, `status`, `expires_at` |
| `Agent` | `agent_id`, `name`, `state`, `credential_id`, `credential_revision`, `permission_assignment_revision`, `permissions` |
| `Permission` | `permission_id`, `name`, nullable `tier`, `managed` |
| `Session` | `session_id`, `user_id`, `created_at`, `last_activity_at`, `idle_expires_at`, `absolute_expires_at`, `remembered`, `current` |
| `Role` | `role_id`, `name`, `managed`, `permission_ids` |
| `User` | `user_id`, `email`, `state`, `role_ids`, `created_at`, `updated_at` |
| `ManagedPolicyPlan` | typed `permissions` and `roles`, each including its serialized `action` |

`AgentCollection`, `PermissionCollection`, `SessionCollection`, `RoleCollection`, and `UserCollection` use the
Fight Common result shape: `page`, `per_page`, `total_pages`, `total_records`, and typed `records`.

A consumer may return an applicable safe resource result after a mutation, or refresh it separately. Creation may
return `CreatedResource`; deletion uses empty success. Routes, status codes, HTTP headers, cookies, failures, and
error mapping remain consumer-owned. See [ADR 0008](../../adr/0008-openapi-payload-contract.md).
