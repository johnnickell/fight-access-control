# Contributing

Fight AccessControl is in public-source incubation. Contributions are welcome through reviewed pull requests
that follow this repository's planning, architecture, quality, and Git Flow contracts. Contributions are
provided under the repository's [MIT License](LICENSE).

## Before changing code

Start with [AGENTS.md](AGENTS.md) and its routing entry; [CLAUDE.md](CLAUDE.md) forwards to the same authority.
For execution, read the [project profile](planning/agents/project-profile.md), relevant [CONTEXT.md](CONTEXT.md)
sections and selected accepted TASK under [planning/](planning/README.md), including actual blockers and required
private inputs. A public summary is not a substitute for a required maintainer brief.

With optional Fight Agent OS/Pi tooling, `/skill:next` recommends and stops; explicitly invoke
`/skill:build TASK-NNNNN` for the selected approved TASK, substituting its actual ID. Build coordinates work,
independent review, QA and open-PR delivery without another approval for already delegated effects. Use
`/skill:work TASK-NNNNN` only for implementation alone. Follow the
[workflow binding](planning/agents/project-profile.md#agent-os-workflow-binding) and
[state-specific continuations](planning/CONVENTIONS.md#workflow-continuations); no private tooling is required
for contributors following the local standards directly.

Use Git Flow:

- `main` is the stable production line.
- `develop` integrates completed work.
- `feature/task-NNNNN-<slug>` branches from `develop` and returns through review; preserve an authorized existing TASK branch.
- Never commit feature work directly to `develop` or `main`.

Keep each effort isolated in its assigned branch and selected checkout. Reuse a TASK checkout choice already
made; otherwise resolve main checkout versus isolated worktree before implementation. Use purpose-named `.runs/` subdirectories from the
[project profile](planning/agents/project-profile.md#tests-and-delivery), including `.runs/worktree/<task-slug>/`
for checkouts and separate notes, logs, handoffs and reviews. `.runs/` is gitignored and must never be staged.
Preserve unrelated changes and do not copy consumer implementations into this library.

## Package PHP runtime

`./bin/build` and the focused Docker wrappers (through `bin/.container-run`) always build with `--pull`,
then inspect the actual selected image's PHP version before running Composer, the quality gate or your command.
The supported build line is stable **PHP 8.5**, with a security floor of **8.5.12**. Later 8.5 patches are admitted;
prereleases, malformed/missing results and other lines fail closed until their support/security floors are assessed.
The floating `php:8.5-cli` base retrieves current patches; a Docker tag or Composer's simulated platform version
is not runtime verification. This does not change consumer Composer requirements.

Docker/registry access is required even with a cached image. Refresh or inspection failure exits nonzero without
running package work; there is no silent offline fallback. Restore Docker/network/registry access or availability
of a patched upstream image, then retry. `FIGHT_ACCESS_CONTROL_PHP_IMAGE` still selects the package image name,
but overrides receive the same refresh and runtime checks. `DOCKER_BIN` still selects the Docker executable.

When changing these wrappers, run the explicit isolated qualification separately:

```bash
python3 scripts/qualify_php_runtime.py
```

It exercises real wrappers with a Docker double (no daemon or registry needed), including failure paths and
linked-worktree build mounts. It is not part of product PHPUnit coverage, ordinary CI or the default build;
qualification does not replace verification of the real refreshed image and the complete `./bin/build` gate.

This check covers only the package's local CLI build runtime. Consumers must independently assess and patch their
web-serving PHP-FPM/Apache/other runtimes and perform required service restarts through their own procedures.
A local CLI pass is neither consumer remediation nor proof of exploitability or compromise.

## Quality and review

Use the repository-owned `./bin/*` wrappers. Run focused tests while iterating, then run both:

```bash
./bin/planning-check
./bin/build
```

`./bin/build` is the canonical local completion gate. Its default PHPUnit/native/OpenAPI workload uses a bounded
512 MiB PHP memory limit, like PHPStan; all suites, fail-on-skipped and exact statement coverage remain mandatory.
Documentation-only follow-ups may retain a prior full pass
only under [Testing's verified evidence-reuse rule](docs/engineering/standards/Testing.md#reusing-a-full-gate-result-after-documentation-only-follow-ups),
with current targeted checks and a complete log/exit/content mapping. Hosted CI resolves latest-compatible Composer
dependencies and calls the same `./bin/quality` gate; it does not maintain a second checklist. Follow
[Testing's hosted-check policy](docs/engineering/standards/Testing.md#gate-boundaries); do not infer a new hosted
requirement from public visibility or a workflow file. Builders do not monitor CI unless explicitly required/authorized.

Coding style composes Fight Common's published PHPCS ruleset through [phpcs.xml](phpcs.xml), with the
repository's documented exclusions and extensions. It covers the package's required strict types, layout,
naming, spacing, arrays, and documentation checks; do not copy Fight Common sniffs into this repository.

The repository has no pre-commit or pre-push build hook. Run and retain the required local gate evidence through
implementation and review; committing does not run the build again or establish verification by itself. A failing
required gate still blocks a completed handoff.

If an existing clone has the former repository hook path configured, check `git config --local --get core.hooksPath`.
When it is exactly `.githooks`, remove that obsolete setting with `git config --local --unset core.hooksPath`.
Preserve any differently configured or independently managed hooks.

Keep Domain/Application framework-neutral with dependency direction `Adapter → Application → Domain`.
The approved capability-first optional Adapter boundary has [PSR-15 Permission admission](docs/http-permission-admission.md)
with Slim/Yii 3 qualification. Include adapters in normal style/static-analysis/architecture tests and exact coverage;
keep native dependencies optional and registration explicit. Do not add framework integration, persistence or other
capabilities outside the active TASK. Report security concerns using [SECURITY.md](SECURITY.md), never through a public issue.

Code changes, commits, pushes, pull requests, visibility changes, merge, version tags, Packagist publication,
releases and deployment remain distinct effects. Record authority for each; one explicit invocation may delegate
several. Planning approval or a recommendation grants no publication authority. Explicit Build delegates its
owned commits, non-force push and open-PR publication, not PR approval/merge, force push, release, deployment,
unrelated work or restricted disclosure. Resolve genuinely missing prerequisites without repeating existing grants.
Signed library releases retain their [separate controls](docs/engineering/standards/Delivery.md#signed-library-releases).
Follow the [release runbook](docs/releasing.md) for the complete version inventory, README/Changelog preflight,
exact-commit certification and publication reconciliation; never globally replace historical version references.
