# Fight Engineering Standards

Adoption date: 2026-09-15
Baseline identity: Fight engineering baseline v1
Project binding: [AccessControl profile](../../planning/agents/project-profile.md)

The ten canonical standards below are copied into this checkout for self-contained use. Source and installed bytes were identical at initial adoption; the source remains private and its filesystem identity is intentionally not distributed.

| Standard | Source SHA-256 | Installed SHA-256 |
| --- | --- | --- |
| [Architecture](standards/Architecture.md) | `3937a128e280f93bb9eece8dea9f612f02a63fdd79bd0f7d63b41a167e190980` | `3937a128e280f93bb9eece8dea9f612f02a63fdd79bd0f7d63b41a167e190980` |
| [Delivery](standards/Delivery.md) | `f846b7dbb8977d7ef195cdbd339ce91a1632691a7664455ebdd2067de4ad925e` | `b733e0d68e56510a01137bd2df41a82ca1f76a5c3cccf6e06b4aa434d106f632` |
| [Frontend](standards/Frontend.md) | `236d9fae3bd94af3a22b6158953979be04c0c8e388f62d56461579d58e9eb8ec` | `236d9fae3bd94af3a22b6158953979be04c0c8e388f62d56461579d58e9eb8ec` |
| [Governance](standards/Governance.md) | `b15f5ccc7804f0c3a7745aa0d7ba9f200c24f7652e81883217ae80aa7e37b81d` | `b15f5ccc7804f0c3a7745aa0d7ba9f200c24f7652e81883217ae80aa7e37b81d` |
| [HTTP](standards/HTTP.md) | `456f7161f08bdd23063bab934ba9a26fb178f0e1ad35e0d898255dd9702626c9` | `456f7161f08bdd23063bab934ba9a26fb178f0e1ad35e0d898255dd9702626c9` |
| [Naming](standards/Naming.md) | `783c67a53b62f9a1576a3a0c00a6438f1b6c40b0df84f268874689b715e74907` | `783c67a53b62f9a1576a3a0c00a6438f1b6c40b0df84f268874689b715e74907` |
| [PHP](standards/PHP.md) | `b102071e4939424796e4edc20d0b46373210634189c8f024038214e0e18cf623` | `b102071e4939424796e4edc20d0b46373210634189c8f024038214e0e18cf623` |
| [Planning](standards/Planning.md) | `616c8e3a793e200747a98b9b918ed921ac17708f4e08b0925a94bd04a79a1bd1` | `313bf61c904e9442aa38e28d6a60713fcea83a6cd8d83abbb2c6554150b062ad` |
| [Review](standards/Review.md) | `46b53d075a5cae328391eb57d645404c3fb55bc76aee461def94aa123f04ea35` | `9b04d3e6aaad45e2b5ccaa3c242d0a80430f01a4d5f0119d642d21368fd4d462` |
| [Testing](standards/Testing.md) | `fdab5a64ba92f406d63c28d48d6fdf800397c079c29932372746caf31f2c432f` | `2e261e828a504b579d861b01f494ec7c78bdcd96493acc5114e659827af66879` |

## Link transformations

The baseline AGENTS links were rewritten from `Standards/<name>.md` to `docs/engineering/standards/<name>.md`. Package facts, commands, exceptions, and adoption gaps remain in the project profile.

## Adoption gaps

This adoption does not certify all existing code against the baseline. Its compatibility scan and deferred cleanup are preserved in the ignored adoption handoff. The package’s Domain/Application boundary, container runtime, exact-coverage gate, and release-certification procedure remain project-specific bindings.

## Project deviations

### AccessControl run layout

- **Rule:** In this repository, use `.runs/<YYYY-MM-DD>-<slug>/worktree` for a TASK-owned linked worktree and retain
  that run's coordination, notes, and gate receipts beneath the same run directory. Reusable handoffs remain under
  `.runs/handoffs/<task>/`; archived evidence remains under `.runs/archive/`.
- **Scope:** AccessControl TASK worktrees and their evidence only.
- **Rationale:** This is the repository's established isolated-worktree layout and keeps the worktree with the exact
  run evidence that explains its ownership and verification.
- **Approval/reference:** Existing AccessControl `AGENTS.md` run-and-worktree contract, preserved during the
  2026-09-15 Fight guidance adoption in PR #55.

## Targeted standards refresh — 2026-09-16

Planning now distinguishes unfinished, executable and attention-needed work, with truthful next-action fallbacks. Review now checks omitted states and cross-view contradictions against independent expected behavior. Only these approved clauses were applied; earlier baseline content, project bindings and exceptions remain unchanged. Installed digests above identify the resulting local documents. Source digests for the refreshed rows identify the current authoring documents; a differing installed digest reflects this selective update rather than full replacement.

## Review workflow refresh — 2026-09-26

John designated Fight Agent OS's `docs/engineering/REVIEW.md` as the new review standard. The Review source digest above identifies that file; the installed copy adapts its Adapter/transaction and full-gate references to this package's Domain/Application boundary and existing Testing policy. A single independent reviewer assesses TASK acceptance and publishes a version-3 canonical report with an `accept` or `revise` verdict; the former model-specific dual-review requirement, numeric scores, and score override no longer govern new reviews. Delivery and Testing were updated only to remove references to those retired rules. Existing scored reports remain historical evidence. This is a project-local refresh, not a claim that the older ten-document baseline was fully replaced.
