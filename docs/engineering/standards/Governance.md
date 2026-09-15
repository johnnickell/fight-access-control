# Standards governance and project adoption

Each project carries its own AGENTS.md and committed standards under `docs/engineering/standards/`, reached through relative links. Its `planning/agents/project-profile.md` contains actual commands, runtime/operator references, architecture exceptions and release/deployment procedures. Domain language remains in CONTEXT.md.

The engineering authoring source maintains the canonical baseline. Projects receive explicit reviewed copies, not symlinks or automatic live synchronization. Their AGENTS.md and shared standards contain no home-directory paths or private skill dependencies. A contributor can work from the checkout without the author's private tooling. The private skill suite reads the project's adopted copy when executing there.

Record adoption date, baseline content digest, selected documents and any deviations in `docs/engineering/STANDARDS.md`. A digest identifies content, not public release certification. On a later update, compare the prior baseline, current canonical source, and local changes; preserve project additions and review conflicts before replacing anything. Do not overwrite an exception by copying a new baseline.

Explicit user instructions govern the current task. Project-specific exceptions require a stated rule, scope, rationale and approval/reference; outside that scope the baseline applies. Existing code is evidence of current practice, not automatic permission to override an agreed standard. When tooling and preferences conflict, reconcile deliberately while keeping checks honest.

Keep a single source for each rule and links with clear load conditions. Formatters and scripts own mechanical detail that can be inspected cheaply. Standards retain preferences, examples, boundaries, and reasons that tooling cannot express. Do not grow a universal rule for every isolated review observation.

Agent-discovered review learnings remain proposals in the ignored handoff, with evidence, the missed criterion, suggested wording, scope, and expected benefit. John approves before promotion to a project convention or the canonical baseline. Open design questions remain explicit; a drafting choice is not retroactive user approval of new business policy.

When John says **“add a learning,”** that is authorization to append the agreed lesson to the target project's **AGENTS.md under `## Learnings`**. Create the heading if absent; preserve existing entries and avoid duplicates. Write a concise, actionable lesson faithful to the correction, with enough scope to prevent overgeneralization. Ask only if the intended lesson or target is unclear. This request authorizes the local AGENTS addendum, including during a normally read-only review; it does not authorize implementation repairs, canonical skill/shared-standard changes, or publication. Preserve project Learnings during later guidance adoption. An explicit stop instruction halts the write as well as other work.

Initial adoption is for private practical use. Public distribution/versioning of the suite follows successful trials. Shared project standards themselves must be suitable for repository readers and must not leak private research sources.
