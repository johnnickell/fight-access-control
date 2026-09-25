# Fight AccessControl

Framework-neutral identity, credential, session, authorization, and account-lifecycle contracts for Fight
applications.

The `0.2.0` release adds an opt-in, non-autoloaded OpenAPI component catalog
for consumer-owned documents. See [OpenAPI composition](docs/openapi-composition.md).

The `0.1.x` release line provides the first public-source package milestone while the API remains intentionally
pre-`1.0.0`. It delivers the framework-neutral Domain and Application behavior described by the repository-local
product specifications; consumer projects continue to own framework and infrastructure adapters. Tagged package
versions intentionally precede full starter implementation so those projects can integrate against immutable
version tags and return compatibility findings through later `0.x` releases.

## Package boundary

Production code follows `Domain <- Application`:

- `Domain` contains framework-independent business concepts and depends on no other package layer.
- `Application` coordinates use cases through Domain types and public Fight Common contracts.
- Application owns the supported access-JWT/opaque-refresh authentication lifecycle through Fight Common ports,
  while consumer repositories own clients, framework integration, persistence, HTTP, cookies, signing-key
  configuration, mail, queues, realtime, hosting, and composition-root adapters. This package has no PHP
  production Adapter layer.

See [CONTEXT.md](CONTEXT.md) for the accepted vocabulary and [TICKET-00001](planning/tickets/00001-TICKET.md)
for the repository-local behavioral and security authority.

### Transaction composition

Transaction-aware Application handlers and security services require Fight Common's supported
`TransactionalUnitOfWork` contract. Consumer composition roots must supply an implementation whose
`commitTransactional()` callback encloses the complete package-owned atomic operation and whose `isClosed()` reports
whether that transaction capability remains available. The deprecated `UnitOfWork` contract and its standalone
`commit()` method are not supported by AccessControl constructors.

This constructor type change is intentionally breaking while AccessControl remains pre-`1.0.0`: consumers upgrading
from `0.2.x` must replace `UnitOfWork` bindings with `TransactionalUnitOfWork` bindings. No compatibility adapter or
release/publication status is implied by this unreleased guidance.

### Current principal composition

Consumers implement `AuthenticationContextProvider` to expose only the authenticated User ID, refresh-session ID,
and authentication version for the current request. The composition root must create a new
`CurrentPrincipalProvider` for each request with that context provider and the package
`AuthoritativePrincipalResolver`. Application handlers use `CurrentPrincipalProvider`; its first lookup resolves
all principal roles and permissions from authoritative repositories and later lookups in the same request return
that cached result. Consumers cannot inject role or permission snapshots through this boundary.

After a consumer-owned framework adapter selects and resolves the request's authentication path, its composition
root creates one `SecurityContext` from exactly one `AuthenticatedUserPrincipal` or
`AuthenticatedAgentPrincipal`. The context exposes that authority's type and delegates its Permission and Role
checks. Agents retain only their direct Permissions and have no package-level Roles; framework wrappers, endpoint
policy, and response handling remain consumer-owned.

## Local development

PHP 8.5 and Docker are required. Tooling follows the Fight Common conventions and runs in the isolated
`fight-access-control` container through repository-owned scripts:

```bash
./bin/phpunit
./bin/phpcs
./bin/phpstan
./bin/deptrac
./bin/rector process src/
./bin/planning-check
```

`./bin/build` is the canonical completion command. It installs the tracked Composer resolution and runs the
single ordered `./bin/quality` gate. `./bin/build --latest` checks the latest dependency versions compatible
with `composer.json`; hosted CI performs that same latest-compatible resolution before invoking
`./bin/quality` directly.

For a clean, dated release candidate, `./bin/release certify <version>` records its exact `HEAD` and the
OpenAPI consumer-composition, planning, and package-quality evidence under ignored `.runs/`. It is
verification-only and does not create a commit, merge, tag, push, or publication.

Coordinate-build scratch work belongs under gitignored `.runs/<YYYY-MM-DD>-<slug>/`. Never stage it. When an
approved task needs isolation, create its disposable linked worktree under that run directory at `worktree/`; run
commands from that checkout and remove it only with separate cleanup authorization. See
[CONTRIBUTING.md](CONTRIBUTING.md) for Git Flow, isolation, and review expectations.

## Security

Do not disclose suspected vulnerabilities in public issues or pull requests. Follow the private reporting
process in [SECURITY.md](SECURITY.md).

## Visibility and release effects

These are independent operational effects. Approval for one is not approval for another; each requires a
separate approval:

- **Public repository visibility** exposes the source and history under the repository license.
- **Commit creation** records reviewed work in Git history.
- **Version tag creation** gives a selected commit a version identifier.
- **Packagist publication** makes package metadata discoverable and installable through Packagist.
- **Release publication** creates a hosted release and its release notes or artifacts.

The repository is public under the MIT License. `v0.1.0` is its first public package release; later `0.x`
versions may refine public contracts before the separate `1.0.0` stability review. A commit hash may still be
used for reproducible integration testing, but it is not a version tag or release.

## License

Fight AccessControl is available under the [MIT License](LICENSE).
