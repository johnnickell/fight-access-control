# Feature scenario and evidence inventory (unreleased)

This inventory connects [EPIC-00010](../planning/epics/00010-EPIC.md) and its three TICKETs to executable package
contracts and retained acceptance checkpoints. **It is not a release or consumer qualification receipt.**
TASK-00061–00066 have their own independent technical acceptance and behavioral QA. TASK-00067 supplies guarded
retirement and the final lifecycle composition; its builder evidence still needs independent review and QA.
Automatic parent status completion does not independently certify cross-TICKET evidence or authorize deployment.

Paths below are relative to the repository. `Feature/` test names refer to
`tests/Application/AccessControl/Feature/`; Domain tests live under `tests/Domain/AccessControl/Feature/`.
Criteria P1–P8, A1–A8 and M1–M9 follow the acceptance-checklist order of
[TICKET-00015](../planning/tickets/00015-TICKET.md), [TICKET-00016](../planning/tickets/00016-TICKET.md) and
[TICKET-00017](../planning/tickets/00017-TICKET.md). E1–E7 follow the EPIC acceptance-boundary order.
An assertion reference identifies package evidence to inspect/run, not a claim that a consumer ran it.

## Requirement-to-evidence map

| Requirement | Package scenario and executable evidence | Owner / qualification boundary |
| --- | --- | --- |
| E1, P1 | Strict 1–128 ASCII name grammar, no normalization; immutable identity/name, OFF/1, required binding and unique identity/name. Domain `FeatureNameTest`, `FeatureTest`; `Feature/FeatureReferencesTest`; `Feature/CommandHandler/ProvisionFeaturesHandlerTest` identity/name conflict tests; `FeatureManagementTest` duplicate/manual creation. | TASK-00061/62/66; database uniqueness and hydration need consumer proof. |
| P2 | Native method-only/nonrepeatable `FeatureFlag`, direct calls remain unenforced; merge explicit registrations, duplicates and immutable inputs; complete empty differs from failed/incomplete/native-invalid scans; wrong scope rejects. `Feature/Attribute/FeatureFlagTest`, `Feature/FeatureReferencesTest`, `Feature/FeatureDiscoveryResultTest`. | TASK-00061; a selected-object fixture is not a production scanner or code-identity attestation. |
| E3, P3 | Provision only missing references, lazy existing default ID including protected tier, reject required missing/malformed/unresolved config, preserve all existing settings/broken bindings; no grants. `Feature/CommandHandler/ProvisionFeaturesHandlerTest`, Domain `FeatureMessagesTest`, `tests/OpenApi/FeatureComponentsTest.php`. | TASK-00062; configuration, authorization and storage adapters remain consumer-owned. |
| E3, P4 | Full candidate inventory preparation, valid OFF/PREVIEW/ON and empty, missing/broken/invalid definitions and operations distinct; no writes or default repair. `Feature/QueryHandler/ValidateFeaturePreparationHandlerTest`, `Feature/Conformance/FeaturePreparationConformance`. | TASK-00063; preparation is not availability, action authority or deployment permission. |
| E3/E7, P5, M8 (provisioning) | One transaction per pass, later insertion rollback, uniqueness/winner protection, missing-ID reference conflict, both uncertain commit outcomes, partial/both publication faults, fresh restart retry without reset or duplicate creation facts. `Feature/CommandHandler/ProvisionFeaturesHandlerTest`, `Feature/Conformance/FeaturePreparationConformance`. | TASK-00062/63; modeled persistence/restart, not real process/database concurrency. |
| E2/E6, A1–A3 | User Role-derived / Agent direct / anonymous status matrix, distinct/shared cohorts, protected human-only preview, no privileged-name bypass; ID not name matching, unknown/broken-at-every-status/malformed/operational errors; no API/snapshot alteration. `Feature/Service/FeatureAvailabilityTest`, `Feature/Conformance/FeatureAvailabilityConformance`. | TASK-00064; existing authentication/assignment rules, not a new authorization policy. |
| E6, A4–A6, M7 (freshness) | Same evaluator and principal observe later status/binding; principal lifetime unchanged; admitted work records completion after OFF without recheck, later explicit check denies; no writes, events, fallback or automatic cancellation. `Feature/Conformance/FeatureAvailabilityConformance::test_worker_reuse_and_admitted_work_require_a_new_explicit_check_to_observe_off`, availability unit matrix/read-effect tests. | TASK-00064; modeled jobs do not qualify real worker enforcement or ORM freshness. |
| E4, M5 (creation/removal) | All statuses/multiple references, sole target beyond 101 unrelated records, direct/reconciliation final guard, late reference rollback, both committed insertion/removal winner orders, losing multi-insert rollback; Role/Agent/stale/tier protections preserved. `Feature/Conformance/PermissionFeatureReferenceConformance`, `InMemoryPermissionFeatureReferenceConformanceTest`, `ManagedFeatureRemovalTest`, existing Permission/reconciliation tests. | TASK-00065; final reference fence is an actual consumer database obligation, not a paginated preflight query. |
| E1/E5, M1–M3/M8 (management), P1 | Manual OFF/1 creation, safe page/read with stored missing ID/null label/marker/revision, real status/binding edits +1, validated no-ops without write/revision/fact, stale cross-field and final-boundary rejection. `Feature/CommandHandler/FeatureManagementTest`, `Feature/Conformance/FeatureManagementConformance`; Domain `FeatureTest`. | TASK-00066; positive revision persistence, pagination, caller/target protection and UI remain consumer obligations. |
| E3/E5, M1, P3 | Real manual create → binding/status edit → provisioning with changed default preserves identity/settings/revision. `FeatureManagementTest::test_manual_creation_survives_real_provisioning_with_changed_default`. | TASK-00066; not a fixture-only preservation claim. |
| E4, M5 (rebinding) | Real rebind then old reference release/new target retained; committed removal-first rebind rejection; in-fence removal after reference retention rejects; losing transaction rolls back removal and binding. `FeatureManagementTest::test_reference_fence_rejects_conflicting_removal_and_validates_noop`, `Feature/Conformance/FeatureManagementConformance` both winner-order scenarios. | TASK-00066; sequential/in-transaction probes are not simultaneous real DB races. |
| E2/E6, M3/M7 | Same captured Agent cohort changes after real binding edits; broken PREVIEW read exposes missing ID, evaluation fails, status cannot launder it, real repair +1 then fresh evaluation allows same principal/denies anonymous. `FeatureManagementTest::test_real_binding_changes_preview_cohort_for_same_principal_snapshot`, `test_broken_binding_can_only_be_repaired_with_an_existing_identity`. | TASK-00066; no grant refresh, default substitution or consumer authorization proof. |
| E5, M4 | Leaving ON requires consumer warning/confirmation; cancellation invokes no command; stale rejection requires refresh and renewed confirmation if still leaving ON. [Management guide](feature-management.md); TASK-00066 independent QA S8 instruction walkthrough, with underlying stale-write tests. | TASK-00066; **instruction walkthrough only**, no live UI/security exercise. |
| E4/E5, M6 | Native-only, registry-only and combined references reject at all statuses; failed/incomplete/malformed/scanner-error/wrong-scope discovery cannot establish absence; remove both sources then retry. `Feature/CommandHandler/RemoveFeatureHandlerTest`; consumer-bindable `Feature/Conformance/FeatureRetirementConformance`. | TASK-00067 builder evidence; current-code identity/completeness and deployment cleanup remain consumer proof. |
| E4/E5, M2/M6 | Positive expected revision; unknown/stale ID; edit during discovery and final-write race preserve newer state; every expected-state field is compared at the final repository boundary. `RemoveFeatureHandlerTest` race tests; `FeatureRetirementConformance::test_repository_removal_compares_full_expected_state_not_just_id_and_revision`. | TASK-00067 builder evidence; no deployment fencing or name-based retargeting. |
| E3/E4, P6, M7 | **Real** management ON → declaration+registration rejection → partial cleanup rejection → full cleanup/delete → unknown evaluation without creation → new candidate registration → missing-default failure → current-default provisioning → new ID/OFF/1 → preparation true but availability false even for old audience → repeat provisioning preservation → old delete/update rejection. `RemoveFeatureHandlerTest::test_current_cleanup_and_new_candidate_reprovision_off_without_restoring_old_audience`. `FeatureRetirementConformance` additionally checks fresh manual reintroduction identity. | TASK-00067 builder evidence replaces no earlier test; TASK-00062's seeded-absence proof remains correctly labeled as limited. |
| E4, M5/M6 | Guarded Feature retirement → actual `ReconcileManagedPolicyHandler`: unreferenced Permission removes, but remaining Feature/custom Role/Agent reference still rejects. `ManagedFeatureRemovalTest::test_retirement_releases_only_its_feature_reference_for_reconciliation`. | TASK-00067 builder evidence; no grants/Permission deletion as Feature-removal side effects. |
| E5/E7, M8 (retirement) | Pre-commit write/commit rollback restores row/reference; success fact only after commit; post-commit/both publisher faults preserve deletion and original throwable, no false rollback inference. `RemoveFeatureHandlerTest` success/order, rollback/write-fault and publication tests; exact `RemoveFeature`/`FeatureRemoved` round trips and invalid required fields. | TASK-00067 builder evidence; no outbox, reliable event delivery or credential-warning exception. |
| E7, P7, A7, M9 | Portable discovery and repository/UoW contracts; reusable preparation/availability/Permission-reference/management/retirement environments and default controlled bindings; [retirement guide](feature-retirement.md) plus the five preceding Feature guides. | All TASKs; bind real disposable consumer adapters and separately execute scanner, DB, UI, security and runtime qualification. |
| E7, P8, A8, M9 | Each TASK's local full-gate receipt, exact owned-statement coverage and independent technical/QA subject, listed below. TASK-00067's current receipt/handoff belongs in its owning record; acceptance remains outstanding until independent review/QA. | Full local builds are required; hosted CI is optional/unchecked, never an invented acceptance gate. |

