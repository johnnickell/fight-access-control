# Releasing Fight AccessControl

Use this runbook when preparing or resuming a package release. It supplements the
[signed-library controls](engineering/standards/Delivery.md#signed-library-releases), not a second release runner.
John must approve the exact version before release mutations. A recommendation, planning completion or successful
check is not signing, publication or consumer deployment authority.

## Select the version

Check the actual latest GitHub release, signed tags, Packagist projection and `CHANGELOG.md` before proposing a version.
A missing network/authentication result means unknown, not unpublished. `composer.json` deliberately omits `version`:
Composer derives the package version from Git tags. Use `X.Y.Z` for the tooling and `vX.Y.Z` for signed tags.

For the pre-v1 line, required changes to consumer constructors, repository interfaces, payloads or persisted state
belong in a new minor, not a patch. Do not infer v1.0.0 readiness from a completed Board; it requires the separate
stability decision. Dependency constraints and schema/operation protocol versions have their own contracts and are
not package-version fields.

## Version inventory

Update these surfaces together on the approved release branch; preserve historical evidence and released entries.

| Surface | Preparation | After verified publication |
| --- | --- | --- |
| `README.md` release block | Set release label/tag URL, `~X.Y.Z` install constraint, overview path and tagged-docs URL to the candidate. Set status to `prepared (not yet published)`; explain that installation awaits publication. Keep PHP/Common requirements aligned with `composer.json`. | Change status to `published` only after verifying the remote release and registry projection. |
| `README.md` development summary | Remove or reframe only the changes actually included in this release; retain genuinely unreleased work. | Keep branch documentation distinct from tagged documentation. |
| `CHANGELOG.md` | Move selected Unreleased changes into one first `## [X.Y.Z] - YYYY-MM-DD` section; retain an Unreleased heading. Update `[Unreleased]` to compare `vX.Y.Z...HEAD` and add `[X.Y.Z]` comparing the preceding release to the new tag. | Preserve the released notes; do not rewrite historical contracts to match newer APIs. |
| `docs/release-X.Y.Z.md` | Create the overview with `# vX.Y.Z release and integration overview`, scope, breaking integration requirements, evidence and unexecuted consumer obligations. Clearly call it preparation, not publication. | Record verified tag/commit, hosted publication and registry identities without claiming consumer qualification. |
| `planning/ROADMAP.md` and `CONTEXT.md` | Update the current release handoff and selected version, not historical checkpoints. Avoid calling the candidate the latest published release. | Update the current publication/handoff and Roadmap's Released table; remove obsolete merge/sign/publish instructions once independently verified. |
| Affected capability guides | Distinguish newly released behavior from pending development; preserve version-of-introduction labels and historical examples. | Link the correct release overview where the publication fact matters. |
| Git tag, GitHub release and Packagist | Prepare the same version identity and exact candidate. Do not add `composer.json.version` or commit the ignored lockfile. | Verify signed `vX.Y.Z`, the remote peeled commit, GitHub tag/title/notes and Packagist source/dist references agree. |

The README's `<!-- release:start -->` / `<!-- release:end -->` block is the bounded current-version panel.
Do not put historical release summaries in it. Both `published` and `prepared (not yet published)` are valid local
metadata states: the checker validates consistency, **not the truth of publication**. During preparation the previous
release remains the latest published version; an explicit candidate panel does not change that fact.

Before committing, search the tracked tree for the old and candidate versions with `git grep -n -F -- '<version>'`
(substitute each actual version). Classify each hit as a current release pointer, historical evidence, an introduction
label, or an independent dependency/protocol version. Update current pointers and stale instructions only. Check new
untracked release files in `git status` as well. Never globally replace every old version string.

## Check and certify

From the selected checkout, substitute the approved version:

```bash
./bin/release check-docs <version>
./bin/planning-check
./bin/documentation-check
git diff --check
```

`check-docs` is read-only, works on an uncommitted tree and requires Python 3 but not Docker or publication access.
It rejects a mismatched README panel/install command/runtime requirement, missing or invalid dated Changelog entry,
a candidate that is not the first release, stale comparison links, a missing/wrong-titled overview and a hardcoded
Composer version. It does not decide release scope, validate arbitrary prose, query hosting/Packagist or certify
consumer adapters. The inventory and manual search above cover semantic references beyond its bounded fields.

When changing the release tooling, run its separate qualification explicitly:

```bash
python3 scripts/qualify_release_docs.py
```

This tooling qualification is not part of product PHPUnit, ordinary CI or `./bin/build`. It uses disposable fixtures
and covers metadata failures, no-write behavior and early certification rejection; it is not release certification.
Implementation/build-input changes still require the normal full build and independent Review/QA.

After independent release-content acceptance and authorized integration, run on the **exact clean main release
merge commit**, not merely a tree-equivalent earlier candidate:

```bash
./bin/release certify <version>
```

Certification checks current version metadata first, then planning, the full package build and OpenAPI consumer
composition. It records `release-docs.log`, the other logs and `certification.json` under
`.runs/release-<version>-<commit>/`. An existing evidence directory stops a retry: preserve failed attempts and move
only the proven-owned failed directory to a purpose-named ignored archive before a separately identified rerun.
Never overwrite a successful receipt or infer a successful run from a partial log. Certification makes no commit,
merge, tag, push or publication and cannot replace independent acceptance.

## Publish and reconcile

1. Confirm exact-version signoff, accepted content, final-commit certification, signer and authority for each remaining
   effect. Prepare the human's concrete interactive signing command; never request a passphrase in chat.
2. Verify the human-signed annotated `vX.Y.Z` tag targets the certified main release merge. Push only within existing
   authority; never move or recreate a published tag.
3. Verify the remote tag/peeled commit, GitHub release and Packagist version/source/dist references independently.
   Publication notes come from the matching Changelog/overview, not unrelated Unreleased entries.
4. In an authorized documentation follow-up, reconcile the README's publication status, release overview, Roadmap
   and Context with the observed facts. The signed tag retains its truthful pre-publication snapshot; do not retag
   merely to change a status sentence. Re-run `check-docs` and documentation/planning checks on the follow-up.
5. Complete authorized main-to-develop merge-back and owned branch cleanup, preserving release evidence. Record the
   actual outcomes in the release ledger; do not leave completed actions as current handoffs.

If any stage is blocked or unknown, report that stage and exact recovery input. Package publication does not enable
consumer registration, install adapters, qualify a production environment or authorize deployment.
