---
id: TICKET-00027
epic: EPIC-00014
title: Authorize Prompt discovery and retrieval
status: ready-for-agent
---

# Authorize Prompt discovery and retrieval

## Problem and Outcome

An Agent permitted to use a Tool or read a Resource is not automatically permitted to discover or retrieve every
Prompt. Consumers need reusable direct-Permission checks through Common's neutral Prompt availability boundary,
without inventing a template engine or allowing Prompt links to confer authority over their targets.

Protect both `prompts/list` and independent `prompts/get` using trusted request authority and validated requirement
association. Preserve Common's lazy selected provider, argument/output limits, safe failures and separately
protected application data. This is item 3 of [EPIC-00014](../epics/00014-EPIC.md).

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Integrator composes protected Prompt definitions | N/A; composition | Associate actual Common identities with validated conjunctive requirements; missing/unmapped is never public by default. |
| Agent lists Prompts | No new business message | Current permissions filter visible metadata before ordering/cursor pagination; listing constructs no Prompt bodies. |
| Agent directly gets a Prompt without prior listing | No new AccessControl business message | Admit before declared argument validation/selected provider processing at Common's supported boundary. |
| Application-backed Prompt needs protected business data | Existing consumer/shared Query, owned by its handler | Preserve independent current business admission; Prompt permission alone grants no application scope. |
| Agent later follows a returned Resource/Skill link | Later Resource operation, not an automatic fetch | Independent target checks apply; no content opening, activation or execution merely from Prompt retrieval. |

Static Prompt content needs no new Query; permission checking adds no Command, Domain event, grant, mutation or
business transaction. Consumer provider code remains trusted application code, not a sandbox implemented here.

## Requirements, Validation and Permissions

- Implement reusable Agent Permission composition through the actual accepted Common Prompt hook, using stable
  validated definition association and nonempty conjunctive requirements. Define/reject missing, malformed,
  conflicting and unknown mappings without inventing a competing Prompt registry or naming algorithm.
- Use one trusted ordinary-request principal; reauthorize each later list page/get with current authority.
  A saved definition, catalog cursor, earlier successful get or known Prompt name grants no access.
- Common owns outer and declared string-argument validation, lazy provider selection, message/link representation,
  finite definition/input/output/result/envelope budgets and safe unknown/denied/internal errors. Do not reflect
  protected declarations, supplied arguments or private policy/provider exception text.
- Preserve private zero-TTL results and visible-catalog continuation semantics. No shared caller-independent cache
  is inferred from static instructions, immutable metadata or a cursor signature.
- Prompt permission and Query/Resource/whole-Skill permissions remain independent. A body must not embed otherwise
  forbidden content merely because it contains instructions; Resource-link metadata itself must be recipient-safe.
- Retrieving instructions grants no Workflow authority, human role, independent acceptance, Skill activation,
  Tool/model execution or permission to change an application record. Human/Agent authority and OAuth remain distinct.

## Dependencies and External Gates

- Reuse existing request authentication; Tool bindings in [TICKET-00025](00025-TICKET.md) are not prerequisites.
- [TICKET-00026](00026-TICKET.md) owns actual linked Resource/Skill admission. A static Prompt can be listed/retrieved
  independently, and returning a link does not force a fetch or grant its access.
- [TICKET-00030](00030-TICKET.md) consumes this family's checks for optional Prompt catalog-change disclosure,
  without blocking ordinary Prompt list/get on live Subscriptions.
- Reinspect actual Common Prompt implementation, independent acceptance/qualification, tagged public API and first
  sufficient dependency minimum before adoption. Earlier uncommitted research source is not a released contract.
- Keep EPIC-00014's combined release/current-contract rules; exact public bindings, Permission vocabulary and TASK
  boundaries remain downstream design. No consumer provider, database or runtime is implemented by this TICKET.

## Acceptance Evidence

- [ ] Real Common Prompt composition and actual AccessControl authority checks reject missing/unknown/invalid
  requirement associations and every missing-Permission combination without provider processing.
- [ ] Current list/get checks are independent of prior listing, cursors and earlier success; changed permissions,
  revoked Agent or changed credentials affect later requests. Listing never builds a body.
- [ ] Denied and unknown names retain Common's safe outcomes; invalid arguments/provider/policy failures do not
  expose declarations, caller data or exception text or become misleading successful empty output.
- [ ] Permitted static and Query-backed provider journeys preserve independent Query access and later Resource
  denial, exact complete output, finite budgets and private cache semantics; no automatic execution or fetch.
- [ ] Minimum-version composition, tests/documentation, exact coverage, canonical gate and later independent
  technical Review/behavioral QA qualify the package boundary, not actual deployed providers/clients.

## Exclusions

Template engines, Prompt storage, interpolation policy, automatic linked reads or execution, Tool/Resource grants,
application relationship policy, live notification mechanics, consumer changes and deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 87 | [TASK-00087](../tasks/00087-TASK.md) | Authorize Agent-protected Prompt discovery and retrieval | needs-info |
## Decisions and Progress

John approved item 3 of the complete eight-TICKET split, explicitly invoked To Tasks for this TICKET, then approved
one complete feature TASK: [TASK-00087 — Authorize Agent-protected Prompt discovery and retrieval](../tasks/00087-TASK.md).
Its full accepted decomposition is now written, including definition association, real list/get, request freshness,
static/Query-backed providers, independently protected links, safe bounded errors/output, tests/docs/minimum-version
qualification and independent Review/QA. No separate prefactor or verification-only TASK is planned.

TASK-00087 is `needs-info` for implemented/independently accepted/qualified, tagged Common Prompt APIs and supported
minimum evidence, not blocked by a local unfinished TASK. Prompt serving does not depend on TASK-00084, Resource/
Skill TASK-00085/00086 or live Subscriptions/durable Tasks. Linked targets retain independent later admission.

This TICKET's TASK planning is complete. TICKET-00028's approved TASK-00088 plan is now written;
TICKET-00029's approved TASK-00089/00090 plan is now written; TICKET-00030's approved TASK-00091–00096 plan is now written; TICKET-00031's TASK-00097–00102 and TICKET-00032's approved TASK-00103–00106 plans are written.
All EPIC-00014 map planning phases/full TASK plans are accepted/complete; consult the Board for execution selection. No source/dependency/config
change, grant, implementation, release, publication or consumer adoption is authorized or established by this plan.
Keep the map Closed and continue remaining planning before Board-based implementation selection.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.
