# Roadmap

## In progress

| Epic | Target | Status | Outcome |
| --- | --- | --- | --- |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | in-progress | T-00027 through T-00030 complete: direct signed-Agent authority is fenced and cached through the final `SecurityContext`; Agent Permission and User Role retries are idempotent. T-00031 is the Ready Frontier before `1.0.0`. |

## Route to 0.x

1. Deliver T-00031 to align custom-Role Permission modification behavior with completed Agent Permission and User
   Role retry semantics.
2. Run a separate stability review before authorizing a `1.0.0` release.
3. Keep implementation, commit, push, pull request, merge, release, and publication as separate approvals.

## Completed

| Epic | Target | Outcome |
| --- | --- | --- |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | Delivered the shared identity, credential, session, authorization, and account-lifecycle package slices; a separate stability decision remains required before release. |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | Delivered Agent HMAC authentication, direct Permission authority, request-scoped Agent resolution, and unified distinct User/Agent current-authority access with exact coverage. |

## Released

No version has been tagged or released.
