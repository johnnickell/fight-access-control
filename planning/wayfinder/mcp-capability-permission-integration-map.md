# Wayfinder Map: Permission integration for evolving Common MCP capabilities

**Label:** `wayfinder:map`
**Status:** Closed
**Planning stage:** Decisions, single EPIC, eight-TICKET decomposition and all full TASK plans accepted/written (TASK-00084–00106, 23 features); implementation selection belongs to the Board

> This map is an **index, not a store**. Material decisions live in the linked decision tickets.

## Destination

Define an implementation-ready future AccessControl release handoff for permission-aware integration with Common's
new execution Tools and evolving Resources, Skills, Prompts, Subscriptions and durable Tasks. Establish which
reusable bindings and optional framework adapters this package should own, including compatibility with Common's
safe-error approach, while preserving Common's neutral contracts and consumer-owned scope.

**Done** = the linked decisions are Closed, remaining fog is resolved or excluded, approved destinations link to
written EPIC handoffs, and external Common API/release prerequisites are explicit. No release number is selected.

## Notes

- John requested examination of Common's current MCP implementation and remaining plans on 2026-10-25, to prepare
  future AccessControl integration. This authorizes investigation/planning, not implementation or release.
- The [research note](research/fight-common-mcp-permission-integration.md) distinguishes released baseline,
  implemented unreleased execution Tools, uncommitted Prompts, and planned Subscriptions/Tasks.
- Preserve the closed [original authorization/profile map](agent-aware-mcp-authorization-map.md). Its implemented
  attribute-driven Tool contract is not reopened by discovering a new Common-owned Tool binding requirement.
- Common owns protocol mechanics and enforcement of supplied neutral decisions. AccessControl owns Agent identity
  and reusable Permission integration; consumers own project/session/resource/Task relationships and runtime policy.

## Decisions so far

1. **[WF-032 — Establish Common MCP permission seams](tickets/WF-032-common-mcp-permission-seams.md) is Closed.**
   Common has appropriate current enforcement seams and explicit future permission requirements. The probe confirms
   all seven execution Tools fail closed in AccessControl's attribute-only catalog; no bypass was demonstrated.
2. **[WF-033 — Bind Common-owned MCP surfaces to Agent Permissions](tickets/WF-033-common-owned-mcp-permission-bindings.md)
   is Closed.** John accepted explicit Tool mappings alongside attributes, reusable permission tooling with
   consumer-owned policy/relationship scope, and separate current checks for live disclosure/continuation while
   preserving short-request snapshots. This grants neither permissions nor implementation authority.
3. **[WF-035 — Decide optional framework permission adapters and safe-error composition](tickets/WF-035-framework-permission-adapter-ownership.md)
   is Closed.** John selected optional integrations inside `src/Adapter`, with Common-style capability-first runtime
   and service-container destinations while Domain/Application retain DDD structure. Safe-error compatibility remains
   required; the profile records the narrow boundary amendment, not implemented adapters or framework support.
4. **[WF-034 — Select the future MCP integration release scope](tickets/WF-034-mcp-integration-release-scope.md) is Closed.**
   John selected one combined release including Subscriptions and durable Tasks plus Slim, Yii 3, Symfony, Laravel
   and CodeIgniter 4, waiting for required Common implementation/qualification and tagged APIs. He approved the
   single **Permission-Aware MCP Integration and Framework Adapters** EPIC destination and left the release number
   unassigned. Actual dependency minima/support ranges need qualification; no implementation is authorized.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-032 | [Establish Common MCP permission seams](tickets/WF-032-common-mcp-permission-seams.md) | Research | AFK | **Closed** | — |
| WF-033 | [Bind Common-owned MCP surfaces to Agent Permissions](tickets/WF-033-common-owned-mcp-permission-bindings.md) | Grilling | HITL | **Closed** | WF-032 |
| WF-035 | [Decide optional framework permission adapters and safe-error composition](tickets/WF-035-framework-permission-adapter-ownership.md) | Grilling | HITL | **Closed** | WF-033 |
| WF-034 | [Select the future MCP integration release scope](tickets/WF-034-mcp-integration-release-scope.md) | Grilling | HITL | **Closed** | WF-033, WF-035 |

## Blocking relationships

