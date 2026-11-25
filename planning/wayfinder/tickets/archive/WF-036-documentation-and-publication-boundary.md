# Choose the reader-facing docs and publication boundary

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** Confirm Pages access and settings before deployment qualification; this does not block decision closure.
**Map:** [Pre-v1 documentation and developer tooling](../../archive/maps/pre-v1-maintenance-map.md)
**Depends on:** —

## Question

What belongs in the public README and documentation site, what stays as repository-only guidance, and how do we update the site on `main` while preserving release-specific instructions?

## Resolution

- Put the consumer-first documentation site source in `docs/`. Prioritize installation, first use, capability guides, and framework setup. Keep contributor standards, planning, release procedures and detailed evidence out of the published site.
- Remove the accumulation of loose notes from `docs/`; do not simply hide them from navigation. Remove `CONTEXT.historical.md` from the working tree as previously requested.
- Merge still-current consumer material into canonical guides and keep active maintainer instructions and required evidence under their repository owners. Retire superseded notes to Git history; do not create a new archive. Repair inbound links and use commit-pinned references for historical evidence where needed, including references to `CONTEXT.historical.md`.

- Publish one MkDocs site on GitHub Pages and update it automatically when changes land on `main`. Link to tagged documentation for release-specific guidance; do not host a separate site per version. Validate incoming changes before merge without publishing from PRs or `develop`. Surface failed builds or deployments without replacing the last successful site.

## Evidence for the planning handoff and delivery

Use the [baseline](../../research/pre-v1-maintenance-baseline.md), an inventory of inbound links and automation consumers, and [release documentation](../../../../docs/releasing.md). Check docs configuration against actual GitHub Pages settings at delivery; distinguish proposed workflow from observed publication. Preserve tag-specific instructions and the release panel contract.

## Resolution boundary

Set audience, content-retention rules and publishing acceptance; do not write the new README, delete pages, or deploy during this decision.