## Retained technical and behavioral acceptance subjects

These are **prior checkpoint results**, not fresh re-review/QA performed by TASK-00067. The canonical local
`.runs/reviews/<TASK-ID>/review.md` and `.runs/qa/<TASK-ID>/qa.md` were read during this handoff, along with the
named full-gate receipts. They are ignored local evidence, not publicly accessible URLs. The owning durable TASK
retains its counts, chronology and handoff references. All accepted implementation subjects below are ancestors
of TASK-00067's baseline `09abe1f`; later receipts/administrative records do not mean those reviews certify this
new implementation. TASK-00067's full build reruns the current product suites, not the previous independent QA.

| TASK / durable record | Accepted implementation | Retained full local gate (tests / assertions; exact statements) | Independent behavioral QA | Local gate receipt |
| --- | --- | --- | --- | --- |
| [TASK-00061](../planning/tasks/00061-TASK.md), renamed Attribute | `a8bdf75799ed598b2bc49a2b75d9b9f0e73dbc49` | 1737 / 34624; 6242/6242 | 6 scenarios / 208 checks; earlier old-name acceptance is historical | `.runs/logs/TASK-00061/feature-flag-rename/build.receipt.json` |
| [TASK-00062](../planning/tasks/00062-TASK.md) | `ff93b603e3ade05771100bca8a0ef2a3318fabed` | 1787 / 34938; 6305/6305 | 8 scenarios / 337 checks | `.runs/logs/TASK-00062/build-01.receipt.json` |
| [TASK-00063](../planning/tasks/00063-TASK.md) | `5cb1a376093ac9ae78a2325c47355aa71d710fc3` | 1802 / 35027; 6348/6348 | 5 scenarios / 26 probe checks | `.runs/logs/TASK-00063/build-03.receipt.md` |
| [TASK-00064](../planning/tasks/00064-TASK.md) | `fc1cc89fda19906237e2053b15f95bbad4e3db6f` | 1812 / 35070; 6369/6369 | 6 scenarios / 30 probe checks | `.runs/logs/TASK-00064/revision-build.receipt` |
| [TASK-00065](../planning/tasks/00065-TASK.md) | `f6438ae53173b23ebcb5802726203a73e37235d2` | 1821 / 35123; 6374/6374 | 6 scenarios / 30 probe checks | `.runs/logs/TASK-00065/build-r5.receipt` |
| [TASK-00066](../planning/tasks/00066-TASK.md) | `0eec8699e31e9fe0dc75742d8911decb225da644` | 1834 / 35275; 6586/6586 | 8 scenarios / 45 executable checks plus instruction walkthrough | `.runs/logs/TASK-00066/build-revision-4.receipt` |
| [TASK-00067](../planning/tasks/00067-TASK.md) | Builder branch `feature/task-00067-feature-retirement`; no independent acceptance yet | 1849 / 35482; 6627/6627; focused 174 / 977 | Required independent QA pending technical acceptance | `.runs/logs/TASK-00067/build-03.receipt.json`; `.runs/handoffs/TASK-00067/work.md` maps log/exit/input manifest to final commit |

