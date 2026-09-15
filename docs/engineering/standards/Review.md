# Review contract

Assess the exact TASK, change, revision, and declared scope against two independent axes. Review new code and meaningfully changed methods/components for a feature/bug; include easy nearby cleanup. Report larger unrelated debt as advisory. A chore explicitly cleaning named classes/files requires those complete files to comply. Do not hide in-scope debt behind a diff-only interpretation.

## Stable criterion catalog

Before examining findings, derive acceptance checks from the TASK and attach them to the Spec criteria below. Keep these cohesive criteria as the scoring denominator; do not pad it with easy subchecks. Determine applicability explicitly and retain IDs through follow-ups.

| ID | Spec criterion | Required evidence |
|---|---|---|
| SP-01 | Intended use cases and acceptance are complete | Actor/trigger/outcome mapped to behavior, including relevant UI/API paths |
| SP-02 | Validation and input rejection are accounted for | Boundary checks, failure responses, and tests or justified exclusions |
| SP-03 | Permissions and access rejection are accounted for | Authoritative enforcement, consumer ownership, or a justified exclusion |
| SP-04 | Commands, queries, events, and side effects match the contract | Trace of state changes, response data, dispatch and external effects |
| SP-05 | Failure, compatibility, and recovery behavior satisfy requirements | Absence/error behavior, existing data/API compatibility, relevant rollback/retry semantics |
| SP-06 | Completion evidence proves the outcome | Acceptance-linked tests, published Before/After evidence or justified alternative, accessible captures/links, actual final gate state |

| ID | Standards criterion | Reference |
|---|---|---|
| ST-01 | Domain knowledge and responsibilities are well placed | Architecture; CONTEXT.md |
| ST-02 | Dependencies and use-case coordination follow project architecture | Architecture; dependency rules; DI and contracts |
| ST-03 | General naming conventions are followed | Naming |
| ST-04 | PHP code and documentation style comply | PHP; actual coding standard |
| ST-05 | Adapters and presentation preserve boundaries and safe output | HTTP; project-specific transport contracts |
| ST-06 | Frontend responsibilities, data, state, and shared services comply | Frontend |
| ST-07 | Tests meaningfully verify production behavior | Testing; coverage and collaborator suitability |
| ST-08 | Documentation is created or updated without drift | CONTEXT.md, API docs, planning, runbooks and affected public docs |
| ST-09 | Verification and delivery hygiene satisfy applicable requirements | Testing; Delivery; exact evidence and owned resources |

Only apply relevant standards. Missing runtime behavior is not an automatic failure in a documentation-only TASK. Apply Testing’s planning-only screenshot exclusion without a score deduction; assess SP-06 against the applicable planning verification instead. Explicitly requested screenshots remain required. Conversely, TASK silence about validation or permissions does not waive SP-02/SP-03: inspect the actual boundary and account for the security process. A demonstrated omission fails the criterion. Do not invent a permission policy or claim an exploit merely because the TASK forgot to mention one.

## Scoring

Each criterion is **Pass**, **Fail**, **Unverified**, or **Not applicable**, with evidence/reason. Fail needs a confirmed violation. Unverified means required proof is absent, remains applicable, and prevents completion; it is not a proven code defect. N/A requires a valid exclusion rather than missing evidence.

For each axis independently: `score = 100 × passed / (passed + failed + unverified)`. Display counts and the score; if no criteria apply, report N/A, not 100. Every applicable criterion must pass before merging, regardless of rounding, unless John explicitly authorizes the scoped review-score override below. Multiple findings keep their criterion failed until all confirmed violations are resolved. Severity orders repairs; it does not change the score or make a mandatory rule optional. Advisory suggestions do not deduct points.

A perfect score means complete compliance within this stated scope and evidence. It is not a guarantee of defect-free software. Internal implementation checks use the same criteria as a readiness checklist; formal scoring is the independent review's output.

Implementation checks and repair verification do not constitute a formal review invocation. The builder returns its changes, proof, and rebuttals in an implementing-agent handoff; it does not alter prior reviews, assign new scores, create the next formal review artifact, or declare landing readiness. Formal review requires a separately requested review operation. Generic approval of a build plan does not supply that request, even if the plan mistakenly included formal review. Verify artifact provenance before accepting scores; a builder-authored assessment remains implementation evidence rather than independent approval.

## Explicit review-score override

John may explicitly authorize landing below 100% on either review axis for a particular PR. First present the current scores, failed/unverified criteria, findings or missing evidence, and practical risks. Bind the authorization to the repository, PR, head/base revisions and accepted exceptions; these may be established by the current conversation rather than requiring John to type hashes. A general request to land, permission to support overrides, or an earlier PR's override is not approval to use one.

Record the authorization, reason if supplied, accepted risks and any agreed follow-up in the ignored review/landing handoff. Preserve actual scores and criterion states; an override does not turn Fail or Unverified into Pass, justify N/A, or imply reviewer approval. Report the outcome as landed with an explicit review-score override. Do not invent follow-up work or a reason on John's behalf.

If head/base content or findings change, reconcile the review and obtain renewed authorization before relying on the override. Reuse authorization already given for the exact reviewed state without asking again. This exception covers the review-score threshold only; it does not waive the full build gate, hosted protections, resource ownership, or separate release/deployment authorization, and does not authorize disabling or bypassing those controls.

