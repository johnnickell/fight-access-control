# Fight engineering instructions

## Work routing

For “What's next?”, `next`, `/skill:next`, or an unqualified `/ask-matt`, go straight to the [Board](planning/tasks/BOARD.md)
before general project orientation. Honor an explicit narrower user selection. Read its current human decision and
active work; otherwise select the first executable ready TASK in Board order. Use the
[Wayfinder index](planning/wayfinder/README.md) for map status and current frontier pointers. Follow
[lightweight work routing](planning/CONVENTIONS.md#lightweight-work-routing) for validation, bounded evidence reads
and planning handoffs. Recommend and stop; never launch a workflow from navigation. Default unimplemented
approved work and confirmed implementation defects to `/skill:build TASK-NNNNN`; use `/skill:work TASK-NNNNN` for an explicit implementation-only
request. Follow the [stage routes](planning/CONVENTIONS.md#workflow-continuations) for review, QA, delivery and evidence gaps.
Historical publication checkpoints do not create current merge actions. Land verifies the exact live subject
when publishing or explicitly resumed; no persistent delivery queue or post-merge planning chore. Load execution guidance when work is invoked.
This routing path does not require reading README, architecture, full project context, task history or receiving skills.

## Project guidance

For execution or substantive planning changes, load the applicable guidance below. The read-only routing
path above takes precedence over broad orientation reads; it does not waive engineering or delivery rules.

**Scope:** these are shared engineering conventions. Read the local [AccessControl project profile](planning/agents/project-profile.md), [CONTEXT.md](CONTEXT.md), and the selected work record alongside them. All required project guidance is available in this checkout; no private skill installation is required.
Optional Fight Agent OS workflows use the [local workflow binding](planning/agents/project-profile.md#agent-os-workflow-binding),
not copied skills or provider-application assumptions.

**Orient:** read the project's AGENTS.md, `planning/agents/project-profile.md` when present, CONTEXT.md, and the selected work record. Inspect current source, Git state, and project-owned commands before relying on recorded examples. Preserve unrelated work.

**Load:** read only standards relevant to the work; builders, reviewers, and auditors use the same rules.

| Work | Read |
|---|---|
| Plan or update tracking | [Planning](docs/engineering/standards/Planning.md) and project planning conventions |
| Design or change business behavior | [Architecture](docs/engineering/standards/Architecture.md) and [Naming](docs/engineering/standards/Naming.md) |
| Write or review PHP | [PHP](docs/engineering/standards/PHP.md), project PHPCS configuration, and relevant architecture rules |
| Handle HTTP or present API data | [HTTP](docs/engineering/standards/HTTP.md) |
| Write or review React/TypeScript | [Frontend](docs/engineering/standards/Frontend.md) |
| Implement or verify behavior | [Testing](docs/engineering/standards/Testing.md) |
| Review or assess readiness | [Review](docs/engineering/standards/Review.md) |
| Worktrees, runtime, PRs, merge, release, deploy | [Delivery](docs/engineering/standards/Delivery.md) and the actual project profile/runbooks |
| Change these standards or adopt them elsewhere | [Governance](docs/engineering/standards/Governance.md) |

**Build:** one TASK normally owns one PR. Use dependency-ordered SUBTASKs for layer assignments. Features are code-first; bugs start with one failing regression test. Keep domain knowledge with its owner and runtime dependencies behind Domain/Application contracts.

**Verify:** use focused checks while iterating, then the complete project gate for implementation/build-input changes. Documentation-only follow-ups may retain prior full-gate evidence with verified input equivalence and targeted checks under the Testing standard. Cover production behavior; keep release/tooling tests separate from default builds and CI. Private repositories use a local build receipt as proof; a hosted CI pass is not required. Surface warnings and incomplete evidence. Keep CONTEXT.md and affected documentation aligned with the code.

**Authorize:** follow the current request's delivery scope. Implementation, publication, independent review, landing,
signed library release and production deployment remain distinct effects. An explicit Build invocation delegates its
work/review/QA/land sequence, including owned commits, non-force push and open-PR publication, without renewed approval
for those effects. A recommendation or planning approval does not. Missing prerequisites hold the affected action;
merge, release, deployment, unrelated work and restricted disclosure remain outside Build's authority.

**Persist:** records own durable decisions and acceptance. Ignored `.runs` subfolders own coordination, review handoffs, logs, and screenshots. Preserve useful evidence through landing and remove only resources demonstrably owned by the work.

**Resolve:** explicit user decisions govern the work. Apply documented project exceptions within their stated scope; otherwise use these standards. If configuration conflicts with an approved preference, explain and reconcile the conflict rather than weakening the gate or silently inventing an exception.

Land closes independently accepted TASKs and applicable current documentation during delivery, validates its
exact live PR/default/base/head/remote, and retires eligible owned local Git/Docker/storage resources under the
[project binding](planning/agents/project-profile.md#integrated-delivery-and-resource-retirement). Human merge
and open-PR remote-head preservation remain separate. Planning checks and view refresh stay offline; retain
historical acceptance/publication and independent reports without recurring post-merge bookkeeping.

When completing a TASK, apply [Automatic parent completion](planning/CONVENTIONS.md#automatic-parent-completion)
in the same operation; do not leave a separate parent assessment or closeout action for the user.
