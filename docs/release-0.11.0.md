# v0.11.0 release and integration overview

**Published on 2026-11-20 (UTC).** The [immutable GitHub release](https://github.com/johnnickell/fight-access-control/releases/tag/v0.11.0)
uses the signed tag at certified `main` merge `6258682512e2c8d76a3fa655dd633167bd8f55b6`
([PR #269](https://github.com/johnnickell/fight-access-control/pull/269)). The
[Packagist](https://packagist.org/packages/johnnickell/fight-access-control#v0.11.0) source and archive references
match that commit. The tag preserves the preparation snapshot. PHP 8.5+ and Fight Common ^1.5.1 are unchanged.

## Administrative session revocation audit records require a reason

[TASK-00188](../planning/tasks/00188-TASK.md) restores durable reasons for authorized administrative revocation
when audit is enabled. The session change and audit record commit in the same transaction. The record stores the
exact trimmed explanation (1–500 characters) in `facts.reason` for `refresh_session.administratively_revoked`.
Self-service revocation, logout, replay and the success event do not include this field. Command inputs and
permission checks are unchanged. Administrators must not enter secrets; the package does not scan or redact
explanations.

This is a breaking persisted-contract change. Update audit storage, serialization and validation to round-trip
`facts.reason` for administrative revocation. The current reader rejects older administrative audit records that
lack the field. There is no package migration, backfill or historical reader. Assess retained records and your
read paths before upgrading; do not invent a reason or treat an old record as valid current evidence. See
[typed audit evidence](audit-evidence.md#admitted-facts) and the [pre-v1 policy](../planning/adr/0011-pre-v1-current-contract-only.md).

## Verification and consumer obligations

The [implementation checkpoint](../planning/tasks/00188-TASK.md#acceptance-checkpoint) records independent
technical and behavioral acceptance for the package repair. Independent release Review and QA passed before
integration; the exact `main` merge passed final certification. Before adoption, prove that your actual storage and reader
preserve the reason, that the audit write shares the session transaction, and that access to the stored explanation
is appropriately restricted. Package evidence does not qualify consumer storage, registry installation or deployment.
