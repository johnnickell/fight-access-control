---
id: TICKET-00026
epic: EPIC-00014
title: Authorize Resource and whole-Skill discovery and reads
status: done
---

# Authorize Resource and whole-Skill discovery and reads

## Problem and Outcome

Protecting `tools/call` does not protect Resource catalogs, exact content reads or Skill manifests/supporting files.
Consumers need reusable Agent Permission checks for those neutral Common boundaries while retaining their own
content/relationship policy. A saved URI, prior page or whole-Skill listing must not become continuing authority.

Supply Permission bindings for Resources and whole Skills, independently composing the applicable checks on list,
direct get and member reads. Common continues to own exact identities, providers, pagination, manifests, bounds
and byte serving. This is item 2 of [EPIC-00014](../epics/00014-EPIC.md), under WF-033's accepted tooling boundary.

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Integrator associates protected Resource/Skill identities with requirements | N/A; composition | Validate association with the actual Common identity/provider contract; no implicit public/unmapped grant. |
| Agent lists Resources or Skills | No new business message | Filter permitted metadata before disclosure/pagination; hidden entries/counts do not become policy evidence. |
| Agent directly reads an exact Resource without listing | Common owns selected provider lookup/open, not a new AccessControl Query | Apply current applicable checks before content opening; prior listing is not required or authority. |
| Agent gets a whole Skill or reads a supporting member | No new business message | Preserve whole-Skill and general Resource checks; no redacted partial Skill masquerading as a complete entry. |
| Agent follows a Tool/Prompt link or saved/historical URI later | N/A until an admitted selected read | Reauthorize independently in the new request; no authority from the link, cursor, cache or earlier manifest. |

Checks create no grants, mutate no content and emit no new Domain events. Selected providers retain their own
business Query authorization if needed; static metadata/content does not require invented Commands/Queries.

## Requirements, Validation and Permissions

- Bind validated nonempty conjunctive direct Agent Permission requirements to the relevant neutral Common ports.
  Define stable identity association for actual Resource and Skill contracts; do not copy Tool object-identity
  assumptions onto providers that legitimately emit fresh metadata objects.
- Unknown/unmapped or invalid requirements fail closed; reject invalid static composition where observable and
  do not treat runtime absence as public access. Consumers explicitly choose content visibility and scope.
- Whole-Skill admission and general member Resource admission remain distinct. Common's complete-entry and
  all-applicable-member requirements must remain intact; individual member reads repeat applicable protection.
- Resource read is exact registered-identity lookup, not arbitrary URI-to-path/network resolution. No normalizing
  escapes, traversal fallback, content prefetch or ownership conflict bypass in the Permission binding.
- Resolve one trusted ordinary request principal consistently; later requests/pages/reads observe changed authority.
  Keep authorization-sensitive caching private/zero-TTL and isolate caller contexts; freshness or immutable bytes
  never grants access. Reuse authentication within the request rather than replaying its nonce per descriptor.
- Protect names, links, manifests, counts and continuation context as well as bodies. Policy/provider faults use
  the actual Common safe boundary, not raw exception messages or a misleading successful empty/partial result.
- Tool/Prompt availability, OAuth scopes and human role labels grant neither Resource access nor Skill activation.
  Content remains untrusted data; serving instructions/scripts does not execute or authorize them.

## Dependencies and External Gates

- Reuse existing request authentication; [TICKET-00025](00025-TICKET.md) is not a dependency of Resource/Skill access.
- [TICKET-00027](00027-TICKET.md) consumes these checks only for actual later linked Resource reads; Prompt retrieval
  itself remains independently usable. [TICKET-00030](00030-TICKET.md) composes these decisions for relevant live signals.
- Verify actual accepted/tagged Common Resource/Skill ports, composition, safe errors and minimum version before
  affected adoption. Retain the EPIC's combined release, optional runtime composition and current-contract policy.
- Consumer storage containment, trusted context, relationship scope and real-client qualification remain external
  responsibilities. No application relationship model or production provider/storage adapter is added here.

## Acceptance Evidence

- [ ] Actual AccessControl principal/Permission resolution composes with real Common Resource and whole-Skill hooks;
  missing/foreign/unmapped/invalid association and all missing-Permission cases fail closed.
- [ ] Resource list/direct read and Skill list/get/member reads apply their independent checks, including direct
  access without listing, links from other families and historical/saved identities after revocation/rotation.
- [ ] Denied reads never open content; hidden metadata/counts/manifests are not disclosed through pages, cursors,
  errors or policy faults. Complete Skill results retain actual Common integrity and member rules.
