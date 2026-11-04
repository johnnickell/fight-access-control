# ADR 0001: Domain and Application Package Boundary

- Status: accepted
- Date: 2026-08-16

## Current applicability

The original Decision/Consequences below are historical. WF-035 first superseded the no-production-Adapter
restriction for optional reusable Permission/framework integration; TASK-00103/00104 now supply PSR-15/Symfony
source. EPIC-00015 further approves aggregate-oriented MCP presentation alongside capability/framework mechanisms.
The current normative [Architecture rules](../../docs/engineering/standards/Architecture.md#accesscontrol-placement-and-dependency-rules)
and [profile](../agents/project-profile.md#package-boundary-and-contracts) replace conflicting layout/dependency prose:
`Adapter → Application → Domain`, aggregate-first Domain/mirrored Application, and neutral
`Adapter/Mcp/{Tool,Resource,Skill}/Agent` presentation. Application may use PSR and supported upstream Application/
Domain contracts; Domain pure PHP and supported upstream Domain contracts. Neither imports Common Adapter/Standards.

This is a scoped approved amendment, not authorization for persistence or arbitrary infrastructure here.
Consumers retain actual persistence, composition and runtime. Optional framework isolation, repository atomic
capabilities, UoW separation, security/recovery and exact coverage remain. TASK-00107 codifies guidance only;
TASK-00108/00109 own later MCP/capability source changes and TASK-00110 owns qualified Common adoption. The
current executable dependency checker is not changed or certified against the full target by this record.

## Original decision — preserved history

Production code follows `Domain <- Application`. AccessControl Domain may depend on its own Domain types, PHP
internals, and public Fight Common Domain primitives only. AccessControl Application may depend on its own
Application types, AccessControl Domain, PHP internals, and public Fight Common Domain primitives and
Application contracts. Neither layer may depend on Fight Common Adapter or Standards code.

Fight AccessControl has no production Adapter layer. Framework, persistence, HTTP, mail, queue, realtime, key,
client, and composition-root implementations belong to consumer repositories. Application-level JWT orchestration
is governed by ADR 0003 and supersedes this record's original JWT exclusion. Tests may provide in-memory doubles
and conformance fixtures without turning them into production adapters.

## Original consequences

Architecture and package-boundary checks reject outward production dependencies, framework imports, and a
production Adapter namespace. Consumer repositories remain free to use native mechanisms while conforming to
the shared observable behavior.

## Original rejected alternatives

Shipping one framework's adapters here was rejected because it would make the shared package the owner of
consumer composition and recreate the cross-repository coupling this extraction removes.
