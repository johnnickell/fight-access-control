# Existing Agent compatibility — retired

[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md) supersedes TASK-00055's legacy-Agent and upgrade
requirements. TASK-00068 removes that implementation, its recovery marker, adoption transition and retired rotation
stubs. Earlier TASKs and review receipts remain historical evidence, not current obligations.

Only the [current Agent contract](agent-current-contract.md) is supported. There is no old-data conversion, legacy
mode, backfill or supported cross-version rollback route. This retirement authorizes no data reset or deployment.