- [ ] Permitted reads preserve Common bytes, exact identity, finite output and private cache behavior; no execution,
  extra grant, arbitrary storage lookup or duplicate business use case is introduced.
- [ ] Minimum-supported dependency composition, request lifetime, safe failures, tests/documentation, exact coverage,
  canonical package gate and later independent Review/behavioral QA satisfy the project profile.

## Exclusions

Content acquisition/storage, filesystem/network adapters, Skill activation/execution, template resolution,
application scope policy, Tool/Prompt admission, automatic grants, consumer runtime changes or deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 85 | [TASK-00085](../tasks/00085-TASK.md) | Authorize exact Resource discovery and reads | done |
| 86 | [TASK-00086](../tasks/00086-TASK.md) | Authorize complete Skills and protected member reads | done |
## Decisions and Progress

John approved item 2 of the complete eight-TICKET split, then explicitly invoked To Tasks for this TICKET and
approved two complete capability slices. Its full accepted TASK decomposition is now written:

- [TASK-00085 — Authorize exact Resource discovery and reads](../tasks/00085-TASK.md), ready-for-agent with no local
  blocker; independent of TASK-00084 and live Subscriptions.
- [TASK-00086 — Authorize complete Skills and protected member reads](../tasks/00086-TASK.md), ready-for-agent but
  waiting on TASK-00085's Resource Permission binding. Whole-Skill admission and selected member Resource checks
  remain distinct; no partial successful manifest or invented all-other-members requirement for a selected file.

Both own their tests/docs, actual Common composition, supported-minimum qualification, full gate and independent
Review/QA. Relevant hooks appear in inspected Common tags; exact minimum/behavior qualification remains an
implementation obligation, not TASK-00084's execution-API hold. No separate layer or testing-only TASK is planned.

This TICKET's TASK planning is complete, not its implementation. TICKET-00027's subsequently approved TASK-00087
plan is now written, as is TICKET-00028's approved TASK-00088 plan; TICKET-00029's approved TASK-00089/00090 plan is also written; TICKET-00030's approved TASK-00091–00096 plan is now written; TICKET-00031's TASK-00097–00102 and TICKET-00032's approved TASK-00103–00106 plans are written.
All EPIC-00014 map planning phases/full TASK plans are accepted/complete; consult the Board for execution selection.
No code, dependency/config change, grants, publication, release, adoption or framework support is authorized or
claimed by planning approval. Exact APIs/association mechanics remain implementation design within the accepted
requirements. Keep the map Closed and continue planning before Board-based implementation selection.

### Current implementation checkpoint

TASK-00085 implements the Resource slice on `feature/task-00085-resource-authorization`; the
[Resource guide](../../docs/agent-resource-authorization.md) records the public composition. Actual list/read and
principal-provider tests qualify tagged Common v1.3.0 as the existing sufficient minimum. Canonical Review ACCEPT and
nonvisual QA PASS accept exact candidate `e2b7594b0005867880601f8d244cca3671a3d746`; TASK-00085 is done for required
package verification; [PR #139](https://github.com/johnnickell/fight-access-control/pull/139) is open against `develop`.
TASK-00086 remains unfinished, so this TICKET stays open. Earlier
planning-only statements above describe their original authorization checkpoint.

### Current integrated delivery

Qualified TASK-00088 intake verified TASK-00086 PR #141 merged into `develop` at
`7fc6c313c39df96f7e261144025433d2b5c46e31` on 2026-10-27 and integrated in the selected base. The ended marker
is retired; no TASK-00086 review/merge action remains. Historical checkpoints below retain their original evidence.
No combined release or consumer qualification follows from this integration.

### Accepted Resource and Skill checkpoint

Both child TASKs are done for independently accepted package implementation and required QA. TASK-00086
candidate `c1cf1a412209254087bd93eda497679725495420` has Review ACCEPT and nonvisual QA PASS. Its publication
and final delivery were pending at closeout; `.runs/handoffs/TASK-00086/land.md` owns the current result.
This completion does not establish merge, release or consumer qualification.

### Historical Skill implementation checkpoint

John invoked Build TASK-00086 after TASK-00085 integrated in `develop`. Work now implements validated whole-Skill
requirements and complete list/get/independent member reads through actual Common composition; the
[Skill guide](../../docs/agent-skill-authorization.md) owns the public contract. TASK-00086 remains in progress:
its committed Work evidence must precede independent technical Review and behavioral QA. This parent stays open;
no combined release, consumer adoption or framework implementation is claimed. Earlier planning-only/waiting
statements and Resource publication prose describe historical checkpoints.

Next: independent `/skill:review TASK-00086` after the verified committed Work handoff.
