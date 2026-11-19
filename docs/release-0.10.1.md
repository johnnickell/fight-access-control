# v0.10.1 release and integration overview

**Prepared; not yet published at this checkpoint.** This patch contains the credential-delivery audit fix in
[PR #265](https://github.com/johnnickell/fight-access-control/pull/265). PHP 8.5+ and Fight Common ^1.5.1 are unchanged.

## Record retryable failures that end in expiry

With audit enabled, invitation, password-reset and email-change workers now commit the expired delivery and one
correlated delivery-failed audit entry together when a retryable failure would schedule the next attempt at or
after expiry. The expired delivery has no recoverable material or retry eligibility. The audit entry records the
bounded failure classification, not raw provider diagnostics. A retry strictly before expiry remains pending.
An audit write failure still rolls back the outcome, not the prior claim.

No Command or Event signature, retry schedule, worker admission rule or package schema changes. Consumers that
validate audit records must accept `expired` with a retryable classification for the applicable delivery-failed
actions. Do not map it to a cleanup action. See [typed audit evidence](audit-evidence.md) for the allowed pairs.

## Verification and consumer obligations

Package tests use controlled providers and persistence. Consumers should verify that their audit mapping accepts
and reads back this outcome, and that the delivery and audit writes share the actual transaction. Package evidence
does not qualify a real mail provider, consumer persistence, registry installation or deployment.
