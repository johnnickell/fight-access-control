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

Keep each effort isolated in its assigned branch and worktree. Use purpose-named `.runs/` subdirectories from the
[project profile](planning/agents/project-profile.md#tests-and-delivery), including `.runs/worktree/<task-slug>/`
for checkouts and separate notes, logs, handoffs and reviews. `.runs/` is gitignored and must never be staged.
Preserve unrelated changes and do not copy consumer implementations into this library.

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

The repository has no pre-commit or pre-push build hook. Run and retain the required local gate evidence through
implementation and review; committing does not run the build again or establish verification by itself. A failing
required gate still blocks a completed handoff.

If an existing clone has the former repository hook path configured, check `git config --local --get core.hooksPath`.
When it is exactly `.githooks`, remove that obsolete setting with `git config --local --unset core.hooksPath`.
Preserve any differently configured or independently managed hooks.

Keep production code framework-neutral with dependency direction `Domain <- Application`. Do not add a
production Adapter layer, framework integration, persistence implementation, or capability outside the active
TASK. Report security concerns using [SECURITY.md](SECURITY.md), never through a public issue.

Code changes, commits, pushes, pull requests, private or public visibility changes, version tags, Packagist
publication, and releases are separate effects. Obtain and record the required approval for each one.