```text
WF-032: factual source investigation (Closed)
  → WF-033: reusable bindings, scope ownership and freshness (Closed)
  → WF-035: same-package capability-first adapters and safe-error composition (Closed)
  → WF-034: combined release, all five frameworks and single EPIC approval (Closed)
  → EPIC-00014 (written) → TICKET-00025–00032 (accepted/written)
  → TICKET-00025 → TASK-00084 (accepted/written; needs-info for external Common evidence)
  → TICKET-00026 → TASK-00085 (Resources) → TASK-00086 (complete Skills/member reads)
  → TICKET-00027 → TASK-00087 (accepted/written; needs-info for Common Prompt contracts/minimum)
  → TICKET-00028 → TASK-00088 (trusted current authority; ready, no local blocker)
  → TICKET-00029 → TASK-00089 / TASK-00090 (each consumes TASK-00084; needs-info for Common scoped reads)
  → TICKET-00030 → TASK-00091–00096 (accepted/written; needs-info for Common notice/live/publication contracts)
  → TICKET-00031 → TASK-00097–00102 (accepted/written; needs-info for Common durable Task contracts)
  → TICKET-00032 → TASK-00103 (Slim/Yii 3) → TASK-00104 / TASK-00105 / TASK-00106 (native peers)
  → All planning phases complete → Board-based selection via /skill:next (no automatic execution)
```

## Frontier

None. WF-032, WF-033, WF-035 and WF-034 are Closed. The single approved EPIC is now written as
[EPIC-00014](../epics/00014-EPIC.md); this map is Closed for completed decisions/EPIC handoff, not implementation
or complete TASK decomposition. Continue its TASK-planning handoff below without reopening settled choices.

## Planning handoff

