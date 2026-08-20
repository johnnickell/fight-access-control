# Domain and Engineering Rules

Fight AccessControl is framework-neutral and has exactly two production layers. Preserve dependency direction
`Domain <- Application`:

- `src/Domain/` owns identity, credential, session, authorization, and lifecycle invariants without framework or
  infrastructure dependencies.
- `src/Application/` owns the synchronous secret-bearing authentication service, orchestrates other explicit use
  cases, and depends only on Domain and public Fight Common contracts.
- There is no production `src/Adapter/` layer. Application creates access-JWT material through Fight Common
  ports; consumers own persistence, HTTP, cookies, signing keys, framework integration, mail, queues, realtime,
  and runtime composition. Framework-native session authentication is outside the supported profile.

Do not copy Fight Common primitives. Use its public contracts. Keep commands explicit, return immutable views from
queries, make required audit evidence durable with the mutation, and publish success events only after commit.

All production classes require exact complete statement coverage. Run `./bin/planning-check` and the canonical
noninteractive `./bin/build` before completion.
