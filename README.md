# Fight AccessControl

Framework-neutral identity, credential, session, authorization, and account-lifecycle contracts for Fight
applications.

This repository is in public-source incubation. It currently establishes the package boundary, local planning
authority, isolated tooling, and quality contracts; it does not yet claim a release or provide the
capabilities described by the adopted product specification.

## Package boundary

Production code follows `Domain <- Application`:

- `Domain` contains framework-independent business concepts and depends on no other package layer.
- `Application` coordinates use cases through Domain types and public Fight Common contracts.
- Application owns the supported access-JWT/opaque-refresh authentication lifecycle through Fight Common ports,
  and the repository supplies its editable React client. Consumer repositories own framework, persistence, HTTP,
  cookies, signing-key configuration, mail, queue, realtime, hosting, and composition-root adapters. This package
  has no PHP production Adapter layer. Framework-native session authentication is an unsupported fallback.

See [CONTEXT.md](CONTEXT.md) for the accepted vocabulary and [planning/specs/00001-PRD.md](planning/specs/00001-PRD.md)
for the repository-local behavioral and security authority.

### Current principal composition

Consumers implement `AuthenticationContextProvider` to expose only the authenticated User ID, refresh-session ID,
and authentication version for the current request. The composition root must create a new
`CurrentPrincipalProvider` for each request with that context provider and the package
`AuthoritativePrincipalResolver`. Application handlers use `CurrentPrincipalProvider`; its first lookup resolves
all principal roles and permissions from authoritative repositories and later lookups in the same request return
that cached result. Consumers cannot inject role or permission snapshots through this boundary.

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

Coordinate-build scratch work belongs under gitignored `.runs/`. Never stage it. See
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

The repository is public under the MIT License, but remains untagged, unpublished on Packagist, and unreleased
unless each effect is separately approved and verified. A commit hash may be used for reproducible integration
testing, but it is not a version tag or release.

## License

Fight AccessControl is available under the [MIT License](LICENSE).
