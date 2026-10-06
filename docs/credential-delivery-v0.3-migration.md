# Credential-delivery migration — retired

The former v0.2/v0.3 conversion and cutover prescriptions are superseded by
[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md). Only the
[current credential-delivery contract](credential-delivery.md) is supported. There is no historical reader, inferred
claim/outcome calling form, old transport-confirmation command or package-owned migration/backfill route.

Released changelog entries and completed TASKs retain their historical meaning. This retirement grants no authority
to reset data, upgrade a consumer or deploy. Current transaction, recovery, idempotency and stale-state safety remain.