[WF-034](tickets/WF-034-mcp-integration-release-scope.md#approved-epic-destination-and-unassigned-release-number)
owns the approved destination:

| Approved destination | Record | State |
|---|---|---|
| Permission-Aware MCP Integration and Framework Adapters | [EPIC-00014](../epics/00014-EPIC.md) | All planning phases accepted/written: eight TICKETs and 23 TASKs (TASK-00084–00106); consult Board, not automatic implementation |

[EPIC-00014's current handoff](../epics/00014-EPIC.md#progress-and-planning-handoff) now owns the complete combined
release, all five framework families, external qualified/tagged Common prerequisites and unassigned version.
WF-033/WF-035 retain accepted permission/scope/freshness, capability-first Adapter and safe-error guarantees.
Runtime integrations remain opt-in; Task polling is independent of live delivery.

John approved the full eight-TICKET split, now written as [TICKET-00025](../tickets/00025-TICKET.md) through
[TICKET-00032](../tickets/00032-TICKET.md). The EPIC's current handoff owns the complete requirement/sequence table;
all map EPIC and TICKET phases are complete. Each requirement owns its evidence and no separate testing TICKET exists.

John explicitly selected TICKET-00025 for To Tasks and approved its complete single-TASK plan, now written as
[TASK-00084](../tasks/00084-TASK.md). It is `needs-info` for actual qualified/tagged Common execution contracts and
minimum-version evidence, not blocked by an unfinished local TASK. No implementation has been created.

John subsequently approved TICKET-00026's two-TASK decomposition, written as [TASK-00085](../tasks/00085-TASK.md)
for Resource discovery/reads and [TASK-00086](../tasks/00086-TASK.md) for complete Skills/member reads. TASK-00085
has no local blocker; TASK-00086 consumes its Resource binding, not TASK-00084. Their Common hooks already appear
in inspected tags; supported-minimum/behavior qualification remains with implementation.

John then approved TICKET-00027's complete single-TASK plan, written as [TASK-00087](../tasks/00087-TASK.md),
needs-info for qualified/tagged Common Prompt contracts and minimum evidence, not a local unfinished TASK. Prompt
serving remains independent of Tool, Resource/Skill and live integrations; actual linked targets have their own checks.

John then approved TICKET-00028's single-TASK plan, written as [TASK-00088](../tasks/00088-TASK.md), ready-for-agent
with no local TASK blocker or new Common runtime API prerequisite. It supplies trusted binding/current checks and
synchronized protected admission; actual consumer lifetime/scope/fence qualification remains separate.

John then approved TICKET-00029's full plan, written as [TASK-00089](../tasks/00089-TASK.md) for scoped timeline
reads and [TASK-00090](../tasks/00090-TASK.md) for independent protected metadata/body reads. Both consume TASK-00084
and are needs-info for qualified/tagged Common scoped-read contracts/minimum evidence, with no dependency between
them or on TASK-00088 for ordinary request reads. Actual consumer scope/persistence/race qualification stays separate.

John then approved TICKET-00030's six complete journeys, written as [TASK-00091](../tasks/00091-TASK.md) recording/
removal, [TASK-00092](../tasks/00092-TASK.md) retained inspection/recovery, [TASK-00093](../tasks/00093-TASK.md) live
Tool/Resource/Skill disclosure, [TASK-00094](../tasks/00094-TASK.md) Prompt signals,
[TASK-00095](../tasks/00095-TASK.md) subscriber inspection and [TASK-00096](../tasks/00096-TASK.md) optional publication.
All retain needs-info qualified/tagged Common/minimum holds and approved local supplier edges; the owning TICKET
records the matrix. Prompt absence does not block ordinary live families, publication is independent of live/Prompt/
inspection, and ordinary polling needs no live runtime. Each owns tests/docs/gates/independent acceptance.

John then approved TICKET-00031's six complete features, written as [TASK-00097](../tasks/00097-TASK.md) creation/
producer/protected reads, [TASK-00098](../tasks/00098-TASK.md) removal, [TASK-00099](../tasks/00099-TASK.md) input/
continuation, [TASK-00100](../tasks/00100-TASK.md) cancellation/intent,
[TASK-00101](../tasks/00101-TASK.md) complete MCP start/reconnect and
[TASK-00102](../tasks/00102-TASK.md) optional full-state notifications. All retain qualified/tagged Common/minimum
holds and approved supplier edges; its owning TICKET records the matrix. Ordinary polling/input/cancel need no live
runtime; removal does not block complete activation, and full-state live has no extra Prompt/inspection/Mercure blocker.

John then approved TICKET-00032's full plan, written as [TASK-00103](../tasks/00103-TASK.md) shared PSR-15 with
separate Slim/Yii 3 qualification, [TASK-00104](../tasks/00104-TASK.md) Symfony,
[TASK-00105](../tasks/00105-TASK.md) Laravel and [TASK-00106](../tasks/00106-TASK.md) CodeIgniter 4. All ready-for-agent;
TASK-00103 has no local blocker, native peers consume only its inward contracts/initial Adapter wiring and share
order 104 without relative framework priority. No inherited MCP/TASK-00088 hold; actual sufficient tagged minima/
native qualification remain acceptance work. Each feature owns tests/docs/gates/independent acceptance.

All map decisions, EPIC/TICKET phases and all eight full TASK plans are accepted/written (23 features). The map
stays Closed, not implemented/done. Consult the Board for execution selection; preserve actual dependencies,
external Common gates, combined release/all five frameworks, optional paths, polling independence and unassigned
version. Planning approval authorizes neither implementation nor publication/release.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.

## Not yet specified (fog)

No remaining uncertainty requires a Wayfinder decision. The following are delegated design or external evidence,
not grounds to reopen accepted scope:

- Exact public PHP APIs, Permission names/grant tiers and one-time consumer seeding: requirement/design work within
  the accepted ownership and use cases; no blanket grants or default Agent access are inferred.
- Actual implemented Common contracts, first tagged dependency minima and supported framework ranges: verify before
  affected implementation/adoption and release; do not invent future version numbers.
- Actual consumer authentication/lifetimes, storage, scope and live-client qualification: separate consumer evidence.

## Out of scope

- Source/test/dependency changes, modifying Common or Agent OS records, implementing an endpoint/adapter or a
  project/tenant model, workflow authority, permission grants, Composer adoption, consumer migration, publication,
  release or deployment.
- Silently shipping a reduced integration release that omits selected Subscriptions/Tasks or one of the five
  framework families; changing the combined-release choice requires a human amendment. Unrelated Common features
  remain out of scope.
- Treating protocol discovery, OAuth scopes, SUPER_ADMIN labels, IDs, cursors or immutable content as Agent authority.
