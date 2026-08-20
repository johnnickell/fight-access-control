@CLAUDE.md

## AccessControl implementation conventions

Follow the existing Fight CMS and Omphalos AccessControl conventions before creating a new type, namespace, or
application pattern. Inspect the canonical source first; do not invent a parallel pattern when a public Fight
Common contract or an established bounded-context structure already exists.

- Before editing, resolve the local ticket and parent PRD, then inspect the closest existing AccessControl vertical
  slice. Implement from the Domain outward and run focused tests for each completed layer before the full build.
- Production code is rooted only in `src/Domain/AccessControl/` and `src/Application/AccessControl/`. Keep the
  bounded context in every production namespace and mirror it in `tests/`.
- Model a feature under its aggregate, for example `User/Command`, `User/Event`, and `User/Query` in Domain, with
  `Application/AccessControl/User/CommandHandler` and `QueryHandler` for handlers.
- Secret-bearing activation, login, refresh, logout, and password operations use the synchronous
  `Application/AccessControl/User/Security/AuthenticationService` and must never be serializable messages.
  Other commands implement `Fight\Common\Domain\Messaging\Command\Command`; queries implement its Query counterpart;
  domain events implement `Fight\Common\Domain\Messaging\Event\Event`. Messages are immutable DTOs: provide
  canonical `fromArray()`, `toArray()`, and named `get...()` accessors; round-trip serialization must be tested and
  missing required data must reject with a Domain exception.
- Command and query handlers implement the corresponding public Fight Common handler interface, declare their
  registration method, and extract the typed payload from its message.
- A CommandHandler performs one command as one atomic Unit of Work. It creates or mutates aggregates through Domain
  methods, persists only through Domain repositories, commits exactly once using the package's supported atomic
  boundary, and triggers its successful domain event only after that commit succeeds. It wraps command work in
  `try`/`catch (Throwable $throwable)`, triggers `CommandFailedEvent` with the original command and failure message,
  then rethrows the same exception. Never swallow failures, dispatch success before commit, or split one use case
  into multiple commands.
- The synchronous `AuthenticationService` applies the same one-Unit-of-Work and post-commit success ordering,
  accepts raw secrets only as `#[\SensitiveParameter]` method arguments, uses Fight Common password and token
  ports, returns non-serializable token results, and dispatches `RedactedCommandFailed` without secret inputs.
- A QueryHandler reads through Domain repositories only. It does not mutate aggregates, commit a Unit of Work, or
  dispatch domain events.
- Aggregate roots own their state changes; a handler coordinates aggregates but does not reconstruct their internal
  relationships. Keep entities extensible (`protected` constructors and no `final`); reserve `final readonly` for
  immutable DTOs, handlers, and services where extension is not part of the contract.
- Persistence contracts are Domain repository interfaces beside their aggregates, named `*Repository` and using
  canonical methods such as `add()`, `getById()`, and `remove()`. Handlers depend on those Domain repositories;
  do not introduce Application `*Store` interfaces, `save()` ports, or preflight `reserve()` calls. Enforce
  canonical-email uniqueness atomically in `UserRepository::add()`. Name dependency properties after their complete
  role, for example `$activationGrantRepository`, never abbreviations such as `$grants`.
- Keep in-memory repository implementations in the Application test boundary under `Repository/`. Production
  persistence adapters remain consumer-owned; do not add a package Adapter layer without explicit approval.
- Application ports such as clocks, credential generators, and ciphers belong in `Application/AccessControl/<Aggregate>/Service`.
  Keep their test implementations in the matching test `Service/` namespace; reserve `CommandHandler/` and
  `QueryHandler/` for handlers only.
- Reuse public Fight Common values and identities. In particular use
  `Fight\Common\Domain\Value\Internet\EmailAddress` rather than creating a local email value object. Context-owned
  IDs extend `Fight\Common\Domain\Identity\UniqueId`. Domain exceptions live under their aggregate's `Exception/`
  namespace, use an `Exception` suffix, and extend the applicable Fight Common Domain exception.
- Backed enum case names are uppercase (`PENDING_ACTIVATION`, `ACTIVE`), with stable serialized string values.
- Match established PHP style: `final readonly` where appropriate, class and method docblocks, explicit
  constructors, one declaration per line, no compressed one-line methods, a space after casts, no spaces around
  concatenation, a blank line before non-trivial `return` statements, and alphabetically sorted `use` statements at
  all times. Use native types rather than `@param` or `@return` docblocks.
- Tests mirror the source boundary. Production tests declare `#[CoversClass]`; tooling tests declare
  `#[CoversNothing]`. Handler tests cover the success ordering (persist → commit → event), rejected or missing data,
  and failures that both rethrow and dispatch `CommandFailedEvent`.
- Every production statement under `src/` requires executable coverage. `./bin/build` must generate Clover from
  PHPUnit, reject coverage-ignore directives, and fail unless statement coverage is exact; do not weaken or bypass
  this gate.
- Do not add a public `contracts/` directory, a production Adapter layer, or a Composer production namespace
  without explicit approval.
