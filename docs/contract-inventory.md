# Declaration and contract ownership inventory

Use this development tool when changing a production declaration, native/static contract, callable relationship,
namespace, capability home or shipped OpenAPI metadata. It implements TASK-00150's structural foundation, not
EPIC-00016's semantic audit or TASK-00152's final reconciliation. A structural pass never resolves pending findings,
qualifies Common or consumers, or supplies independent Review/QA.

## Required structural check

Prepare the [isolated PHP runtime](engineering/LOCAL_TOOLING.md) and ordinary Composer dependencies first.
`bin/quality` is the sole gate definition and runs `python3 scripts/contract_inventory.py` without ignored evidence
or private criteria. `nikic/php-parser` is an explicit development dependency; source is parsed, never included.
The existing syntax, style, static/dependency, Rector, default PHPUnit/OpenAPI and exact coverage gates are unchanged.

The checker recursively discovers **all** PHP files under `src` and `openapi`, including declaration-free bootstrap
files, rather than checking historical counts or a glob-expanded sample. Missing/empty/unreadable roots or files,
links, special PHP files, parse/encoding failures, partial parser output, changing inputs, duplicate declarations,
and zero/multiple primary owners fail nonzero. Links are rejected conservatively, not followed or silently skipped.

The AST records classes/interfaces/traits/enums, functions/methods, properties/constants/cases, promoted/native
parameters, property hooks, closures/arrow functions/captures, Attributes, imports/trait composition, local PHPDoc
contracts and syntactic calls/construction/property relationships. Native resolved names, inheritance, flags,
parameters/results, defaults, PHPDoc and metadata are retained. Serialization/hydration methods such as fromArray
and toArray are ordinary named method boundaries; their semantic round trips still need owner evidence.

Named types/functions use namespace-qualified identity; methods and parameters use their owning identity.
Anonymous expressions use enclosing boundary, AST kind/target and deterministic occurrence order, not line numbers.
Moving a named declaration preserves its identity and changes its location. Namespace renames are removals/additions;
anonymous identities may shift when preceding equivalent expressions are inserted. Source positions aid inspection
but do not define identity. Calls are syntactic hints, **not** resolved dynamic dispatch, complete caller analysis,
callback execution proof or inferred PHPStan types. Owners must trace actual callers, including dynamically composed
ones, tests and consumer examples. File hashes invalidate judgments even for implementation-only changes.

## Ownership and deliberate drift reconciliation

[contract-owners.json](../etc/contract-owners.json) routes current families to the existing correction TASK/TICKET
owners. Exactly one primary correction/edit owner applies to each file and its boundaries. Separate dispatch
concerns retain TICKET-00038 ownership without taking over Domain, query semantics or presentation edits. Actual
relocations and Agent Command conversion are reflected; old planning paths/counts are not permanent assertions.
This is a compact routing projection of [TICKET-00042](../planning/tickets/00042-TICKET.md), not another architecture
standard, backlog, acceptance ledger or delivery queue. Consult owning requirements before changing its rules.

[contract-inventory.json](../etc/contract-inventory.json) stores only per-primary-owner boundary counts and canonical
SHA-256 fingerprints of identities, locations, contracts and owners/concerns. It intentionally has no historical
acceptance rows. A new, removed, relocated or contract-changed boundary causes drift even if a broad family rule
already assigns its owner. A fresh checkout can check the tracked fingerprints without another checkout's `.runs`.

For a TASK-owned change, retain a complete before inventory **before editing**, then inspect after drift:

```sh
./bin/exec python3 scripts/contract_inventory.py --output .runs/tasks/TASK-00150/runs/before.json
# Make only your authorized changes; substitute your actual TASK ID in evidence paths.
./bin/exec python3 scripts/contract_inventory.py --previous .runs/tasks/TASK-00150/runs/before.json \
  --output .runs/tasks/TASK-00150/runs/after.json
```

The second command exits nonzero on structural drift but retains complete discovery plus added/removed/moved/
changed IDs and affected owners. Discovery errors instead replace the requested output with an explicit incomplete
diagnostic, never leave an earlier successful output looking current. Without a before artifact the compact
fingerprints identify affected owners, not every historical ID; regenerate earlier content from Git if needed.

Resolve new/ambiguous ownership with the actual correction owner, inspect the detailed difference and changed
source, then explicitly refresh the compact fingerprints and inspect their Git diff:

```sh
./bin/exec python3 scripts/contract_inventory.py --refresh --output .runs/tasks/TASK-00150/runs/current.json
./bin/exec python3 scripts/contract_inventory.py
```

Refresh acknowledges structural changes only. It does not make a correction, validate supplied evidence or accept
a semantic contract. Commit the compact configuration/fingerprints with the authorized source change. Never refresh
merely to hide an unexplained omission. Other correction slices need not wait for this tool to produce their fragments.

## Candidate evidence and owner judgments

`--output` writes deterministic detailed JSON under ignored TASK evidence. It includes the observed Git head,
production file SHA-256 map, configuration/tool hashes, resolved Composer lock/installed-package identities and a
combined `candidate` hash. The hash, not a HEAD label alone, identifies tested content; precommit snapshots need a
Git mapping in the handoff. Output timestamps are deliberately absent. Records include parent/location, actual
native/static declaration, primary owner, secondary concerns and explicit pending judgment slots for every concern.
All initial slots remain pending. No count or discovery result means semantic review is complete.

Correction owners may supply `--judgments PATH` containing a JSON object with the exact `candidate` hash and a
`records` list. Each record names `boundary` (its exact ID), `task` (primary or listed concern owner), `status`
(`pending`, `corrected` or `unchanged`), an `evidence` list of locators, and nonempty explanatory strings for:

- `actual_contract`, `callers`, `input`, `result`, `absence`, `failure`;
- `validation`, `permissions`, `secrets`, `events_effects`;
- `finding`, `disposition`, `verification`.

Explain N/A individually for type-only concerns. Corrected/unchanged assertions require evidence locators; stale
candidates, unknown boundaries/owners, duplicate records, unsupported statuses and incomplete fields fail. Merge
same-candidate owner fragments into one records list before submission. Omitted entries stay pending; the checker
never converts them to unchanged, evaluates linked proof, awards independent acceptance or marks the overall audit
complete. TASK-00152 owns final integrated reconciliation, not this structural foundation.

Keep detailed inventories, judgments and diagnostics in ignored task-owned evidence. This development foundation
requires no correction-batch brief because it supplies no production semantic judgment. Actual correction/review
owners must obtain their applicable authorized private criteria before judging affected contracts. Public absence
is not evidence of a missing brief. Never copy restricted criteria, credentials, runtime secrets or private findings
into tracked configuration, public logs or PR prose. Review detailed artifacts before any authorized disclosure.

## Explicit tooling qualification

Run this separately when changing discovery/reconciliation behavior:

```sh
./bin/exec python3 scripts/qualify_contract_inventory.py
```

It exercises real PHP AST parsing and command exit/output behavior against isolated generated source/metadata
fixtures, including declarations, callbacks, drift, ownership ambiguity, incomplete inputs and judgment staleness.
It is not part of default PHPUnit, product coverage or ordinary CI. Required structural checking still runs in
`bin/quality`. Keep complete qualification logs/exits and the canonical `./bin/build` receipt with the TASK handoff;
nonvisual executable scenarios are useful evidence here, whereas screenshots add no behavioral proof.
