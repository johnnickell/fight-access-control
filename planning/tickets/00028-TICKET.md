---
id: T-00028
prd: PRD-00003
title: Publish the final SecurityContext boundary
status: ready-for-agent
blocked_by: T-00027
---

# Publish the final SecurityContext boundary

## Outcome

A consuming project can inject one final `SecurityContext` containing exactly one authenticated User or Agent and
use it as the package's stable pre-`1.0.0` interface for principal type, Role presence, and Permission presence.

## Scope

- In scope: final SecurityContext naming and construction, exact authority delegation, the complete signed-Agent
  request integration seam, public package-contract verification, obsolete pre-release API removal, and consumer
  documentation.
- Out of scope: authentication-path selection, endpoint policy, framework-owned identity wrappers, Symfony voters
  or attributes, adapters in any supported framework, Agent Roles, direct User Permissions, and release tagging.

## Acceptance Criteria

- [ ] The final `SecurityContext` constructor requires exactly one `AuthenticatedAuthority` through its type
  signature, so empty and multiple-authority runtime branches no longer exist.
- [ ] The context returns the same immutable authority and delegates Permission and Role checks without
  authenticating requests, inspecting transport state, selecting an identity path, or deciding endpoint policy.
- [ ] A framework adapter can distinguish User from Agent through the package-owned principal type, preserve actual
  User Roles, and observe that Agent authority has no package Roles.
- [ ] The primary behavioral seam begins with a consumer-supplied signed Agent request, resolves the complete cached
  principal, places it in `SecurityContext`, and proves type, Permission, Role, nonce, caching, denial, and diagnostic
  outcomes without a framework or third-party security library.
- [ ] Supported public constructors and methods for `SecurityContext`, both concrete principals, the authenticated-
  authority contract, principal-type enum, and shared Permission snapshot are production-autoloadable.
- [ ] Obsolete current-context, intermediate Agent-result, and duplicate Permission-snapshot types are absent from
  the supported API without compatibility aliases, while package-owned coordinators remain marked `@internal`.
- [ ] Documentation describes consumer-owned framework composition without prescribing wrappers, guards, voters,
  middleware, routes, response formats, or an Agent `ROLE_USER` default.
- [ ] Public-boundary, behavioral, architecture, and package-install tests pass with exact executable coverage.

## Verification

- Focused SecurityContext and public-contract tests
- Complete signed-request-to-SecurityContext behavioral conformance test
- Architecture and production-install checks
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Record the verified outcome only when terminal.
