# Public payload catalog

This is the current declaration-to-component map for [consumer OpenAPI composition](openapi-composition.md).
Names below have the `Fight.AccessControl.` prefix. Message names match their canonical `toArray()` payloads;
PHP namespaces remain aggregate-owned. This is not a route registry, dispatch guarantee or authorization policy.
The executable inventory in `tests/OpenApi/PublicMessageSamples.php` is checked against current Command/Query
implementations, then each real round trip is validated against generated OpenAPI 3.1 using JSON Schema 2020-12.
A new message must receive a real sample and a component, not just increase a count.

## Message declarations

Every name in a row maps individually to its same-named component. Source directories identify the declarations;
anchors are deliberately outside Composer autoload. `openapi/bootstrap.php` loads all seven anchors explicitly.

| Declarations | Components / anchor |
| --- | --- |
| [Activation Commands](../src/Domain/AccessControl/ActivationGrant/Command): DeliverUserInvitation, ResendInvitationDelivery, RetryInvitationDelivery | Same names in `Components.php` |
| Activation Command: ExpireInvitationDelivery | Same name in `CredentialDeliveryComponents.php` |
| [Agent Commands](../src/Domain/AccessControl/Agent/Command): GrantPermissionToAgent, RevokePermissionFromAgent, ReplaceAgentPermissions | Same names in `Components.php` |
| Agent Commands: ProvisionAgent, RotateAgentCredential | Same names in `AgentDeliveryComponents.php`; void handling, never an issuance response |
| Agent Command: UpdateAgent | Same name in `AgentNameComponents.php` |
| [Email Commands](../src/Domain/AccessControl/EmailChangeGrant/Command): CancelEmailChange, DeliverEmailChange, ExpireEmailChange, RequestEmailChange | Same names in `Components.php` |
| [Feature Commands](../src/Domain/AccessControl/Feature/Command): CreateFeature, ProvisionFeatures, RemoveFeature, SetFeaturePermission, SetFeatureStatus | Same names in `FeatureComponents.php` |
| [Managed policy Command](../src/Domain/AccessControl/ManagedPolicy/Command): ReconcileManagedPolicy | Same name in `Components.php` |
| [Reset Commands](../src/Domain/AccessControl/PasswordResetGrant/Command): RequestPasswordReset, ExpirePasswordResetDelivery | Same names in `Components.php` |
| Reset Command: DeliverPasswordReset | Same name in `CredentialDeliveryComponents.php` |
| [Session Command](../src/Domain/AccessControl/RefreshSession/Command): RevokeSession | Same name in `Components.php` |
| [Role Commands](../src/Domain/AccessControl/Role/Command): CreateCustomRole, GrantPermissionToCustomRole, RevokePermissionFromCustomRole, RenameCustomRole, RemoveCustomRole | Same names in `Components.php` |
| [User Commands](../src/Domain/AccessControl/User/Command): AssignRoleToUser, RemoveRoleFromUser, CorrectPendingInvitation, DeleteUser, DisableUser, EnableUser, InvitePendingUser, RestoreUser | Same names in `Components.php` |
| User Commands: RegisterUser, RecoverPendingRegistration | Same names in `RegistrationComponents.php` |
| [Activation Query](../src/Domain/AccessControl/ActivationGrant/Query): FindInvitationDeliveryStatus | Same name in `Components.php` |
| [Agent Queries](../src/Domain/AccessControl/Agent/Query): GetAgentById, GetAgentProfile, ListAgents | Same names in `Components.php` |
| [Operation Queries](../src/Domain/AccessControl/AgentCredentialOperation/Query): GetAgentOperation, ListDueAgentDeliveries | Same names in `AgentDeliveryComponents.php` |
| Operation Queries: ListAgentDeliveryMaintenance, CountAgentDeliveryKeyReferences | Same names in `AgentMaintenanceComponents.php` |
| [Human delivery Queries](../src/Domain/AccessControl/CredentialDelivery/Query): FindCredentialDeliveryStatus, FindDueCredentialDeliveries, FindExpiredCredentialDeliveries | Same names in `CredentialDeliveryComponents.php` |
| [Feature Queries](../src/Domain/AccessControl/Feature/Query): GetFeatureById, ListFeatures, ValidateFeaturePreparation | Same names in `FeatureComponents.php` |
| [Policy Query](../src/Domain/AccessControl/ManagedPolicy/Query): PreviewManagedPolicy | Same name in `Components.php` |
| [Permission Queries](../src/Domain/AccessControl/Permission/Query): GetPermissionById, ListPermissions | Same names in `Components.php` |
| [Session Query](../src/Domain/AccessControl/RefreshSession/Query): ListActiveSessions | Same name in `Components.php` |
| [Role Queries](../src/Domain/AccessControl/Role/Query): GetRoleById, ListRoles | Same names in `Components.php` |
| [User Queries](../src/Domain/AccessControl/User/Query): GetUserById, ListUsers | Same names in `Components.php` |

