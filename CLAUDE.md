# CLAUDE.md

This file is the canonical agent and contributor guidance for Fight AccessControl.

## Local Authority

Read root `CONTEXT.md` for the package vocabulary and accepted architecture. Durable planning lives in this
repository under `planning/`; no Fight Common checkout is needed to determine AccessControl implementation
status or readiness.

- `planning/specs/00001-PRD.md` is the repository-local behavioral and security authority.
- `planning/ROADMAP.md` and `planning/epics/` define the local delivery parent and sequencing authority.
- `planning/adr/` records accepted architecture and quality decisions.
- `planning/agents/issue-tracker.md` defines ticket resolution, readiness, and completion.
- `planning/agents/triage-labels.md` defines the allowed workflow states.
- `planning/agents/domain.md` defines the Domain/Application boundary.
- `planning/tickets/BOARD.md` is the recommended execution order; ticket files remain canonical.

Coordinated build artifacts belong in `.runs/<YYYY-MM-DD>-<slug>/`. The directory is gitignored and must never
be staged. Durable decisions and outcomes belong in the canonical local planning artifact.

## Architecture

Fight AccessControl is a framework-neutral PHP library. Production dependency direction is
`Domain <- Application`: Domain depends on no other package layer, and Application may depend only on Domain
and public Fight Common contracts. There is no production Adapter layer. Application owns JWT/refresh
authentication orchestration through Fight Common ports; consumers own framework, persistence, HTTP, cookies,
signing keys, mail, queue, realtime, and composition-root implementations.

The two PHP production namespaces are `Fight\AccessControl\Domain\` and
`Fight\AccessControl\Application\`. The supported editable React client is a repository source artifact outside
the PHP namespaces, and framework-native session authentication is unsupported. Test namespaces mirror the production layers under
`Fight\Test\AccessControl\`.

## Commands

Tooling runs in the isolated `fight-access-control` PHP container. Interactive wrappers are conveniences for a
human terminal. Agents should use focused noninteractive container execution while iterating.

```bash
./bin/planning-check
docker container run --rm -e XDEBUG_MODE=coverage -v "$PWD:/app:delegated" -w /app fight-access-control \
  php vendor/bin/phpunit tests/Tooling/PlanningAuthorityTest.php
```

`./bin/build` is the canonical noninteractive completion command. It installs the tracked Composer resolution
and delegates to the single ordered quality gate. Always run both `./bin/planning-check` and the full
`./bin/build` before declaring a ticket complete, committing, or pushing.

The repository's lean PHPCS ruleset uses published PSR-12 and Slevomat rules for strict types, layout, naming,
spacing, arrays, and documentation presence. It is intentionally not a copy of Fight Common's private custom
semantic documentation sniffs and does not claim exact parity with them. When a compatible Fight Common release
exports a consumer coding standard, migrate this package to that exported standard and remove overlapping local
configuration; do not create or copy a parallel custom authority.

## Git Flow

- `main` contains the stable production line.
- `develop` integrates completed work.
- `feature/<name>` branches from `develop` and returns through review.
- Never commit feature work directly to `develop` or `main`.

Commit, push, pull request, public visibility, version tags, and package publication are separate effects and
require their own authorization.
