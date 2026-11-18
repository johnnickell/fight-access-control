# Agent operation type contracts (Unreleased)

TASK-00119 audits the current Agent operation/recovery Domain boundary, including the separate
`AgentCredentialOperation` aggregate, read/write repositories, delivery/maintenance values, issuance Commands
and five Agent lifecycle Events. This is package contract correction, not Common bus-generic adoption,
consumer persistence qualification, new recovery policy or release authority. Agent identity/administration
contracts remain TASK-00118's separate boundary.

## Exact safe representations

`AgentOperationKey::toArray()` and `AgentIssuance::toArray()` expose named PHPStan payload shapes; the latter
is still the exact original cipher-associated-data representation. Issuance timestamps retain six fractional
digits and numeric offsets. Decoding rejects noncanonical representations, invalid calendars and invalid offsets.
No key, request, issuance byte, destination binding or canonical marker is rewritten. Marker **2** is unchanged.

The two issuance Commands, four Queries, delivery policy and owned Events describe their exact output fields.
`AgentOperationView::toArray()` is a discriminated union: `issuance_outcome = confirmed` carries original issuance,
marker and recorded dispositions; `indeterminate` carries null for all four. The View retains a single nullable
confirmation internally instead of independently nullable fields. Unknown markers remain representable for
rejection after authorization, not compatibility or disclosure.

```php
$payload = $view->toArray();
if ($payload['issuance_outcome'] === 'confirmed') {
    $originalAgentId = $payload['issuance']['agent_id']; // string, not a nullable or unqualified array
} else {
    // Retain the original key/request. Absence still proves neither rollback nor execution rejection.
}
```

Provision/rotation handlers still return **void**. Independent authorized outcome Queries remain the only status
interface; no returned, retained or reconstructed publication warning is added. Confirmed issuance survives
publication failure, including both publishers failing. Issuance/disposition never grants delivery, activation,
secret retrieval or use authority.

## Validation belongs to its owner

- Owned Event decoders require their declared scalar types without casts. Fact timestamps are explicit valid
  RFC3339 instants, not relative dates or inferred clocks; nonnegative credential revisions are enforced.
  Existing Event output formats/fields and publisher failure policies are unchanged. Typed failure text is not
  automatically sanitized: publishers still supply operation-specific constant evidence, and consumers sanitize logs.
- Current operation hydration rejects contradictory material/disposition/credential combinations, missing claim
  policy, claims beyond state revision, admitted deadlines beyond original pinned retention, malformed retry
  history, incompatible receipt/failure fields and impossible cleanup state. It never fills in missing material,
  policies, attempts or receipts. Persist **every** recorded field on hydration; constructor defaults describe new
  pending work, not a repair strategy for stored terminal state.
  A delivered snapshot lacking receipt evidence remains representable for the existing reconciliation-required
  outcome; it cannot recreate material or assert a newly verified acknowledgement.
- Opaque retained canonical request strings are compared at resolution, not parsed or rewritten during hydration.
  Resolution checks both exact request bytes and the original issuance relationship: destination, initial provision
  revision, or rotation target/distinct predecessor/exact successor revision. Corrupt or unrelated correlation rejects.
  Unknown markers remain opaque and reject before reinterpretation. No key can become fresh work through cleanup.
- State revisions and claim fences cannot overflow/reset. Exhausted state rejects or terminalizes through the
  existing finite-attempt path; no wrapping counter is admitted. Inconsistent negative/global capacity evidence
  is unavailable, not free capacity. Retained-key recovery remains outside new-work capacity admission.
- Cohort capabilities are validated as a list of strings before retention. Unknown version/generation or missing
  reconciliation still fails compatibility; well-shaped capability evidence is not actual composition qualification.

## Repository and caller obligations

Read repositories retain nullable authoritative lookups and typed lists of secret-free Views. Storage faults never
become absence, empty work or a zero key count. Write repositories remain separate from read ports and the UoW;
expected-state, same-connection, transaction-duration cohort/Agent/authority/slot/key fences are unchanged.
Persist exact original correlation, pinned policy, claim/admission epochs/deadlines, retry/receipt/failure history,
material references and cleanup evidence. Replacement must not rewrite canonical bindings, reset claim history,
swap delivery material outside maintenance or resurrect retired authority. The in-memory test adapter distinguishes
first claim/takeover, admission to the exact unadmitted claim, exact-attempt completion and terminalization.
A takeover advances the fence once, changes the claim token and advances the lease without clearing pinned policy;
admission cannot renew existing authority/deadline evidence. Completion retains the admitted attempt. Expiry and
attempt exhaustion retain claim history rather than reopening work. Exact current Agent/operation comparison and
transaction rollback remain mandatory. The adapter declares its exact no-argument hook callbacks; it is still a
package model, not production persistence or independent proof of real-time/database writer fencing.

Existing package issuance, delivery, receipt-first restart, scheduler-only recovery, maintenance, canonical,
cohort and restoration scenarios remain applicable. The [type fixtures](type-contracts.md) add exact positive
inference and incompatible-use checks for these Domain contracts. The [inventory](contract-inventory.md) owns
structural drift; candidate-specific exhaustive member/callback/caller dispositions and regression/gate evidence
remain in the canonical TASK Work handoff, not a public semantic-acceptance ledger.

Consumer upgrades must stop coercing Event input, hydrate complete coherent current state and use the precise
payload/list/null contracts directly. No aliases, compatibility readers, migrations or consumer edits are supplied.
Actual database races, cryptography, trusted restoration witnesses, sink ordering and activation/use authorization
remain independently required consumer qualification under the [integration guide](agent-integration.md).
