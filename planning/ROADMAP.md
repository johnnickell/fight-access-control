# Roadmap

## In progress

| Epic | Target | Status | Outcome |
| --- | --- | --- | --- |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | ready-for-agent | Simplify the public authenticated-authority boundary and make authorization modifications consistently idempotent before `1.0.0`. |

## Route to 0.x

1. Deliver T-00026 through T-00028 for the unified authenticated-authority API and its framework-neutral
   conformance behavior.
2. Deliver T-00029 through T-00031 to align Agent Permission, User Role, and custom-Role Permission modification
   behavior.
3. Run a separate stability review before authorizing a `1.0.0` release.
4. Keep implementation, commit, push, pull request, merge, release, and publication as separate approvals.

## Completed

| Epic | Target | Outcome |
| --- | --- | --- |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | Delivered the shared identity, credential, session, authorization, and account-lifecycle package slices; a separate stability decision remains required before release. |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | Delivered Agent HMAC authentication, direct Permission authority, request-scoped Agent resolution, and unified distinct User/Agent current-authority access with exact coverage. |

## Released

No version has been tagged or released.