## Prior rounds and evidence

Local ignored review handoffs are the default history for this workflow; GitHub reviews/comments are supplementary. Start with the supplied handoff and any review pasted into the current conversation. Then inspect the matching TASK's `.runs/handoffs/<task>/` and referenced `.runs/notes/` records. Because `.runs` is ignored and is not copied into a fresh worktree, check the known primary checkout's matching handoff/archive location when needed. Search by verified TASK/PR ownership, and confirm repository, head/base and round identity before treating an artifact as relevant; do not select by modification time alone.

Read earlier findings, criterion states, rebuttals and the implementing agent's repair/evidence handoff before reviewing changes. Pass this history to both reviewers. Preserve finding IDs and classify each as resolved, still confirmed, rebutted/disproved, stale or decision needed at the current head. Distinguish newly introduced findings from previously present but missed findings. A new commit invalidates reuse of old scores as current approval; it does not erase the prior round's history.

Record the prior artifact paths and reviewed revisions in the new handoff. No GitHub reviews/comments does not establish that no previous review exists. If an earlier round is known but its artifact cannot be found, name the locations checked and request its path or pasted content; continue independent review where possible, but report reconciliation as incomplete rather than inventing a fresh initial round or claiming there is nothing to reconcile.

The implementing agent owns execution of the full local pre-submit gate. The reviewer verifies the saved gate result, complete log and tested content/revision mapping, along with hosted results only where Testing’s public-repository policy requires them. Establish visibility before declaring a hosted-check gap; private-repository CI status is optional and does not make criteria Unverified or lower scores when local proof is sufficient. Do not rerun the full build merely because a review starts, a new review round begins, or the reviewer has a different environment. A verified engineer-run gate is valid review evidence; reviewer execution is not a separate requirement. Apply [Testing’s evidence-reuse rule](Testing.md#reusing-a-full-gate-result-after-documentation-only-follow-ups) before declaring an older receipt stale: an earlier full pass plus verified input equivalence and current targeted checks may satisfy the current review. A different head hash alone is not a finding or score deduction.

If evidence is missing, stale or inconsistent, name the precise gap and request the missing receipt or a needed rerun from the implementing agent in the handoff; continue other review work. Do not launch another full gate unless John explicitly requests it. Focused checks needed to reproduce or disprove a finding remain appropriate within the read-only review scope. Keep incomplete runs distinct from earlier completed evidence and hosted results; never promote a partial log to a pass or let a hosted success silently replace required local evidence.

## Adversarial findings

Before reporting or scoring a finding, attempt to disprove it against current code. Record:

- Stable finding ID, criterion, severity, current revision and exact location.
- Violated requirement and practical consequence.
- Reproduction or traced evidence; counterevidence considered.
- Feasible correction within scope and verification that would close it.
- Status: confirmed, resolved, rebutted/disproved, stale, decision needed, or advisory.

For chronology findings, apply [Delivery](Delivery.md#delivery-chronology): distinguish explicitly historical checkpoints from claims about current state. Do not deduct points because a pre-publication note lacks future commit/PR details, because a tracked file does not identify its own enclosing commit, or because later publication makes a true historical statement no longer current. Verify current state through TASK metadata, Git, hosted checks and ignored handoffs. A misleading unqualified current-state claim or stale implementation fact can still be a documentation defect; propose the smallest clarification, not a requirement to rewrite history after each push.

Architectural and documentation defects can use traced evidence without executable reproduction. Unproven suspicions remain questions; missing required evidence makes the criterion Unverified where appropriate. Incomplete product policy becomes a decision request with a recommendation, not invented implementation.

Revalidate each finding at the new head. Accept evidence-backed rebuttals after checking them; escalate actual policy disagreements to John. On follow-up, label findings previously reported, introduced by revision, or previously present but missed. Scrutinize new discoveries especially closely. For a confirmed miss, explain the earlier blind spot and propose a reusable learning when warranted. A learning becomes a rule only after John's approval, through standards governance.

## Reviewers and handoff

Use independent Spec and Standards reviewers with **GPT-5.6 Sol, high**. Each gets the exact TASK, revision/scope, relevant standards, and evidence; read-only source access, no fixes or further delegation. The coordinator adjudicates overlaps and contradictory findings against evidence. If that profile is unavailable, report it and ask for an explicit alternative; do not silently use the generic router's weaker fallback.

The review produces two score tables, confirmed findings, verified rebuttals, evidence gaps, advisories, and a copyable implementing-agent prompt. Keep artifacts in ignored run/handoff folders. A reviewer does not modify source or tracked planning records, regenerate tracked views, post external comments, commit, push, merge, or silently begin another build. Review verification may run checks within the declared read-only scope; it does not include applying repairs. Approval to exit Plan Mode or write/finish a handoff retains this boundary. A review plan must list only the review artifact files to write and verify, with repair instructions labeled as content for a future builder. Generic “Implement the plan” or “proceed” approves that artifact-writing scope, not the repairs quoted in the artifact. Re-read the active review workflow and approved scope on resumption, save the artifacts and return control. Only a specific request to fix findings/change code or invoke the build workflow changes the role; the repair prompt inside a handoff is output, not authorization to execute it. Leaving repairs uncommitted does not make them read-only review work.
