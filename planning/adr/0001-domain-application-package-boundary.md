# ADR 0001: Domain and Application Package Boundary

- Status: accepted
- Date: 2026-08-16

## Decision

Production code follows `Domain <- Application`. AccessControl Domain may depend on its own Domain types, PHP
internals, and public Fight Common Domain primitives only. AccessControl Application may depend on its own
Application types, AccessControl Domain, PHP internals, and public Fight Common Domain primitives and
Application contracts. Neither layer may depend on Fight Common Adapter or Standards code.

Fight AccessControl has no production Adapter layer. Framework, persistence, HTTP, mail, queue, realtime, key,
and composition-root implementations belong to consumer repositories. Application-level JWT orchestration and
the editable React client are governed by ADR 0003 and supersede this record's original JWT/client exclusion.
Tests may provide in-memory doubles and conformance fixtures without turning them into production adapters.

## Consequences

Architecture and package-boundary checks reject outward production dependencies, framework imports, and a
production Adapter namespace. Consumer repositories remain free to use native mechanisms while conforming to
the shared observable behavior.

## Rejected Alternatives

Shipping one framework's adapters here was rejected because it would make the shared package the owner of
consumer composition and recreate the cross-repository coupling this extraction removes.
