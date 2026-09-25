# Fight AccessControl Project Profile

This package binds the [Fight Engineering Standards](../../docs/engineering/STANDARDS.md) to its Domain and
Application package boundary.

## Package boundary and contracts

- Production code is limited to src/Domain/AccessControl and src/Application/AccessControl, mirrored by tests.
- Consumers own production persistence adapters, transport, framework composition, keys, queues, and runtime. Do not
  add a production Adapter layer, contracts directory, or Composer production namespace without explicit approval.
- Reuse public Fight Common values and identities: context IDs extend UniqueId, email is the common EmailAddress,
  messages use the public Command, Query, Event, and handler contracts, and package exceptions stay under their
  aggregate Exception namespace.
- Repositories are Domain interfaces beside their aggregate. Handlers use canonical add/getById/remove operations and
  the supported atomic boundary; do not create Application Store ports, save ports, or preflight reservation calls.
- UserRepository::add() enforces canonical-email uniqueness atomically. Backed enum cases are uppercase and retain
  stable serialized string values.

## Application and security behavior

- Secret-bearing activation, login, refresh, logout, and password operations remain synchronous
  AuthenticationService methods. Raw secrets are sensitive parameters, never serializable messages, and failures
  dispatch only redacted failure evidence.
- Serializable Commands, Queries, and Events are immutable DTOs with canonical fromArray/toArray round trips,
  named getters, and rejection of missing required data.
- Command and Query handlers implement the corresponding public Fight Common handler interface, declare their
  registration method, and extract the typed payload from the received message.
- A CommandHandler owns one atomic Unit of Work: aggregate mutation and repository persistence precede one commit;
  a success event is dispatched only after that commit. On failure it dispatches CommandFailedEvent with the original
  command and message, then rethrows the same throwable.
- QueryHandlers read through Domain repositories only: no aggregate mutation, commit, or domain-event dispatch.
- AuthenticationService follows the same atomic, post-commit ordering, uses Fight Common password and token ports,
  returns non-serializable token results, and emits RedactedCommandFailed without raw secret input.
- Aggregate roots own state transitions. Keep aggregate entities extensible; use final readonly only for immutable
  DTOs, handlers, and services where extension is not part of the contract.

## Tests and delivery

- In-memory repositories and service doubles belong in the matching Application test boundary. Production tests use
  CoversClass; tooling tests use CoversNothing. Handler tests prove post-commit event ordering and failure rethrow
  behavior.
- Application clocks, credential generators, and ciphers belong under the matching Application aggregate Service
  namespace; their test doubles use the matching test Service namespace.
- Every production statement requires executable coverage. The isolated fight-access-control PHP container is the
  package runtime; ./bin/planning-check and ./bin/build are mandatory pre-submit gates, and the build enforces
  PHPCS, PHPStan, architecture, Rector, PHPUnit, and exact statement coverage.
- Use the ignored `.runs/<YYYY-MM-DD>-<slug>/worktree` linked-worktree layout from develop; retain it through
  review. Store run-local coordination, notes, and gate receipts below that same run directory; store reusable
  handoffs under `.runs/handoffs/<task>/`. Branch feature work from develop and never commit directly to develop or
  main. Release certification, tags, publication, and cleanup remain separately authorized.
