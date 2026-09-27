# Review Standards

Review is an independent attempt to disprove a TASK's acceptance claims. It reports findings and evidence; implementation, publication, and merge are separate.

## Target and independence

Read the TASK, its accepted decisions, the diff against the identified base, current status, and claimed tests. Disclose whether the reviewer authored, directed, repaired, or supplied evidence for the implementation. A material contributor cannot independently accept it. Include staged, unstaged, and untracked target changes in the reviewed scope; a clean committed target is preferable for a landing handoff. No particular model, number of reviewers, or delegation tool is required.

A review of a rebased branch challenges the new base and effective diff, not just the old patch. A prior report may remain applicable through a proven mechanical reconciliation under [Delivery](Delivery.md); changed commit IDs alone neither prove a defect nor require another review. Substantive behavior changes or uncertain integration require a new independent review.

## Challenge acceptance

Map every acceptance criterion to changed behavior, tests, and fresh evidence. Inspect the actual diff and trace applicable boundaries:

- scope, exclusions, and truthful planning claims;
- dependency direction and Domain/Application ownership (and Adapter ownership where applicable);
- CQRS, validation, response mapping, and safe Views where changed;
- authorization, secrets, sensitive data, and error paths;
- success, rejection, failure, side effects, and transaction behavior where relevant;
- fresh check results, counts, warnings, skips, and limitations.

Mark inapplicable areas briefly with a reason. Test unsupported claims at the narrowest useful boundary. For the canonical gate, verify the builder's complete saved result and tested-content mapping under [Testing](Testing.md); request missing evidence rather than rerunning the full build unless John explicitly asks. Apply Testing's documentation-only evidence-reuse rule where appropriate. Do not fix implementation or change planning status while reviewing.

## Findings and verdict

A blocking finding is a traceable acceptance, correctness, security, data-integrity, scope, or evidence defect. Give severity (`critical`, `high`, `medium`, `low`), affected criterion, reproducible evidence, expected versus observed behavior, and correction. Separate non-blocking improvements from unproved residual risks. `accept` requires sufficient evidence for all TASK acceptance criteria and no blockers; otherwise `revise`. An independent review does not approve a PR or authorize merge. Older scored reviews remain historical evidence, not a requirement to produce scores or obtain multiple reviewers for new reviews.

## Durable handoff

Publish the complete report at `<base-worktree>/.runs/reviews/<TASK-ID>/review.md`; chat is only a pointer. Resolve the base via Git's common directory and registered worktrees, and keep the ignored report directory within that base without following symlinks. Existing numbered `review-<NNNNN>.md` files are historical evidence: preserve them, but do not create new sequence entries. Replace only the canonical report, using a temporary file in the same directory and atomic rename. If writing or read-back fails, report an incomplete handoff and do not return an actionable chat-only verdict.

New reports use this small header:

```yaml
---
review_handoff_version: 3
task: TASK-NNNNN
target_branch: feature/example
base_commit: <full Git OID>
head_commit: <full Git OID>
target_status: clean
verdict: accept
---
```

`target_status` is `clean` or the full `git status --porcelain=v1 --untracked-files=all` snapshot (use a YAML block scalar for multiple lines). For dirty reviews, identify each reviewed file version by path, state and digest or retained patch so a later session can detect drift; identify any selected ignored evidence even when Git status is clean. Include reviewer relationship, exact target/base, findings (or `None`), criterion-to-evidence coverage, fresh commands/results, limitations, risks, and final verdict in the body. Read the canonical report back before handoff.

Older version-2 reports remain valid handoffs; read their canonical file and verify its declared branch, base/head, status, verdict, and any recorded snapshot against the target. Keep existing immutable history untouched. Only the canonical `review.md` supplies the latest verdict; never pick an older numbered file to override it.

A reviewer does not modify implementation or tracked planning records, regenerate tracked views, post external comments, commit, push, approve, or merge. Review verification may run focused checks within the read-only scope; it does not include repairs. Repair instructions in the report are for a future builder, not authorization for the reviewer to execute them. Stop after publishing and reading back the report.
