# Roadmap

## In progress

| Epic | Target | Current outcome |
| --- | --- | --- |
No epics are currently in progress.

## Route to 1.0.0

1. Publish completed framework-neutral capabilities as reviewed pre-`1.0.0` package releases, beginning with
   `v0.1.0`, without making full starter implementation a package-release prerequisite.
2. Deliver [T-00033](tickets/00033-TICKET.md) against released Fight Common `v1.2.0` to prepare the framework-neutral
   `v0.2.0` reusable schema-component release without making a package-owned OpenAPI document or starter
   implementation a prerequisite.
3. Implement the full Symfony, Laravel, Yii,
   CodeIgniter, and Slim starter skeletons against tagged package versions.
4. Feed shared compatibility findings into reviewed subsequent `0.x` releases while framework-specific fixes
   remain in their owning starter repositories.
5. Run a separate stability review before authorizing a `1.0.0` release.
6. Keep implementation, commit, push, pull request, merge, release, and publication as separate approvals.

## Completed

| Epic | Target | Outcome |
| --- | --- | --- |
| [EPIC-00004](epics/00004-EPIC.md) | `v0.2.0` | Delivered the local consumer-composable OpenAPI schema review candidate; tag, release, and publication remain separate effects. |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | Delivered the final unified User/Agent `SecurityContext` and retry-safe Agent Permission, User Role, and custom-Role Permission changes. |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | Delivered the shared identity, credential, session, authorization, and account-lifecycle package slices; a separate stability decision remains required before release. |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | Delivered Agent HMAC authentication, direct Permission authority, request-scoped Agent resolution, and unified distinct User/Agent current-authority access with exact coverage. |

## Released

| Version | Date | Outcome |
| --- | --- | --- |
| `v0.1.0` | 2026-09-10 | First public package milestone: framework-neutral User and Agent identity, authentication, session, Role, Permission, managed-policy, and current-authority behavior with exact coverage. |
