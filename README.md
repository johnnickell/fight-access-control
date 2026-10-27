<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/fight-access-control-dark.svg">
  <img src="docs/assets/fight-access-control-light.svg" alt="FIGHT Access Control" width="440">
</picture>

# Fight AccessControl

Framework-neutral PHP identity and authorization for applications that own their infrastructure. Manage Users,
Agent credentials, sessions, Roles, Permissions and account lifecycles without tying business rules to a framework.

[Install](#release-and-installation) · [Integration](#integrate-with-your-application) ·
[Capabilities](#choose-a-capability) · [Contributing](CONTRIBUTING.md) · [Changelog](CHANGELOG.md)

Current TASK delivery actions use the [Board's explicit handoffs](planning/tasks/BOARD.md#current-delivery-handoffs),
not historical PR/acceptance checkpoints. [Delivery conventions](planning/CONVENTIONS.md#current-delivery-handoffs)
keep ordinary checks offline and bound live checking/authorized intake retirement separately; releases stay separate.

## Release and installation

<!-- release:start -->
**Release: [v0.8.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.8.0)**

**Status: published.**

Requires **PHP 8.5+** and **Fight Common ^1.3** (resolved by Composer).

```bash
composer require johnnickell/fight-access-control:"~0.8.0"
```

[Release overview](docs/release-0.8.0.md) ·
[Tagged documentation](https://github.com/johnnickell/fight-access-control/tree/v0.8.0/docs)
<!-- release:end -->

GitHub publication and Packagist source/dist references are verified for **v0.8.0**. The immutable tagged
README retains its original preparation status; the release overview here records the verified publication.
Registry availability is not proof of installation or qualification in your application.

The constraint stays on this minor line. Pre-v1 minors can break API and persistence contracts: read the
[Changelog](CHANGELOG.md) and the target release overview before upgrading. Only the current contract is supported;
there are no compatibility defaults, historical readers or package migration/backfill routes
([policy](planning/adr/0011-pre-v1-current-contract-only.md)).

**Using `develop` or a release branch?** Relative guides describe the checked-out code, including prepared changes.
Use the matching published tag's documentation for an installed package. Installing this library does not supply
endpoints, a database schema, a login UI, framework bindings or production configuration.

## Integrate with your application

Core dependencies point inward: **Application → Domain**. Consumers supply infrastructure through package interfaces.
Future optional permission/framework integrations are approved under capability-first `src/Adapter`, pointing inward
through Application; they are [planned, not implemented](planning/wayfinder/tickets/WF-035-framework-permission-adapter-ownership.md).
Domain/Application retain their DDD structure; no framework bindings or production configuration are installed today.

| This package owns | Your application owns |
| --- | --- |
| Identity, credentials, sessions and account state | Persistence, schema and repository implementations |
| Commands, Queries, Events and use-case coordination | Framework wiring, transports, HTTP responses and UI |
| Role/Permission rules and authenticated authority | Entry-point authorization, abuse controls and request context |
| Recoverable credential-delivery state and worker contracts | Keys, ciphers, providers, queues and scheduling |

1. **Choose a capability below.** Read its composition guide and implement its required Domain repositories and
   Application ports. Do not treat the test doubles as production adapters.
2. **Wire one atomic boundary.** Supply Fight Common's `TransactionalUnitOfWork` on the shared persistence connection.
   Its `commitTransactional()` callback encloses package-owned atomic work; the deprecated standalone `UnitOfWork`
   contract is not supported. Follow the selected guide's concurrency, audit and failure semantics.
3. **Bind fresh request authority.** For User requests, supply `AuthenticationContextProvider` and create a new
   `CurrentPrincipalProvider` with `AuthoritativePrincipalResolver` per request. After resolving one authentication
   path, build `SecurityContext` from exactly one authenticated User or Agent principal. Never accept caller-supplied
   Role or Permission snapshots as authority; Agents have direct Permissions, not Roles.
4. **Run recovery independently of notifications.** Protect discovery and worker dispatch; schedule bounded recovery
   even when post-commit events trigger immediate work. Credential delivery is at-least-once, not exactly-once.
5. **Qualify the real composition.** Exercise authorization, transactions, concurrent writers, restart, keys and provider
   idempotency with your actual adapters. Package tests and a published version do not qualify a consumer deployment.

## Choose a capability

| Capability | Start here |
| --- | --- |
| User invitation, activation and credential delivery | [Delivery composition](docs/credential-delivery.md) · [Expired-work cleanup](docs/credential-expiry.md) |
| Optional User registration and administrator recovery | [Registration](docs/user-registration.md) · [Recovery](docs/registration-recovery.md) |
| Login, refresh, logout, passwords and current authority | [Behavioral contract](planning/tickets/00001-TICKET.md) · [Vocabulary and boundaries](CONTEXT.md#vocabulary) |
| Roles, Permission tiers and managed policy | [Permission tiers](docs/permission-tiers.md) · [Operation outcomes](docs/operation-outcomes.md) |
| Recoverable Agent provisioning, rotation and delivery | [Agent integration](docs/agent-integration.md) · [Conformance and evidence](docs/agent-operation-evidence.md) |
| Agent profiles and protected MCP capabilities | [Profile Tools](docs/agent-profile-tools.md) · [Tool authorization](docs/agent-mcp-authorization.md) · [Resource authorization](docs/agent-resource-authorization.md) · [Skill authorization](docs/agent-skill-authorization.md) |
| Permission-based Feature flags | [Declarations](docs/feature-references.md) · [Provisioning](docs/feature-provisioning.md) · [Preparation](docs/feature-preparation.md) · [Availability](docs/feature-availability.md) · [Management](docs/feature-management.md) · [Retirement](docs/feature-retirement.md) |
| Consumer-owned OpenAPI documents | [Opt-in schema components](docs/openapi-composition.md) |

Feature availability is not authorization for the underlying action. Registration exposure and enablement are
consumer choices. MCP support supplies neutral authorization and profile Tools, not a hosted server or endpoint.

### Released in v0.8.0

This release includes breaking Role caller revisions and recovery composition requirements, plus operation-outcome
and managed-policy consistency changes. Custom Role rename/remove bind the displayed `expected_revision`; stale
intent requires a fresh read and reconfirmation, not automatic token substitution. See
[Role composition](docs/role-revisions.md), [operation outcomes](docs/operation-outcomes.md) and the
[v0.8.0 integration overview](docs/release-0.8.0.md). These changes are **not part of published v0.7.0**.

## Development and contributions

Docker is required for the repository-owned PHP 8.5 tooling. From a clone:

```bash
git clone https://github.com/johnnickell/fight-access-control.git
cd fight-access-control
./bin/build
```

`./bin/build` builds the isolated `fight-access-control` image, resolves latest-compatible dependencies into the
ignored lockfile with `composer update`, then runs the canonical quality gate: syntax, planning, PHPCS, PHPStan,
architecture, Rector, PHPUnit, exact statement coverage and documentation links. No application server or database
is required for package tests. After that initial setup, focused commands include:

```bash
./bin/phpunit
./bin/phpcs
./bin/phpstan
./bin/planning-check
./bin/documentation-check
```

Start with [CONTRIBUTING](CONTRIBUTING.md) and [AGENTS](AGENTS.md) for branch selection, the Task Board, independent
review and verification. Optional Fight Agent OS/Pi users can use `/skill:next` for a recommendation, then explicitly
invoke `/skill:build TASK-NNNNN` for approved work. See the [workflow binding](planning/agents/project-profile.md#agent-os-workflow-binding);
private tooling is not required. Keep scratch and evidence in ignored `.runs/` subdirectories.

## Releases

Follow the [release runbook](docs/releasing.md) to update every current-version surface together and validate it
with `./bin/release check-docs <version>`. `./bin/release certify <version>` additionally requires a clean committed
candidate and records exact-commit local evidence; it never commits, merges, tags, pushes or publishes.

Version selection, independent acceptance, human signing, publication and consumer deployment remain distinct
steps. A README version or a dated Changelog section is not publication proof. The separate stability review is
still required before v1.0.0.

## Security and license

Report suspected vulnerabilities privately through [SECURITY.md](SECURITY.md), not public issues or pull requests.
Fight AccessControl is available under the [MIT License](LICENSE).