## Every Query result

| Queries | Actual result and component disposition |
| --- | --- |
| GetUserById / ListUsers | `UserView` → `User`; projected `ResultSet<UserView>` → `UserCollection` |
| GetRoleById / ListRoles | `RoleView` → `Role`; projected `ResultSet<RoleView>` → `RoleCollection`; revision → `RoleRevision` |
| GetPermissionById / ListPermissions | `PermissionView` → `Permission`; projected `ResultSet<PermissionView>` → `PermissionCollection` |
| GetAgentById / ListAgents | `AgentView` → `Agent`; projected `ResultSet<AgentView>` → `AgentCollection`; members → `PrincipalPermission` |
| GetAgentProfile | `AgentProfileView` → `AgentProfile`, not the administrative `Agent` resource |
| GetFeatureById / ListFeatures | `FeatureView` → `Feature`; projected `ResultSet<FeatureView>` → `FeatureCollection`; required nullable `permission_name` and corresponding `binding_missing` remain meaningful |
| ListActiveSessions | Projected `ResultSet<SessionView>` → `SessionCollection`; member → `Session` |
| FindInvitationDeliveryStatus | `InvitationDeliveryStatusView` or `CredentialDeliveryAbsent` → `InvitationDeliveryStatusResult`; recorded origin → `ActivationOrigin` |
| FindCredentialDeliveryStatus | `CredentialDeliveryStatusView` or `CredentialDeliveryAbsent` → `CredentialDeliveryStatusResult` |
| FindDueCredentialDeliveries | `list<DueCredentialDelivery>` → `DueCredentialDeliveries`; member → `DueCredentialDelivery` |
| FindExpiredCredentialDeliveries | `list<ExpiredCredentialDelivery>` → `ExpiredCredentialDeliveries`; member → `ExpiredCredentialDelivery` |
| GetAgentOperation | `AgentOperationView` → `AgentOperation` confirmed/indeterminate union, not nullable absence or invocation warnings |
| ListDueAgentDeliveries | `list<AgentOperationView>` → `DueAgentDeliveries`, confirmed members only |
| ListAgentDeliveryMaintenance | `list<AgentOperationView>` → `AgentDeliveryMaintenance`, confirmed members only |
| CountAgentDeliveryKeyReferences | Integer → `AgentDeliveryKeyReferences`, diagnostic count, not key destruction authority |
| ValidateFeaturePreparation | `FeaturePreparationResult` and `FeaturePreparationIssue` → same-named components; `FeaturePreparationProblem` → issue `problem` enum; advisory, not activation authority |
| PreviewManagedPolicy | `ManagedPolicyPlan` → same name; `ManagedPermissionPlanItem` / `ManagedRolePlanItem` serialize through the plan into `ManagedPolicy.PermissionPlanItem` / `ManagedPolicy.RolePlanItem` |

