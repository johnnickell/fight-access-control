# Choose the host and Composer tooling split

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Pre-v1 documentation and developer tooling](../../archive/maps/pre-v1-maintenance-map.md)
**Depends on:** WF-038

## Question

Which commands should become Composer-autoloaded PHP under `scripts/`, and which need to run without Docker or installed Composer dependencies?

## Must decide

- Classify commands as host orchestration, offline/bootstrap checks, or container tools. Prefer thin host wrappers delegating to selected-container PHP where possible, without changing invocation from the host.
- Choose a dev-only Composer namespace/entrypoint pattern and justify any modern console dependency against simpler built-in PHP or existing tools. Keep tooling out of production autoloading.
- Set migration order and exceptions. Preserve CLI arguments, exit status, offline planning, worktree selection, build-cache evidence and safe teardown. Include any runner retained by WF-038.

## Approved resolution

- Keep `bin/` as the host-facing interface. Leave lifecycle, worktree selection and build-cache orchestration on the host. Keep planning validation and archiving, release docs preflight, and the existing standalone documentation link check runnable without Docker or `vendor/`. Do not require host PHP for commands that do not already require it.
- Start with the structural contract inventory: the original Python runner called a PHP AST helper; see the [current inventory procedure](../../../../docs/engineering/contract-inventory.md) and [migration inventory](../../../../docs/engineering/python-tooling-inventory.md). Follow with the [coverage report checker](../../../../scripts/check_unit_coverage.php), now run directly in the prepared PHP runtime. Port the static fixture runner after the WF-038 relocation; preserve its per-file diagnostics, timeout handling, and separate qualification. [TASK-00198](../../../tasks/archive/00198-TASK.md) owns the port. None of these ports is a reason to alter the quality gate's proof.
- Put shared tooling classes under `scripts/` in a separate dev-only PSR-4 namespace, with small PHP entrypoints loading `vendor/autoload.php`. Start without adding a console dependency. Delegate focused host commands through the existing [container wrapper](../../../../bin/.container-run); the [shared gate](../../../../bin/quality) should call the same PHP entrypoints directly in its prepared environment, whether that is the selected container or hosted CI. Do not nest Docker or prepare dependencies twice.

## Approved amendment — no Python

This supersedes the earlier Docker-free wrapper and Python exception rules. Host-facing `bin/*` commands use Docker for tooling; shell wrappers may select and manage that runtime. Programs under `scripts/*` run directly in their supplied environment, never require or invoke Docker themselves, and may use Composer. Prepared tooling must preserve offline operation where promised, CLI behavior, and authorization boundaries. Replace repository-owned Python tooling with PHP; no Python exception or required Python toolchain remains, including in consumer docs and automation. Do not introduce nested Docker in prepared environments.

## Evidence required for the planning handoff and delivery

Use [local tooling guidance](../../../../docs/engineering/LOCAL_TOOLING.md) and the [baseline](../../research/pre-v1-maintenance-baseline.md). Trace clean-checkout, CI, linked-worktree and release call paths, including use before `vendor/` exists, and identify the direct qualification each migration needs.

## Resolution boundary

Set the command boundary and priorities. Do not port scripts, add dependencies or declare all Python removable without evidence.

## Approved exception — consumer docs and Pages

The operator superseded the [no-Python amendment](#approved-amendment--no-python) only for the existing consumer docs and GitHub Pages pipeline. Keep MkDocs, its Python hook, and the existing Python/uv build and preview tooling without changing validation, publication or runtime behavior. [TICKET-00057](../../../tickets/archive/00057-TICKET.md) does not need a migration TASK for that pipeline. The three maintained diagram generators in [TASK-00202](../../../tasks/archive/00202-TASK.md) remain in PHP. The rest of the tooling decision stands.
