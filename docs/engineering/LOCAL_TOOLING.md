# Local Docker, worktrees and build evidence

Use these commands when preparing, validating or retiring this repository. Python 3, Git, Docker with native
Compose v2 `!override` support, and the target's normal dependency access are prerequisites. Worktrunk is needed
only for guarded source removal and must support `remove --foreground --format=json --yes`.

## Select the checkout

`bin/up` and `bin/down` without a selector always operate on the canonical main checkout. From an isolated
registered worktree, explicitly select it for lifecycle commands. Tooling, Composer and focused wrappers inside
that worktree select its project automatically. Do not set ambient `COMPOSE_FILE` or `COMPOSE_PROJECT_NAME` to
choose a target; the wrapper selects files, project and environment explicitly.

```sh
./bin/up                                      # Main stack
./bin/down                                    # Main down, named data volumes retained
./bin/up --worktree /absolute/registered/path  # One isolated stack
./bin/down --worktree /absolute/registered/path
```

Git registrations resolve paths, branches and unambiguous short names. Ambiguity fails before effects. Each
worktree's project hashes the canonical repository and exact checkout path, so branches may change without
renaming its resources. Worktree ports bind dynamically on loopback; inspect the actual published port with
`./bin/runtime docker compose ps`. Compose health is not proof of an application/browser journey. No shared
ingress, custom DNS, certificate enrollment, database migrations or imported main data is implicit.

From the selected isolated checkout prepare dependencies/runtime with:

```sh
./bin/up --worktree "$PWD"
./bin/composer update --no-interaction --prefer-dist --no-progress
./bin/build
./bin/build --force                           # Deliberate fresh application gate
./bin/exec php --version
./bin/runtime inspect
```

`bin/up --worktree PATH --build-only` builds images without starting the stack. This repository's tooling
service is `php`. Composer/quality commands retain the local project's dependency and coverage policy.
Main and worktrees have separate images, networks, source mounts and generated output. The native Git common
directory is mounted read-only so linked-worktree locators function without a metadata copy.

## Two caches with different lifetimes

Docker's ordinary shared builder retains downloaded layers and build cache across worktrees. Lifecycle down
never runs a machine-wide prune, clears a shared builder or force-removes an immutable image ID. Isolated down
removes only reserved task image tags, project containers, private networks and disposable named volumes.
Main down preserves named data volumes. Unknown resource labels and foreign consumers, including stopped
containers, hold the affected teardown instead of being treated as disposable.

A normal `bin/build` first runs offline planning validation freshly. The checkout-local `var/build.json` points
to the original successful full-gate receipt and log under ignored `.runs/build/`. Reuse requires identical
source, installed dependencies/lockfile, governing instructions, environment, actual image/container/runtime
identities and generated artifacts, plus unchanged original evidence. Ordinary planning record Markdown is
excluded from application inputs; planning conventions, agent instructions, ADRs, templates and executable
planning inputs remain covered. Symlink targets and bytes/modes are covered. Missing, changed or unreadable
evidence cannot qualify a hit. Concurrent builds refuse the same checkout's lock.

A miss executes the entire existing gate and reports its reason and original exit. `--force` invalidates the
pointer before fresh execution; failures never publish a success. Runtime recreation invalidates reuse even
when image layers are cached. Report fresh planning separately from the named original application receipt;
a cache hit does not provide independent Review/QA acceptance or permit publication/merge by itself.

Ignored `var/worktrees/<project>/` in canonical main contains generated Compose configuration and a locator,
not a second resource inventory or a worker/PID admission ledger. Application boot never depends on `.runs/`.
Keep evidence while consumed; record final results in the existing work record/PR before retiring exact owned
scratch and generated output. Preserve unknown/unique data and unrelated sessions. No new TASK record is
required for this explicitly authorized rollout.

## Retire Docker before source

Run the public `bin/down --worktree PATH` first, independently of source/delivery holds. Repeating it uses
native Docker inventories; unavailable inventory is an error. Preserve the locator until source retirement.
Do not delete source with raw Git/rm, force Worktrunk or bypass its hooks.

After required independent acceptance, exact publication verification and durable outcome recording, run from
the canonical safe checkout:

```sh
./bin/runtime finish --worktree /absolute/registered/path   --outcome /absolute/surviving/outcome.md --published-oid FULL_OID   --pr https://github.com/OWNER/REPOSITORY/pull/NUMBER
```

The outcome must survive outside disposable source. Finish invokes down first and checks the exact live PR,
ref and published OID. The shared guarded remover then requires clean tracked/untracked source, no needed
ignored/private data, no active source consumers and no filesystem protections. It rechecks effective
pre/post-remove hooks in both canonical and target contexts. Only absent hooks or the exact repository hook
in `.config/wt.toml` are admitted; unknown/user/changed hooks retain source without suppressing Docker down.
Native per-invocation consent never persists trust or skips a hook. A canonical checkout missing the supported
`bin/down` must adopt it through normal delivery before removal; do not patch an active main checkout to
circumvent that hold.

Native Worktrunk owns non-force source/ref removal. Verify source, registration, runtime and generated config
absence separately. An exact unchanged `retained_unmerged` local ref is reported as retained, not deleted or
failed cleanup. Never delete a remote head, stop a shared VM, strip protections or archive source routinely.
After successful retirement, repeat using the original absolute path; do not redirect through a surviving ref.

## Maintainer ownership

`etc/local-tooling.json` binds the local service set, gate, artifact paths and runtime probes. Update it whenever
those contracts change. `scripts/local_tooling` owns this checkout's adapters. Guarded native removal modules
(`resources.py`, `source.py`, `publication.py`, `worktrunk.py`) were adopted verbatim from Fight Agent OS
`scripts/resource_lifecycle` at `cbdf91562b2a5a8afb1c67cb83f24891f52015c5` (2026-11-02). Runtime selection and
build evidence are local adaptations, not copied provider application services. Changes require explicit
comparison and qualification; no private-path dependency, symlink or automatic synchronization is installed.
Validate these tools directly with native Compose/Worktrunk and normal gates, outside product test suites.

AccessControl retains its stable PHP 8.5 security floor (minimum 8.5.12). Image refresh and native admission run without a source mount before package work; an unavailable refresh or unsupported PHP stops the command. Ordinary gate preparation retains `composer update`; `--latest` remains accepted. The library owns a CLI tooling service and requires no HTTP stack or application database.

Local development builds set native `BUILDX_NO_DEFAULT_ATTESTATIONS=1` for the build command so cached executable layers retain a stable immutable image identity instead of a timestamped attestation index. Release/publication commands retain their own policy. Layers and actual image IDs remain verified; this does not permit publishing local images.

Use `./bin/runtime docker ps` to list all selected-checkout containers and their published ports, including plain-run tool previews. `docker compose ps` lists Compose services only.
