---
id: T-00017
title: Complete administrative identity and authorization reads
status: done
parent: PRD-00001
blocked_by: []
branch: feature/t-00017-complete-administrative-reads
---

# Complete administrative identity and authorization reads

## Outcome

Administrative clients can page through Users, Roles, and Permissions and retrieve any one of them by stable
identity through package-native Queries that return immutable safe views rather than aggregates.

## Acceptance criteria

- [x] `GetUserById` complements the existing `ListUsers` query and returns a nullable immutable `UserView` without
  credential, authentication-authority, or persistence state.
- [x] `ListRoles` and `GetRoleById` return typed pages or one nullable immutable `RoleView` containing the stable Role
  identity, name, managed/custom classification, and Permission identities required by an administrative client.
- [x] `ListPermissions` and `GetPermissionById` return typed pages or one nullable immutable `PermissionView`
  containing the stable Permission identity, name, tier, and managed/custom classification.
- [x] Every new Query is an immutable, canonically serializable Domain message with named accessors, round-trip
  coverage, and rejection of missing required data.
- [x] Query handlers depend only on the matching Domain repositories, perform no mutation, Unit of Work commit, or
  event dispatch, and never return aggregate instances.
- [x] Tests cover paginated mapping, stable-identity lookup, absent records, safe view contents, serialization, and
  exact executable statement coverage.

## Exclusions

No command or authorization-policy change, aggregate mutation, ManagedPolicy behavior, persistence adapter, schema,
migration, HTTP or OpenAPI contract, generated client, React application, or other UI work.

## Verification

- Focused User, Role, and Permission Query and QueryHandler tests
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- Implemented on `feature/t-00017-complete-administrative-reads` through six approved red-green slices covering
  Arrayable Query outputs, User lookup, Role pagination and lookup, and Permission pagination and lookup.
- `GetUserById`, `ListRoles`, `GetRoleById`, `ListPermissions`, and `GetPermissionById` are immutable canonical
  Domain Queries with repository-only Application handlers returning safe immutable views rather than aggregates.
- `UserView`, `RoleView`, `PermissionView`, `SessionView`, `InvitationDeliveryStatusView`, and `ManagedPolicyPlan`
  implement Fight Common `Arrayable` for the announced Fight Common 1.2 JSend ResultSet requirement.
- Focused tests prove pagination metadata, exact view arrays, managed/custom classification, stable lookup, absent
  records, message round trips, and rejection of every missing required field.
- `./bin/planning-check` passed with 19 records and 4 active before closure.
- `./bin/build` passed with 507 tests, 3,583 assertions, and exact 3,739/3,739 statement coverage before closure.
- Final independent Standards and Spec reviews reported no findings after two targeted refinements.
