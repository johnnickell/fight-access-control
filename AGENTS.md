@CLAUDE.md

## AccessControl implementation conventions

Follow the existing Fight CMS and Omphalos AccessControl conventions before creating a new type, namespace, or
application pattern. Inspect the canonical source first; do not invent a parallel pattern when a public Fight
Common contract or an established bounded-context structure already exists.

- Production code is rooted only in `src/Domain/AccessControl/` and `src/Application/AccessControl/`. Keep the
  bounded context in every production namespace and mirror it in `tests/`.
- Model a feature under its aggregate, for example `User/Command`, `User/Event`, and `User/Query` in Domain, with
  `Application/AccessControl/User/CommandHandler` and `QueryHandler` for handlers.
- Commands implement `Fight\Common\Domain\Messaging\Command\Command`; queries implement its Query counterpart;
  domain events implement `Fight\Common\Domain\Messaging\Event\Event`. Provide the canonical `fromArray()`,
  `toArray()`, and named `get...()` accessors.
- Command and query handlers implement the corresponding public Fight Common handler interface, declare their
  registration method, extract the typed payload from its message, and use the package Unit of Work and event
  dispatcher in the established order.
- Persistence contracts are Domain repository interfaces beside their aggregates, named `*Repository` and using
  canonical methods such as `add()`, `getById()`, and `remove()`. Handlers depend on those Domain repositories;
  do not introduce Application `*Store` interfaces, `save()` ports, or preflight `reserve()` calls. Enforce
  canonical-email uniqueness atomically in `UserRepository::add()`.
- Keep in-memory repository implementations in the Application test boundary under `Repository/`. Production
  persistence adapters remain consumer-owned; do not add a package Adapter layer without explicit approval.
- Reuse public Fight Common values and identities. In particular use
  `Fight\Common\Domain\Value\Internet\EmailAddress` rather than creating a local email value object. Context-owned
  IDs extend `Fight\Common\Domain\Identity\UniqueId`.
- Backed enum case names are uppercase (`PENDING_ACTIVATION`, `ACTIVE`), with stable serialized string values.
- Match established PHP style: `final readonly` where appropriate, class and method docblocks, explicit
  constructors, one declaration per line, and no compressed one-line methods.
- Do not add a public `contracts/` directory, a production Adapter layer, or a Composer production namespace
  without explicit approval.
