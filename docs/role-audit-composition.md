# Ordinary Role audit composition (Unreleased)

Inject the general audit repository into the three existing Role handlers and use ordinary
`TransactionalUnitOfWork`. No business-aware transaction decorator, returned-Event inspection, repository
interception or post-commit evidence subscriber is needed. This guide demonstrates package composition;
**actual consumer mapping, schema, connections, locks, processes and access remain unqualified here**.

## Bind the real writers

The consumer constructs its concrete Role, User and audit repositories and ordinary UoW with the **same native
connection and enclosing transaction**. For an ORM, sharing a connection name, database URL or active flag is
insufficient: verify the actual underlying connection, flush participation and transaction lifetime. An audit
adapter must neither open another connection nor begin/commit its own transaction. Removal's User reference
checks and every reference writer must participate in the same transaction-duration fences.

The following constructor calls use current public signatures. The variables are already constructed
consumer-owned adapters; AccessControl ships no PostgreSQL/DBAL/ORM persistence implementation:

```php
$create = new CreateCustomRoleHandler(
    $roleRepository, $clock, $unitOfWork, $eventDispatcher, $auditEvidenceRepository
);
$rename = new RenameCustomRoleHandler(
    $roleRepository, $clock, $unitOfWork, $eventDispatcher, $auditEvidenceRepository
);
$remove = new RemoveCustomRoleHandler(
    $roleRepository, $userRepository, $clock, $unitOfWork, $eventDispatcher, $auditEvidenceRepository
);

$router->registerHandler($create::commandRegistration(), $create);
$router->registerHandler($rename::commandRegistration(), $rename);
$router->registerHandler($remove::commandRegistration(), $remove);
$commandBus = new RoutingCommandBus($router);

$commandBus->execute(new CreateCustomRole($actorId, $roleId, RoleName::fromString('ROLE_SUPPORT')));
// Keep the revision from the state actually displayed, not a later substituted repository read.
$commandBus->execute(new RenameCustomRole(
    $actorId, $roleId, RoleName::fromString('ROLE_HELP_DESK'), $displayedRevision
));
$commandBus->execute(new RemoveCustomRole($actorId, $roleId, $confirmedRevision));
```

Here the handler imports are `Fight\AccessControl\Application\AccessControl\Role\CommandHandler`, the Commands
are under `Fight\AccessControl\Domain\AccessControl\Role\Command`, and `RoleName` is under the Domain Role owner.
`RoutingCommandBus` is Common's `Adapter\Messaging\Command\Sync\RoutingCommandBus`; the test binding uses
`Adapter\Messaging\Command\Sync\Routing\InMemoryCommandRouter`. Production callers choose their supported router.
The snippets are separate admitted requests, not a blind create/rename/remove script: obtain current authorization,
displayed state and destructive confirmation for each. The required originating actor is a `UserId`, even with
omitted/null audit. Attribution and a fresh revision grant no authority. Preserve all entry, target/tenant,
last-admin, reserved/managed identity, name, Permission tier, User-reference and pending-registration policy.

Omitting the last argument or explicitly passing null intentionally disables durable audit for that owner only.
It does not disable any business guard, and makes no durability promise. A supplied repository's failure is never
opt-out. Later enabling audit does not backfill history. Direct/bypass repository writes are consumer-owned.

## Mapping and schema obligations

Implement `AuditEvidenceRepository::add(AuditEvidence): void` as a required staged insert when enabled. Preserve
the complete current `toArray()` representation and validate through the current `fromArray()` contract when
reading. A normalized schema or lossless structured column is a consumer choice, not a prescribed SQL migration.

| Field | Required mapping and protection |
| --- | --- |
| `id` | Preserve the generated unique non-nil `AuditEvidenceId`; enforce uniqueness. Hydration restores it, never regenerates it. It is not a command idempotency key. |
| `actor.kind`, `actor.id` | Preserve both discriminator and UUID for User, Agent, System and Anonymous. System and Anonymous both use nil UUID text but are different kinds; User/Agent text can also coincide. Never infer kind from text or require fabricated system/anonymous account rows. Role lifecycle Commands retain their existing User actor restriction. |
| `subject.kind`, `subject.id` | Preserve the typed User, Agent, Role, Permission or Feature identity independently of actor. A Role subject must not be stored as a User row or untyped UUID. |
| `action` | Preserve the admitted package action with its compatible subject/facts, not arbitrary caller text or an inferred Event class. Unsupported actions/shapes must reject, never silently discard or downgrade evidence. |
| `occurred_at` | Preserve the trusted outcome time exactly, including microseconds and offset (`Y-m-d\TH:i:s.uP`). Do not substitute envelope creation, insertion time or a database default. |
| `facts` | Preserve every admitted action-specific field, nullable state, enum, counter and relationship. Preserve complete Role incarnation plus sequence, not just the sequence or timestamp. Preserve actual User/session/grant/operation/assignment revisions according to their own semantics; do not invent a universal revision. |
| Typed relationships | Preserve Role/Permission, grant family and ID, delivery/session/credential identities, retained predecessor and other applicable relationships in their typed fact shape. Absent and populated values must round-trip without loss. A UUID alone cannot recover grant family. |
| `correlation` | Preserve explicit null, actual message identity and/or the complete retained scoped operation key. Namespace/caller scope must not be dropped or parsed into provenance. It grants neither authorization nor universal deduplication. |
| Bounded facts and access | Admit only the current typed, action-specific facts; no raw message/entity, secret, hash, ciphertext, exception or diagnostic context. A string/regex is not a free-text sanitizer. Consumer field admission, read access, retention and response/log sanitation remain required. |

