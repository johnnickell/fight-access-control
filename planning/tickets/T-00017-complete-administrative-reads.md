---
id: T-00017
title: Complete administrative identity and authorization reads
status: ready-for-agent
parent: PRD-00001
blocked_by: []
branch: feature/t-00017-complete-administrative-reads
---

# Complete administrative identity and authorization reads

## Outcome

Administrative clients can page through Users, Roles, and Permissions and retrieve any one of them by stable
identity through package-native Queries that return immutable safe views rather than aggregates.

## Acceptance criteria

- [ ] `GetUserById` complements the existing `ListUsers` query and returns a nullable immutable `UserView` without
  credential, authentication-authority, or persistence state.
- [ ] `ListRoles` and `GetRoleById` return typed pages or one nullable immutable `RoleView` containing the stable Role
  identity, name, managed/custom classification, and Permission identities required by an administrative client.
- [ ] `ListPermissions` and `GetPermissionById` return typed pages or one nullable immutable `PermissionView`
  containing the stable Permission identity, name, tier, and managed/custom classification.
- [ ] Every new Query is an immutable, canonically serializable Domain message with named accessors, round-trip
  coverage, and rejection of missing required data.
- [ ] Query handlers depend only on the matching Domain repositories, perform no mutation, Unit of Work commit, or
  event dispatch, and never return aggregate instances.
- [ ] Tests cover paginated mapping, stable-identity lookup, absent records, safe view contents, serialization, and
  exact executable statement coverage.

## Exclusions

No command or authorization-policy change, aggregate mutation, ManagedPolicy behavior, persistence adapter, schema,
migration, HTTP or OpenAPI contract, generated client, React application, or other UI work.

## Verification

- Focused User, Role, and Permission Query and QueryHandler tests
- `./bin/planning-check`
- `./bin/build`
