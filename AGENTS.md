# Fight engineering instructions

**Scope:** these are shared engineering conventions. Read the local [AccessControl project profile](planning/agents/project-profile.md), [CONTEXT.md](CONTEXT.md), and the selected work record alongside them. All referenced guidance is available in this checkout; no private skill installation is required.

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

**Authorize:** follow the current request's delivery scope. Implementation, publication, independent review, landing, signed library release, and production deployment are distinct operations. A workflow may explicitly include commit/push/PR; ordinary code edits do not imply merge, release, or deployment permission.

**Persist:** records own durable decisions and acceptance. Ignored `.runs` subfolders own coordination, review handoffs, logs, and screenshots. Preserve useful evidence through landing and remove only resources demonstrably owned by the work.

**Resolve:** explicit user decisions govern the work. Apply documented project exceptions within their stated scope; otherwise use these standards. If configuration conflicts with an approved preference, explain and reconcile the conflict rather than weakening the gate or silently inventing an exception.
