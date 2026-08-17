# Fight AccessControl

Framework-neutral identity, credential, session, authorization, and account-lifecycle contracts for Fight
applications.

This repository is in private incubation. It currently establishes the package boundary, local planning
authority, isolated tooling, and quality contracts; it does not yet claim a public release or provide the
capabilities described by the adopted product specification.

## Package boundary

Production code follows `Domain <- Application`:

- `Domain` contains framework-independent business concepts and depends on no other package layer.
- `Application` coordinates use cases through Domain types and public Fight Common contracts.
- Consumer repositories own framework, persistence, HTTP, mail, queue, JWT, realtime, and composition-root
  adapters. This package has no production Adapter layer.

See [CONTEXT.md](CONTEXT.md) for the accepted vocabulary and [planning/specs/00001-PRD.md](planning/specs/00001-PRD.md)
for the repository-local behavioral and security authority.

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

## Private-incubation effects

These are independent operational effects. Approval for one is not approval for another; each requires a
separate approval:

- **Private repository visibility** permits invited collaborators to inspect the incubation repository.
- **Public repository visibility** exposes the repository and its history to everyone.
- **Commit creation** records reviewed work in Git history.
- **Version tag creation** gives a selected commit a version identifier.
- **Packagist publication** makes package metadata discoverable and installable through Packagist.
- **Release publication** creates a hosted release and its release notes or artifacts.

The repository is private, untagged, unpublished on Packagist, and unreleased during incubation unless each
effect is separately approved and verified. A commit hash may be used privately for reproducible integration
testing, but it is not a version tag or release.

## License

This private-incubation repository is proprietary. No public license is granted. See [LICENSE](LICENSE).
