# Local Docker, worktrees and build evidence

Use these commands when preparing, validating or retiring this repository. The host wrappers require Bash, Git,
Docker with native Compose `!override` support, and `jq` for native JSON inventories. Fresh preparation needs
the package's normal dependency access. Guarded source removal also uses Worktrunk
(`remove --foreground --format=json --yes`), `lsof` and, for publication proof, `gh`. Local package tooling
needs neither host PHP nor Python. The separate [consumer docs workflow](../../CONTRIBUTING.md) still uses Python.

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

Git registrations resolve paths, branches and unambiguous short names. An explicitly empty selector or
ambiguous match fails before Docker effects. Each
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

A normal `bin/build` first runs offline planning validation freshly. On a host without the default package PHP
image, prepare it once with `./bin/up --build-only` in main, or select an already prepared package image as
[documented for planning](../../planning/CONVENTIONS.md#offline-planning-toolchain). This does not start services
or install project dependencies. The build starts a one-shot PHP process from that image with no Docker socket,
network or `vendor/` requirement. PHP owns the checkout lock and hashes, and the host wrapper runs planning,
preparation and the single gate. A symlink whose target is not visible in that checkout mount disables reuse.
The checkout-local `var/build.json` points
to the original successful full-gate receipt and log under ignored `.runs/build/`. Reuse requires identical
source, installed dependencies/lockfile, governing instructions and generated artifacts, plus unchanged
original evidence. Database contents/schema/identity, containers, runtime tools and inherited environment
values are not fingerprinted or queried for cache reuse. Ordinary planning record Markdown is
excluded from application inputs; planning conventions, agent instructions, ADRs, templates and executable
planning inputs remain covered. Symlink targets and bytes/modes are covered. Missing, changed or unreadable
evidence cannot qualify a hit. Concurrent builds refuse the same checkout's lock.

A cache hit skips build preparation, including dependency installation/update and image builds.
On a miss, configured preparation runs first and its resulting dependency contents become the gate inputs.
A miss executes the entire existing gate and reports its reason and original exit. `--force` invalidates the
pointer before fresh execution; failures never publish a success. Changing Pi roles or recreating runtime
services does not invalidate an otherwise qualifying receipt. Old cache formats require one fresh build.
Report fresh planning separately from the named original application receipt; a cache hit does not provide
independent Review/QA acceptance or permit publication/merge by itself.

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

Parent-mount and host source-consumer probes have 60-second deadlines. Foreground Worktrunk removal has a
120-second deadline; native lifecycle commands and inventories have a 30-minute limit. A timeout stops the
attempt. It does not prove that no effect occurred. Reobserve source, registration, ref and runtime before
another removal attempt; never retry blindly.

Native Worktrunk owns non-force source/ref removal. Verify source, registration, runtime and generated config
absence separately. An exact unchanged `retained_unmerged` local ref is reported as retained, not deleted or
failed cleanup. Never delete a remote head, stop a shared VM, strip protections or archive source routinely.
After successful retirement, repeat using the original absolute path; do not redirect through a surviving ref.

## macOS Docker Desktop retained-handle workaround

This host uses **Apple Virtualization framework / gRPC FUSE** as an explicitly approved local workaround
(TASK-00183, John, 2026-11-16). Leave that selection enabled. Docker documents both AVF sharing choices in
[Desktop settings](https://docs.docker.com/desktop/settings-and-maintenance/settings/); this is not a Docker
promise of per-task descriptor release. The qualified environment was macOS 26.6.2 on Apple Silicon,
Desktop 4.99.0 / Engine 29.9.3 and Worktrunk 0.81.0. Other hosts/backends need their own qualification.

Backend maintenance is separate from task cleanup. On a separately authorized host, inspect workloads and
record the current backend, container IDs/running intent and storage/resource identities first. Use supported
Desktop Settings → General → File sharing implementation → gRPC FUSE, retaining the existing AVF selection,
then the required normal Apply & Restart Engine. That interrupts shared services. Verify settled saved Settings,
matching startup backend, engine availability and preservation afterward; retain originally stopped containers
and other settings/data. Do not repeat this already completed host change, auto-toggle backends, edit private
settings or restart shared Docker to recover ordinary cleanup. A different backend/virtualization transition
requires its own exact authority.

Under VirtioFS, unchanged-byte atomic replacement reproduced a write-capable VM descriptor surviving task down.
Under gRPC FUSE, three same-path atomic-replacement cycles completed normal guarded retirement, but one separate
post-down check held on an unqualified `com.docker.backend` directory reader before a later fresh removal passed.
No write-capable descriptor was sampled in those cycles; this does **not** guarantee immediate release or prevent
all future retention. Mixed upstream reports also preclude a universal fix claim
([#168](https://github.com/docker/desktop-feedback/issues/168),
[#218](https://github.com/docker/desktop-feedback/issues/218)).

Keep the single Docker-first retirement procedure above and every source/ref/data/hook check. After a hold,
retain source, registration and locator. Reobserve successful all-container inventory (including stopped/parent
binds), host cwd/readers/writers and exact Git/private-output/hook identity. Only qualified changed conditions
permit another normal retirement at the **original absolute path**; recheck same-path reuse as new identity.
Unknown/writable handles or incomplete visibility still hold. Elapsed time, stable bytes and a prior success are
not admission. No blind retry loop, retry queue, forced removal, writer exemption or routine shared restart.
If conditions remain unqualified, report the exact consumer/visibility prerequisite to its owner and stop.

TASK-00183 verified the shared guard on this backend. TASK-00201 moved local host operations to the wrappers;
no new supplier assent or supported per-export release contract is claimed. The old Python-only runtime-free
fixture entrypoint had no repository caller and was removed. This repository's registered application worktrees
still use the Docker-first retirement path above.

## Local QA

Use the isolated Docker environment and the focused or full project gate above for library QA.
Browser HTTPS is supplied by the framework checkout that hosts the library. Keep that application's
runtime and this repository's test services in their respective Compose projects.

## Maintainer ownership

`etc/local-tooling.json` binds local runtime setup, the gate and artifact paths. Update it whenever
those contracts change. `bin/runtime` and its `.runtime-*` files own native selection, Docker, publication and
source removal. The previous Python guards were adopted from Fight Agent OS `scripts/resource_lifecycle` at
`cbdf91562b2a5a8afb1c67cb83f24891f52015c5` (2026-11-02); the current local wrappers preserve that ownership
boundary without depending on the provider at runtime. `bin/build` owns host orchestration and
[`scripts/build_cache.php`](../../scripts/build_cache.php) owns filesystem evidence. Qualify guard changes
explicitly; no private-path dependency, symlink or automatic synchronization is installed.
Validate these tools directly with native Compose/Worktrunk and normal gates, outside product test suites.

AccessControl retains its stable PHP 8.5 security floor (minimum 8.5.12). Image refresh and native admission run without a source mount before package work; an unavailable refresh or unsupported PHP stops the command. Ordinary gate preparation retains `composer update`; `--latest` remains accepted. The library owns a CLI tooling service and requires no HTTP stack or application database.

Local development builds set native `BUILDX_NO_DEFAULT_ATTESTATIONS=1` for the build command so cached executable layers retain a stable immutable image identity instead of a timestamped attestation index. Release/publication commands retain their own policy. This does not permit publishing local images.

Use `./bin/runtime docker ps` to list all selected-checkout containers and their published ports, including plain-run tool previews. `docker compose ps` lists Compose services only.
