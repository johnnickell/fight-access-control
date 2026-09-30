# Bind self-service profile tools to the authenticated Agent

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Agent-aware MCP authorization and self-service profile tools](../agent-aware-mcp-authorization-map.md)
**Depends on:** WF-008

## Question

How do the MCP self-service profile read and name-update tools select their target Agent?

## Must decide

- Whether the target comes exclusively from authentication or may be supplied by the caller.

## Required evidence

John explicitly confirmed this boundary as “settled” after clarifying that self-service tools target only the
authenticated Agent and accept no caller-supplied target Agent ID.

## Resolution boundary

This decision settles only target selection for the two MCP self-service profile tools. It does not settle output
schemas, Permission identities, unchanged-name update semantics, or a separate administrator API. It does not
authorize implementation.

## Resolution

Both tools derive their target Agent ID exclusively from the authenticated principal. Neither accepts a
caller-supplied target Agent ID. Reading or updating another Agent is outside these self-service tools.

Carry this boundary forward as an accepted requirement, not a provisional hypothesis or a question to reopen.
The map remains active for the remaining profile contract decisions; WF-008's authorization rules are unchanged.
