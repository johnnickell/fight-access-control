# Fight Engineering Standards

Adoption date: 2026-09-15
Baseline identity: Fight engineering baseline v1
Project binding: [AccessControl profile](../../planning/agents/project-profile.md)

The ten canonical standards below are copied into this checkout for self-contained use. Source and installed bytes were identical at initial adoption; the source remains private and its filesystem identity is intentionally not distributed.

| Standard | Source SHA-256 | Installed SHA-256 |
| --- | --- | --- |
| [Architecture](standards/Architecture.md) | `3937a128e280f93bb9eece8dea9f612f02a63fdd79bd0f7d63b41a167e190980` | `3937a128e280f93bb9eece8dea9f612f02a63fdd79bd0f7d63b41a167e190980` |
| [Delivery](standards/Delivery.md) | `f846b7dbb8977d7ef195cdbd339ce91a1632691a7664455ebdd2067de4ad925e` | `61dfcbd2ea0b34e4ab6e995c9761d752a3f74b248549a5ce48a33007c06225a1` |
| [Frontend](standards/Frontend.md) | `236d9fae3bd94af3a22b6158953979be04c0c8e388f62d56461579d58e9eb8ec` | `236d9fae3bd94af3a22b6158953979be04c0c8e388f62d56461579d58e9eb8ec` |
| [Governance](standards/Governance.md) | `b15f5ccc7804f0c3a7745aa0d7ba9f200c24f7652e81883217ae80aa7e37b81d` | `b15f5ccc7804f0c3a7745aa0d7ba9f200c24f7652e81883217ae80aa7e37b81d` |
| [HTTP](standards/HTTP.md) | `456f7161f08bdd23063bab934ba9a26fb178f0e1ad35e0d898255dd9702626c9` | `456f7161f08bdd23063bab934ba9a26fb178f0e1ad35e0d898255dd9702626c9` |
| [Naming](standards/Naming.md) | `783c67a53b62f9a1576a3a0c00a6438f1b6c40b0df84f268874689b715e74907` | `783c67a53b62f9a1576a3a0c00a6438f1b6c40b0df84f268874689b715e74907` |
| [PHP](standards/PHP.md) | `b102071e4939424796e4edc20d0b46373210634189c8f024038214e0e18cf623` | `b102071e4939424796e4edc20d0b46373210634189c8f024038214e0e18cf623` |
| [Planning](standards/Planning.md) | `616c8e3a793e200747a98b9b918ed921ac17708f4e08b0925a94bd04a79a1bd1` | `ec87830d1037e31aa2c4b3af537b1628e18ea1693da428c851ed2996a1c9777d` |
| [Review](standards/Review.md) | `46b53d075a5cae328391eb57d645404c3fb55bc76aee461def94aa123f04ea35` | `d9b77bb05bc04176f323a6c86cd1af76eb4edf76ddec3cd1df01a5ba86e4bfd5` |
| [Testing](standards/Testing.md) | `fdab5a64ba92f406d63c28d48d6fdf800397c079c29932372746caf31f2c432f` | `2e261e828a504b579d861b01f494ec7c78bdcd96493acc5114e659827af66879` |

## Link transformations

The baseline AGENTS links were rewritten from `Standards/<name>.md` to `docs/engineering/standards/<name>.md`. Package facts, commands, exceptions, and adoption gaps remain in the project profile.

## Adoption gaps

This adoption does not certify all existing code against the baseline. Its compatibility scan and deferred cleanup are preserved in the ignored adoption handoff. The package boundary, container runtime, exact-coverage gate, and release-certification procedure remain project-specific bindings.

## Project deviations

### AccessControl run layout

