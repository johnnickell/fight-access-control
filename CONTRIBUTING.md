# Contributing

Fight AccessControl is in public-source incubation. Contributions are welcome through reviewed pull requests
that follow this repository's planning, architecture, quality, and Git Flow contracts. Contributions are
provided under the repository's [MIT License](LICENSE).

## Before changing code

Start with [AGENTS.md](AGENTS.md) and its routing entry; [CLAUDE.md](CLAUDE.md) forwards to the same authority.
For execution, read the [project profile](planning/agents/project-profile.md), [domain glossary](GLOSSARY.md),
relevant composition guides and applicable [ADRs](planning/adr/README.md), and the selected accepted TASK under
[planning/](planning/README.md), including actual blockers and required private inputs. A public summary is not a substitute for a required maintainer brief.

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
[project profile](planning/agents/project-profile.md#tests-and-delivery). Create new source worktrees at
`<repo_path>/.runs/worktrees/<slug>/`, using the canonical base checkout resolved through Git metadata. Preserve
existing registered paths and keep notes, logs, handoffs and reviews separate. `.runs/` is gitignored and must never be staged.
Preserve unrelated changes and do not copy consumer implementations into this library.

## Package PHP runtime

[Local tooling](docs/engineering/LOCAL_TOOLING.md#two-caches-with-different-lifetimes) owns build preparation,
cache admission and runtime details. A normal `./bin/build` validates planning freshly, then either reuses qualifying
application evidence or prepares and executes the full gate. `./bin/build --force` requests fresh application execution.
A cache hit skips preparation; report retained application evidence separately from fresh checks.

On a cache miss, preparation refreshes the image, verifies the actual runtime before mounted package work and resolves
dependencies. Focused Docker wrappers retain their runtime admission; they are not a cache or security-floor bypass.
The supported CLI line is stable PHP 8.5, minimum 8.5.12. Refresh or admission failure stops execution without a silent
offline fallback. Restore the failed prerequisite before retrying. Consumer Composer requirements and separately
controlled release image inputs are unchanged.

When changing these wrappers, qualify them directly with the native commands in
[local tooling](docs/engineering/LOCAL_TOOLING.md): prepare the explicit isolated checkout, inspect the actual
image admission, exercise the focused command, then run the complete `./bin/build` gate. Verify rejection
before mounted package work when refresh or admission fails. The obsolete Docker-double harness has been
removed because it modeled the superseded container-run interface.

This check covers only the package's local CLI build runtime. Consumers must independently assess and patch their
web-serving PHP-FPM/Apache/other runtimes and perform required service restarts through their own procedures.
A local CLI pass is neither consumer remediation nor proof of exploitability or compromise.

## Consumer documentation site

Run `./bin/docs-build` with [uv](https://docs.astral.sh/uv/) and Python 3.12. uv uses the locked dependencies in
`etc/docs-site/uv.lock`; it can download Python if needed. Docker and Composer are not needed for the site.
The command checks navigation, local links and anchors through a strict MkDocs build. On success it prints the
absolute path to a new static artifact under ignored `site/`. To preview, serve that directory locally:

```bash
site_dir=$(./bin/docs-build) && python3 -m http.server --bind 127.0.0.1 --directory "$site_dir" 8000
```

Open `http://127.0.0.1:8000/`. On failure, the command exits nonzero without returning an artifact path or
replacing an earlier successful artifact. The site command never deploys. Keep navigation in `mkdocs.yml` aligned
with the allowlist in `etc/docs-site/consumer_files.py`: publish only consumer pages and the assets they need. Repository guides, release procedures,
engineering standards, planning and evidence are not part of the site artifact. Run `./bin/documentation-check`
separately for repository Markdown links.

The **Consumer docs** Actions workflow runs this build on pull requests. A push to `main` also uploads the
successful build and deploys that artifact to GitHub Pages. Pull requests and `develop` do not deploy. If the
build, upload, or deployment fails, check the failed job in Actions; a failed run does not replace the last
successful site.

At delivery, ask the authorized repository owner to confirm that Pages uses **GitHub Actions** as its source,
that the `github-pages` environment allows deployment from `main`, and that the intended readers can access the
site. After changes reach `main` through the existing authorized merge process, match the successful Actions run
and Pages deployment to the exact `main` commit and open the URL reported by the deployment. The
[public consumer docs](https://johnnickell.github.io/fight-access-control/) show the last successful deployment;
use the [repository docs](docs/index.md) for the current checkout. The workflow does not change repository settings
or branch policy.

Put integration summaries and entry points in the consumer pages. Put detailed behavior in the guide for the
owning capability and link to it from the site rather than copying it. Use the [project profile](planning/agents/project-profile.md)
for current package and engineering rules, the [release runbook](docs/releasing.md) for release preparation, and
planning records for approved decisions and acceptance. Cite superseded material at its original commit instead
of keeping a second set of guides. When removing or merging a page, check inbound links and preserve the referenced
section or link to its historical snapshot, as appropriate.

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

For type-contract work, run `./bin/type-contracts` during iteration and follow the
[fixture guide](docs/type-contracts.md) to register exact positive inference and expected-negative diagnostics.
`bin/quality` requires these product contracts; qualify changes to the runner separately with
`./bin/exec php scripts/qualify_type_contracts.php`, outside default PHPUnit and production coverage.
Representative fixtures do not establish whole-library correctness or qualify missing Common dispatch/Event APIs.

Coding style composes Fight Common's published PHPCS ruleset through [phpcs.xml](phpcs.xml), with the
repository's documented exclusions and extensions. It covers the package's required strict types, layout,
naming, spacing, arrays, and documentation checks; do not copy Fight Common sniffs into this repository.

Production declaration/contract changes also reconcile the required [structural ownership inventory](docs/engineering/contract-inventory.md).
Its compact fingerprints run in `bin/quality`; detailed candidate evidence stays ignored and pending semantic judgments
remain with the existing correction owners. Qualify changes to the inventory tool separately from product PHPUnit.

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
