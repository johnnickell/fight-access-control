# Runtime and delivery

Read the current project profile, Git state, runbooks, and owning commands. Treat recorded environments and command examples as leads to verify. Existing project deviations remain explicit until deliberately migrated.

## Worktree and resource ownership

Before implementation, ask John to choose the main checkout or an isolated worktree with extra runtime resources; reuse a choice already made for this TASK. Main checkout still uses the appropriate feature/patch branch. Do not overwrite unrelated dirty work. If the selected checkout cannot safely host the work, explain the conflict and resolve the affected choice.

Use ignored `.runs/worktrees/<slug>/`, `.runs/notes/<date>-<task>/`, `.runs/handoffs/<task>/`, and `.runs/archive/`. Keep reusable handoffs/evidence when cleaning environments. Durable requirements and outcomes belong in planning records.

Record TASK, repo, branch/base/PR, checkout path, owned containers/volumes/routes, URLs, creation evidence, cleanup commands, and retention requests before allocating resources. Libraries may need no persistent HTTP runtime. Do not provision one just to satisfy a checklist.

Use the shared LocalDevelopment project's documented enrollment and worktree procedures. Integration wiring, DNS, routes, certificates and shared services belong to that project. The consuming project profile points to the operator contract; skills do not invent NGINX or `/etc/hosts` management. Verify enrollment and actual worktree support. If unavailable, record the gap and continue independent work; obtain the missing runtime decision before claiming UI/API proof. Never treat another project's successful enrollment as evidence for this one.

Keep TASK environments alive through review unless John requests otherwise. After verified landing and local synchronization, clean only proven TASK-owned resources through their owning tooling. Preserve shared services and other branches/worktrees. Ambiguous ownership blocks only that cleanup; report retained resources.

## Git and PR identity

Use merge commits, never squash/rebase merges. Prefer GitFlow:

| Work | Source → destination |
|---|---|
| Feature/unreleased fix | `feature/*` from `develop` → `develop` |
| Current-line release | `release/*` from `develop` → `main`, then merge `main` → `develop` |
| Application emergency repair | `hotfix/*` from `main`; complete according to the project's approved hotfix flow |
| Released library repair | `patch/<version>-<slug>` from the oldest affected supported line, with separately reviewed forward ports |

Libraries do not use hotfix branches. Do not commit directly to protected integration/release branches. Check branch existence and project policy before creating missing GitFlow structure.

Find an existing PR at workflow entry and immediately before PR creation. Establish unique ownership using repo, branch, TASK and recorded SUBTASK relationship, base, and remote head. A similar title alone is insufficient. Reuse the matching PR, including review repairs. If ownership is ambiguous, stop publication with the candidates and missing fact. A closed/merged PR is not silently reopened; distinguish follow-up work.

PR descriptions explain the final behavior/problem, verification and material limitations, and include the Before/After evidence section required by [Testing](Testing.md). Verify the published evidence, not just local captures. Preserve a history of first failures and later successful gates in evidence. Do not expose private reference identities, local credentials, or private research. Honor hosted branch protection when landing. Follow [Testing](Testing.md#gate-boundaries) for implementation’s local-gate boundary: builders verify publication without monitoring hosted CI; review/landing apply Testing’s visibility policy: private repositories use local proof without requiring a hosted pass; public repositories follow declared hosted requirements. Unavailable CI at a stage that requires it needs the project's explicit policy or a decision, not a fabricated pass.

Before any PR merge, require the current content's independent review with all applicable Spec and Standards criteria passing, or the explicit scoped review-score override defined in [Review](Review.md). Local verification must satisfy [Testing](Testing.md#gate-boundaries), including its documented evidence-reuse and private-repository rules. Private-repository readiness does not require successful hosted CI. Treat any contrary host enforcement as a configuration blocker under Testing, not a review failure. Release preparation does not waive this requirement. If the change or base has moved, reconcile the evidence before proceeding.

## Delivery chronology

Tracked completion notes describe implementation and verification at an explicit checkpoint, such as “At the pre-publication checkpoint…”. Statements about no commit, PR, merge or deployment must be scoped to that checkpoint when they are historical. Preserve true historical outcomes; correct unqualified claims that misleadingly describe current state. Keep durable implementation facts current rather than treating all stale prose as historical.

Record a verified PR URL in TASK metadata once it exists; never predict a PR number or require publication details before publication. Include an existing PR link before the final gate where possible. When first publication requires a follow-up metadata/view change, verify and deliver that change through normal project gates. This does not require another tracked update to describe the metadata commit itself.

After commit/push/PR creation, record the actual resulting commit, PR, publication outcome and verification references in ignored run/handoff artifacts. Use Git to verify publication; review/landing use the local receipt for private repositories and hosted checks only where the project visibility policy requires them. Builders report hosted CI as unchecked unless explicitly tasked with checking it. A pre-commit gate identifies the tested tree or content snapshot; after committing, link that evidence to the resulting commit after confirming the tested content was preserved, or document the input equivalence and targeted checks required by Testing’s documentation-only evidence-reuse rule. A tracked file must never be required to contain its own enclosing commit hash or future CI outcome. Do not create recursive bookkeeping commits merely to restate each new head. Real content changes still require applicable verification and review.

## Signed library releases

Require John's explicit signoff on the exact version before release mutations. Use `vX.Y.Z` tags. Current-line tags identify the exact release merge commit on main; maintenance tags identify the release commit on that maintenance line. Do not move/recreate a published tag.

The release workflow owns release branch integration, the human signing handoff, publication verification, merge-back into develop, and completed release-branch cleanup. Provide a concrete copyable script using the project's actual tooling that prompts interactively for signing or sudo. Never collect a passphrase/password in chat. Prepare everything possible before asking for the final human operation. Resume from verified state after the human runs it; instructions alone are not evidence that a tag was published.

For libraries ignoring the lockfile, ordinary dependency preparation uses `composer update`. A release resolver's already selected lowest/latest lane must remain intact during certification; follow its documented preserving mode rather than running a broad update over it. Product gates and release certification are separate evidence.

Library support, once adopted by the project:

| Line | Support |
|---|---|
| Current minor | Bug and security fixes |
| Immediately previous minor | Security, data-loss, critical compatibility fixes for six months after the next minor |
| Latest minor of previous major | Same limited six-month window after the new major |
| Older minors | End of life, retained read-only |

Only the latest patch of a supported minor is supported. Maintenance branches use `major.minor`, such as `1.1`. Derive lifecycle dates from actual releases and the support authority. Create a needed maintenance branch at the exact signed release commit, update support/protection records, and retire support by preserving the branch read-only. Branch existence alone does not prove current support. Do not merge an old maintenance line wholesale over newer main content.

## Production deployments

Production operations require the exact target and approved project procedure. Inspect artifact identity, timestamped release directories, configuration/secrets, backup verification, migration ordering, atomic promotion where supported, health checks, retention, and recovery commands. Prefer existing proven operator tooling over new orchestration.

Prepare a concrete deployment plan/script with expected results and abort/recovery points. Resolve whether John executes it or authorizes agent execution; do not infer that choice from asking to design a deployment. A deployment request with an already explicit execution scope need not be reconfirmed.

Before migration/promotion, verify the selected artifact and required backup/recovery readiness. After promotion, verify actual health and record outcome. A code rollback does not automatically reverse a destructive database migration. Follow the approved recovery plan; pause on an unplanned destructive restore. Keep operational qualification outside ordinary feature CI/builds, while still requiring relevant checks for the actual deployment.
