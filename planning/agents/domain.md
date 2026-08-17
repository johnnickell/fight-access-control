# Domain and Engineering Rules

Fight AccessControl is framework-neutral and has exactly two production layers. Preserve dependency direction
`Domain <- Application`:

- `src/Domain/` owns identity, credential, session, authorization, and lifecycle invariants without framework or
  infrastructure dependencies.
- `src/Application/` orchestrates explicit use cases and depends only on Domain and public Fight Common contracts.
- There is no production `src/Adapter/` layer. Consumers own every persistence, HTTP, framework security, mail,
  queue, JWT, realtime, and runtime-composition adapter.

Do not copy Fight Common primitives. Use its public contracts. Keep commands explicit, return immutable views from
queries, make required audit evidence durable with the mutation, and publish success events only after commit.

All production classes require exact complete statement coverage. Run `./bin/planning-check` and the canonical
noninteractive `./bin/build` before completion.