The complete current shapes are described in [typed audit evidence](audit-evidence.md), with reusable whole-payload
storage assertions in [audit conformance](audit-transaction-conformance.md). General adapters must support every
shape they advertise, including newer family facts; the representative conformance corpus is not exhaustive writer
qualification. Reject unsupported evidence before successful completion rather than accepting a no-op repository.
These are current pre-v1 breaking persisted/constructor contracts under [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md):
update current callers and mapping directly. No actor-string reader, old-constructor facade, alias, compatibility
mode, migration/backfill machinery or release-version selection is supplied.

## Role outcomes and executable proof

| Path | Authoritative evidence and observation |
| --- | --- |
| Create | One `ROLE_CREATED`, typed User actor/Role subject, fresh evidence identity, exact trusted creation time/message correlation, null predecessor and the actual initial incarnation at sequence one. |
| Rename | One `ROLE_RENAMED` with the actual before/after incarnation/revisions. The modeled Role reopens with the actual new name/time; names and entity snapshots are deliberately not audit facts. Stale/ABA/recreated intent rejects; the original expected revision is never replaced automatically. |
| Same name | Validate expected revision and complete current-state fence first. No mutation, revision/time movement, success Event or evidence, even when configured audit would fail. |
| Remove | One `ROLE_REMOVED` retaining the authoritative predecessor, no fabricated successor. Current User/pending-registration references and final expected-state/reference fences still protect removal. |
| Audit failure / final concurrent loss | Both staged Role and mapped evidence roll back; a distinct failure-notification fault cannot replace the primary fault. A separately committed pre-CAS winner survives the loser. |
| Lost commit acknowledgement | Both committed and uncommitted modeled outcomes throw. Fresh observations distinguish them; the throw alone proves neither success nor rollback. Reconcile under current authority, never blindly replay stale intent. |
| Publication failure | The ordinary success publisher can throw after commit; Role/evidence remain committed and the original fault still throws even if failure notification also fails. No issuance-style warning return, new outbox or automatic retry is introduced. |

[RoleAuditCompositionTest](../tests/Application/AccessControl/Role/CommandHandler/RoleAuditCompositionTest.php)
uses the existing [Role lifecycle fixture](../tests/Application/AccessControl/Role/CommandHandler/RoleLifecycleAuditFixture.php),
real registered handlers/Common routing, ordinary UoW and Role/User/reference repositories. It opts into the reusable
[MappedAuditEvidenceRepository](../tests/Application/AccessControl/Audit/Repository/MappedAuditEvidenceRepository.php),
reuses the audit conformance rollback/committed fault cases, and reconstructs complete Role observations from modeled
bytes after completion, including failures. Audit reads rehydrate independently from inserted bytes. No returned
Event supplies evidence and no helper duplicates production evidence factories/insertion.

Run the owning Role tests together with the reusable audit transaction/storage suites. The latter retain independent
wrong-active-transaction controls (orphaned/missing evidence) and all-kind mapping checks. Role lifecycle/revision/
reference suites retain ABA, stale intent, managed/name/reserved guards and both registration-reference winner orders:

```sh
./bin/exec php vendor/bin/phpunit --fail-on-risky --fail-on-skipped --no-coverage \
  tests/Application/AccessControl/Role tests/Application/AccessControl/Audit
./bin/build
```

These are modeled package checks, not real SQL isolation, connection reopening, physical process restart, production
adapter compatibility or consumer authorization certification. Normal full-gate evidence and minimum/ordinary
dependency results belong to the TASK Work handoff; independent Review and nonvisual QA remain separate.

## Before retiring the Agent OS workaround

Retain `RoleMutationUnitOfWork`, `RoleMutationAudit` and `PostgresRoleMutationAudit` until the consumer owner has:

1. Implemented and qualified the **general** audit adapter/schema mapping above for the current package contract.
   Do not presume the present `PostgresAuditEvidenceRepository` understands Role subjects, typed actors, evidence
   identity, occurrence time or complete facts merely because its class name matches the port.
2. Bound all three actual handlers to the ordinary UoW and upgraded audit repository on the real shared connection,
   including User/reference checks and every writer's transaction-duration locks/fences. Verified originating User
   provenance and existing entry/target/reference policy must remain intact for direct, queued and no-op calls.
3. Run actual-adapter storage conformance, shared-connection commit and audit-before/after rollback checks, both
   concurrent winner orders (including registration/reference admission), no-change/stale/ABA/recreation checks,
   both uncertain-commit outcomes and committed publication/double-notification failure. Observe through independent
   real sessions/processes, including restart; an active flag or this package's modeled checkpoint is insufficient.
4. Qualified consumer evidence read/access/retention, safe errors/logs and exact displayed-token propagation with
   refresh/reconfirmation on conflict. Retained historical releases/acceptance are not new adapter qualification.
5. Obtained separately owned consumer change/adoption and any schema/data/deployment authority, then removed the
   workaround without also removing policy, adding an independent commit, duplicating records or weakening fences.

**External gap:** none of those actual Agent OS database/bootstrap/process/access checks or operations is performed
by this package TASK. They are retirement prerequisites, not a hidden requirement to implement a sibling repository
before truthful package composition/guidance can be accepted. This example authorizes no workaround deletion,
consumer edit, schema/data operation, adoption, release or deployment.
