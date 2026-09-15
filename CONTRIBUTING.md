# Contributing

Fight AccessControl is in public-source incubation. Contributions are welcome through reviewed pull requests
that follow this repository's planning, architecture, quality, and Git Flow contracts. Contributions are
provided under the repository's [MIT License](LICENSE).

## Before changing code

Read [CLAUDE.md](CLAUDE.md), [CONTEXT.md](CONTEXT.md), and the repository-local planning authority under
[planning/](planning/README.md). Work only from a ready local TASK. Detailed capability tasks are not part
of the bootstrap itself.

Use Git Flow:

- `main` is the stable production line.
- `develop` integrates completed work.
- `feature/<name>` branches from `develop` and returns through review.
- Never commit feature work directly to `develop` or `main`.

Keep each effort isolated in its assigned branch and worktree. Coordinate-build notes and spoke reports belong
under `.runs/<YYYY-MM-DD>-<slug>/`; `.runs/` is scratch space, is gitignored, and must never be staged. Preserve
unrelated changes and do not copy consumer implementations into this library.

## Quality and review

Use the repository-owned `./bin/*` wrappers. Run focused tests while iterating, then run both:

```bash
./bin/planning-check
./bin/build
```

`./bin/build` is the canonical local completion gate. Hosted CI resolves latest-compatible Composer
dependencies and then calls the same `./bin/quality` gate; it does not maintain a second checklist.

Coding style composes Fight Common's published PHPCS ruleset through [phpcs.xml](phpcs.xml), with the
repository's documented exclusions and extensions. It covers the package's required strict types, layout,
naming, spacing, arrays, and documentation checks; do not copy Fight Common sniffs into this repository.

The repository includes an opt-in pre-commit hook that delegates to the same default build:

```bash
git config core.hooksPath .githooks
```

This changes only the current clone. Once enabled, the default build gate is non-bypassable: never use
`git commit --no-verify`. Diagnose and repair every failure, then let the hook complete successfully before
creating the commit. There is deliberately no pre-push hook.

Keep production code framework-neutral with dependency direction `Domain <- Application`. Do not add a
production Adapter layer, framework integration, persistence implementation, or capability outside the active
TASK. Report security concerns using [SECURITY.md](SECURITY.md), never through a public issue.

Code changes, commits, pushes, pull requests, private or public visibility changes, version tags, Packagist
publication, and releases are separate effects. Obtain and record the required approval for each one.
