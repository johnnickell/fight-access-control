---
id: TICKET-00054
epic: EPIC-00019
title: Publish and streamline consumer documentation
status: ready-for-agent
---

# Publish and streamline consumer documentation

## Problem and Outcome

Consumers need a clear path from installation to integration without reading maintainer notes. Deliver a short README and one consumer-first MkDocs site sourced from `docs/`, branded with the [official Fight logo](../../docs/assets/README.md). Match or improve on [Fight Common](https://johnnickell.github.io/fight-common/) for visual clarity and navigation.

## Use Cases and Contracts

- A consumer finds installation, first use, capabilities and framework setup from the README or site. For an installed version, tagged documentation remains reachable and the README release panel stays valid.
- A maintainer inventories pages and inbound links before consolidating current material into its owning guides. Remove superseded notes and `CONTEXT.historical.md` from the working tree after repairing links; preserve cited evidence with commit-pinned links, not a new archive. Contributor standards, planning, release procedures and detailed evidence remain repository-only.
- A docs change is validated before merge. Changes landing on `main` publish automatically through GitHub Pages; PRs and `develop` do not deploy. A failed build or deployment is visible and does not replace the last successful site. Deployment uses the existing GitHub authorization boundary; no new package Commands, Queries, Events, permissions or storage apply.

## Sequencing and exclusions

Plan this first. Coordinate links to relocated fixtures with [TICKET-00056](00056-TICKET.md). No hosted version per release, version selection, or change to release or branch policy. Actual Pages settings and deployed-commit evidence must be checked during delivery.

## Planning progress

John approved the three-TASK split: build the branded site first, then consolidate guides and enable publication as independent follow-ups. The branded site and shorter README are accepted in [TASK-00189](../tasks/00189-TASK.md). Guide consolidation and Pages publication remain open.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 512 | [TASK-00189](../tasks/00189-TASK.md) | Build the branded consumer docs site and shorten the README | done |
| 513 | [TASK-00190](../tasks/00190-TASK.md) | Consolidate current guides and retire superseded notes | done |
| 514 | [TASK-00191](../tasks/00191-TASK.md) | Validate and publish consumer docs automatically | ready-for-agent |
## Acceptance Evidence

- [ ] Render the landing page and representative guides on desktop and mobile. Compare logo legibility, contrast, code readability and install-to-integration navigation against Fight Common.
- [ ] Verify docs generation, path and anchor continuity, removal inventory, and the release panel and tagged links.
- [ ] Verify pre-merge validation and actual automatic publication from `main`, with failure and non-deploy behavior checked separately.