Required single-resource handlers return a non-null safe View or deliberate Common Application `ResourceNotFound`.
Optional human status uses the explicit absence object; internal faults are neither absence nor success.
[Required-resource](required-resource-queries.md), [human status](credential-delivery.md#explicit-status-and-bounded-discovery-unreleased)
and [Agent Query](agent-query-contracts.md) guides own catches, authority and read guarantees. No generic failure
schema is supplied. Consumer transports decide how to present absence or failures.

ResultSet retains View objects: consumers must explicitly project each safe record with `toArray()` before JSON
encoding the page. The generated schema describes that projection, not arbitrary object serialization. Empty and
out-of-range pages are valid; no `page <= total_pages` rule is added. These samples do not qualify universal Common
ResultSet invariants or a producer snapshot; [TASK-00132](../planning/tasks/00132-TASK.md) retains that separate hold.
The page metadata is unchanged. Pagination request `orderings` is a string-direction object, or the empty JSON array
produced by the native empty PHP ordering map; a nonempty indexed array is invalid. Empty preparation payloads likewise
allow `{}` or canonical `[]`, never nonempty objects/arrays. These are value shapes, not HTTP request-body policy.

## Synchronous User security and other values

| Current public PHP operation/value | Component or deliberate boundary |
| --- | --- |
| `UserAuthentication::activate` / `login` | `Authentication.ActivateRequest` / `Authentication.LoginRequest`; optional Boolean `remember` maps to the native default false |
| `SessionLifecycle::refresh` / `logout` | `Authentication.RefreshRequest` / `Authentication.LogoutRequest` |
| `PasswordManagement::resetPassword` / `changePassword` | `Authentication.ResetPasswordRequest` / `Authentication.ChangePasswordRequest`; `user_id` is the consumer's trusted authenticated identity for change |
| `EmailChangeConfirmation::confirmEmail` | `Authentication.ConfirmEmailRequest` |
| `TokenSet`, `RefreshResult`, `AccessToken`, `RefreshCredential` | Intentionally non-serializable PHP security results/values. Consumer explicitly projects TokenSet getters to `Authentication.BrowserResponse` or opted-in `Authentication.TokenSet`; bounded conflict projects to `Authentication.RefreshConflict`. Never serialize the internal object wholesale |
| `AgentOperationKey`, `AgentIssuance` | Same-named safe nested components; no delivery, activation or use permission implied |
| `AgentUpdateInitiator` | Same-named provenance component, not an authorization grant |
| `AgentProvisioningRequest`, `AgentRotationRequest`, `AgentCredentialDestination`, `AgentOperationScope` | Flattened into their Command/Query/issuance components; no separate runtime request or authorization wrapper |
| `ManagedPolicy`, `ManagedPermissionDefinition`, `ManagedRoleDefinition` | Policy Command/Query payloads with `ManagedPolicy.PermissionDefinition` / `ManagedPolicy.RoleDefinition` members |
| `AgentDeliveryResult`, `AgentMaintenanceResult` | Same-named safe invocation enums; distinct from status reads and current authority |
| Existing registration and expiry Events | `UserRegistered`, `PendingRegistrationRecovered`, `InvitationDeliveryExpired`, `PasswordResetDeliveryExpired`, `EmailChangeExpired`; no generic event envelope or replay guarantee |
| `CreatedResource`, `PaginationRequest`, `JSend.Success.*` | Explicit optional consumer projections/envelopes, not additional handler return types; `JSend.Success.Empty` is a body-bearing null success, never an HTTP 204 body |
| `FeatureFlag` | Native repeatable class/method name declaration yielding FeatureName; cumulative ancestry/route metadata, no wire DTO or runtime enforcement |
| Identity/name/status values used above | UUID/name/enum fields or named scalar components (`FeatureName`, `PermissionName`, `RoleName`, `RoleRevision`, `ActivationOrigin`); not raw aggregate serialization |
| Aggregates, repositories, policy/authority snapshots, claims, receipts, cohort/restoration evidence, encrypted material, provider invocations/results and exceptions | Internal/persistence/security contracts, not public wire schemas. In particular `AgentDeliveryWorkView` is internal read-validation evidence, not a public Query result |

Raw Agent Command names are not canonical output names: UpdateAgent retains raw input; ProvisionAgent accepts bounded
UTF-8 bytes before later AgentName validation. Do not apply the canonical 120-code-point limit to those raw fields.
Provisioning's 4096-byte limit is documented rather than misrepresented as JSON Schema's character `maxLength`.
Feature names use the current strict ASCII rule. Native FeatureFlag validation does not trim/normalize or invoke
availability/authorization; future declaration/discovery redesign remains separately owned.

Request secrets are write-only and example-free. Browser output forbids additional fields, including refresh
credentials. Portable body-token output explicitly includes `refresh_token`; consumers still own cookies, headers,
security bootstrap, routes, disclosure and safe failure mapping. Schema tests are component composition, not endpoint,
generated-client, storage/crypto, scanner completeness or deployment qualification. Current breaking metadata requires
consumer recomposition/client regeneration as appropriate, with no compatibility aliases or selected release version.
