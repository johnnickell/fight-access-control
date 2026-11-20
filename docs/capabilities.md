# Capabilities

Choose the capability that owns the behavior you want. The detailed composition guides linked here open in the repository; for an installed release, use those guides at its tag.

## Users and sessions

Start with [Users and sessions](users.md) for sign-in, refresh, logout, passwords and account onboarding. Use [credential expiry](https://github.com/johnnickell/fight-access-control/blob/develop/docs/credential-expiry.md) when scheduling cleanup of expired delivery work.

## Roles and Permissions

Users get Permissions through Roles. Agents receive direct Permissions and have no Roles. Configure the policy that admits the real caller and target at every entry point; do not accept a caller-supplied Role or Permission snapshot. See [Permission tiers and managed policy](https://github.com/johnnickell/fight-access-control/blob/develop/docs/permission-tiers.md) and [HTTP admission](frameworks.md).

## Agents and MCP

Use [Agent integration](https://github.com/johnnickell/fight-access-control/blob/develop/docs/agent-integration.md) for machine credentials, provisioning, rotation and recoverable delivery. A provisioning Command does not return credentials; read the outcome through an authorized Query and deliver through the protected workflow. Issuance is not delivery, activation or permission to use the credential.

For Model Context Protocol (MCP), start with the [profile Tool catalog](https://github.com/johnnickell/fight-access-control/blob/develop/docs/agent-profile-tools.md) and [Tool authorization guide](https://github.com/johnnickell/fight-access-control/blob/develop/docs/agent-mcp-authorization.md). The package provides authorization and presentation building blocks, not a hosted MCP server.

## Feature flags

Declare [Feature references](https://github.com/johnnickell/fight-access-control/blob/develop/docs/feature-references.md), [provision definitions](https://github.com/johnnickell/fight-access-control/blob/develop/docs/feature-provisioning.md), then check [preparation](https://github.com/johnnickell/fight-access-control/blob/develop/docs/feature-preparation.md) and wire [availability](https://github.com/johnnickell/fight-access-control/blob/develop/docs/feature-availability.md). Feature availability is not authorization for the underlying action.

## OpenAPI components

Use the [OpenAPI composition guide](https://github.com/johnnickell/fight-access-control/blob/develop/docs/openapi-composition.md) to opt into reusable schemas. Your application supplies the document, endpoints, status codes and security schemes.