## Open qualification and stop boundaries

- **Package acceptance:** TASK-00067's complete local gate passes; independent technical review and behavioral
  QA are still required. No preceding TASK report or this prose automatically accepts it. An observed missing assertion
  or required receipt must be reported as a gap, not checked off from the table.
- **Actual consumer scanning:** intended current versus candidate code, invalid/native targets and multiplicity,
  every module/registration, inaccessible/partial inventories, deployment overlap and privileged bypass handling.
- **Actual consumer persistence:** atomic unique inserts, expected-state writes including no-ops/removal, all-status
  unpaginated reference guards, shared connection/fence through completion, both overlapping writer orders,
  rollback/crash behavior and authoritative ORM reads. Package fixtures do not run those database races.
- **Actual consumer use:** authentication snapshot lifecycle, independent action/management/setup authorization
  for every entry path, transport-safe diagnostics, ON-leaving confirmation/cancellation/stale refresh, retirement
  UI and registered runtime checks/worker reuse. Instruction walkthroughs are not executed consumer UI proof.
- **Delivery:** no selected Feature release version, certification/publication, consumer adoption or deployment is
  established. Complete package acceptance remains distinct from any consumer's qualified composition. Do not
  treat an intermediate slice, a normal command return, event or prepared OFF record as rollout permission.
