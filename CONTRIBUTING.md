# Contributing

Fight AccessControl is in private incubation. Contributions are accepted only from explicitly authorized
maintainers while repository access remains private. The [LICENSE](LICENSE) notice governs all access and
contributions; contributing does not select or grant a public license.

## Before changing code

Read [CLAUDE.md](CLAUDE.md), [CONTEXT.md](CONTEXT.md), and the repository-local planning authority under
[planning/](planning/README.md). Work only from a ready local ticket. Detailed capability tickets are not part
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

Coding style currently comes from the repository's lean PHPCS ruleset built only from published PSR-12 and
Slevomat rules. It covers strict types, layout, naming, spacing, arrays, and documentation presence without
claiming parity with Fight Common's unexported semantic documentation checks. When a compatible Fight Common
release exports its consumer standard, adopt that package-owned standard and remove overlapping local rules;
never copy its custom sniffs into this repository.

The repository includes an opt-in pre-commit hook that delegates to the same default build:

```bash
git config core.hooksPath .githooks
```

This changes only the current clone. When an exceptional commit must be created without the local gate, Git's
explicit bypass remains `git commit --no-verify`; record why the already-required build evidence was obtained
another way. There is deliberately no pre-push hook.

Keep production code framework-neutral with dependency direction `Domain <- Application`. Do not add a
production Adapter layer, framework integration, persistence implementation, or capability outside the active
ticket. Report security concerns using [SECURITY.md](SECURITY.md), never through a public issue.

Code changes, commits, pushes, pull requests, private or public visibility changes, version tags, Packagist
publication, and releases are separate effects. Obtain and record the required approval for each one.