- **Rule:** Use purpose-named `.runs/` subdirectories as specified by the
  [project profile](../../planning/agents/project-profile.md#tests-and-delivery), including singular
  `.runs/worktree/<task-slug>/` for linked worktrees. Do not group unrelated artifact types beneath dated top-level
  run folders. Retain existing evidence; relocation or cleanup requires proved ownership and authorization.
- **Scope:** AccessControl TASK worktrees and their evidence only.
- **Rationale:** Clear subdirectories distinguish checkouts, notes, logs, handoffs and reviews while TASK names
  preserve ownership across those locations.
- **Approval/reference:** John explicitly corrected TASK-00046's location to `.runs/worktree/*` on 2026-09-27 and
  requested clear `.runs` subdirectories for future work. This supersedes the dated run layout preserved in PR #55;
  the shared Delivery standard's plural `.runs/worktrees/` spelling does not apply in this repository.

### AccessControl OpenAPI contract integration tests

- **Rule:** Test the shipped OpenAPI catalog through generated-schema PHPUnit integration tests in the default suite,
  normal CI test execution, and `./bin/build`. These are public library contract tests, not tooling meta-tests;
  the shared Testing standard's exclusion of tooling tests from default builds does not apply to them. Compare
  generated schemas with owning enums and real serialized payloads, and verify intended component coverage and
  reference resolution rather than inspecting PHP source text or freezing schema counts.
- **Scope:** Package-owned `openapi/` contract verification only. Release scripts, planning validators, wrappers,
  and other tooling retain the shared exclusion. Test-only consumer document fixtures do not authorize production
  endpoints, a package-owned document, or OpenAPI dependencies in Domain/Application.
- **Coverage:** Preserve the exact `src/` statement-coverage gate. Schema-contract integration tests may use
  `CoversNothing` to avoid attributing incidental execution to Domain/Application coverage; this does not classify
  the shipped contract as tooling or exclude its tests from the normal suite.
- **Rationale:** Consumers use these distributed schemas in production. Contract drift must fail the ordinary
  library gate, not wait for optional release qualification.
- **Approval/reference:** John explicitly required main-pipeline PHPUnit integration coverage on 2026-09-28 in
  [TASK-00060](../../planning/tasks/00060-TASK.md#approved-verification-amendment). That TASK owns implementation;
  recording the rule does not assert that the tests or suite wiring already exist.

## Optional capability-first Adapter boundary — WF-035

- **Rule:** The [project profile](../../planning/agents/project-profile.md#package-boundary-and-contracts) is the
  authority for the approved optional `src/Adapter` integration boundary and Common-style capability-first layout.
  Domain/Application retain their DDD organization and inward dependency rules.
- **Scope:** Reusable permission/framework integration only; no blanket infrastructure/persistence or all-framework
  support approval. Existing exact coverage, applicable lifecycle evidence and independent acceptance remain required.
- **Rationale:** Share reusable transport/framework glue without duplicating permission policy across consumers or
  forcing a framework-specific structure into Domain/Application.
- **Approval/reference:** John explicitly selected `src/Adapter` inside AccessControl and Common-style capability-first
  directories in [WF-035](../../planning/wayfinder/tickets/WF-035-framework-permission-adapter-ownership.md).
  This supersedes the earlier Domain/Application-only production restriction within that narrow scope, not the
  historical adoption records below. No adapters, Composer mapping or gate changes are implemented by this amendment.

## Targeted standards refresh — 2026-09-16

Planning now distinguishes unfinished, executable and attention-needed work, with truthful next-action fallbacks. Review now checks omitted states and cross-view contradictions against independent expected behavior. Only these approved clauses were applied; earlier baseline content, project bindings and exceptions remain unchanged. Installed digests above identify the resulting local documents. Source digests for the refreshed rows identify the current authoring documents; a differing installed digest reflects this selective update rather than full replacement.

## Review workflow refresh — 2026-09-26

John designated Fight Agent OS's `docs/engineering/REVIEW.md` as the new review standard. The Review source digest above identifies that file; the installed copy adapts its Adapter/transaction and full-gate references to this package's Domain/Application boundary and existing Testing policy. A single independent reviewer assesses TASK acceptance and publishes a version-3 canonical report with an `accept` or `revise` verdict; the former model-specific dual-review requirement, numeric scores, and score override no longer govern new reviews. Delivery and Testing were updated only to remove references to those retired rules. Existing scored reports remain historical evidence. This is a project-local refresh, not a claim that the older ten-document baseline was fully replaced.

## Automatic parent completion amendment — 2026-10-01

The maintainer approved [automatic parent completion](../../planning/CONVENTIONS.md#automatic-parent-completion)
as part of child completion, without another parent assessment, review, QA or confirmation. Local planning,
completion guidance and the generator now follow this rule. Child acceptance and delivery authority remain
unchanged. This is an explicit local amendment, not automatic synchronization of the remaining standards.

## Agent OS workflow alignment — 2026-10-20

Approved scope: [TASK-00081](../../planning/tasks/00081-TASK.md). Compared the installed provider's Build, Next,
Work and Writing for Agents entrypoints, target-context/execution/handoff guidance, relevant quality/QA/landing
sections and installed harness access rule against this checkout's existing adoption and local amendments.
The SHA-256 of the ordered comparison manifest (each resource's SHA-256, two spaces, provider-relative path and
newline, in that order) is `810357b69de51fb0ea3e32d05cfe4faf8961952ed40246fbe69e34fc32d17861`;
the complete manifest and comparison evidence remain in the ignored TASK handoff. This identifies inspected
content, not release certification or end-to-end workflow qualification.

Selected changes: Build-default read-only routing, explicit state-specific handoffs, invocation-versus-recommendation
authority and a focused [target binding](../../planning/agents/project-profile.md#agent-os-workflow-binding).
Root/contributor/planning entrypoints now share those owners. Only Delivery's authority/runtime applicability
and Review's continuation clauses changed among the ten standards; installed digests above reflect them.
Source columns retain their earlier baseline/refresh identities, not a claim of wholesale replacement.
The provider's skills and scripts remain provider-owned and optional; no catalog, private installation paths,
global configuration or provider application assumptions are imported.

Retained unchanged: Domain/Application-only production ownership, pre-v1 current-contract policy and security
brief requirements; exact statement coverage, canonical Docker build, documentation-only evidence reuse and
hosted-check policy; OpenAPI integration-test and singular worktree-layout exceptions; independent acceptance,
behavioral QA, automatic parent completion and human-signed library-release controls. A single invocation may
delegate multiple distinct effects, but planning approval and ordinary edits do not authorize publication, and
Build's open-PR authority does not extend to merge, release, deployment or restricted disclosure.
