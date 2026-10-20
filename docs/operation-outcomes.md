# Operation outcomes

The current package contract separates business persistence, required audit, notification and consumer presentation.
Commands and synchronous authentication methods retain their established authorization and transaction boundaries.
Queries remain read-only.

## Notifications and callers

Package-produced failure notifications use operation-specific constant text with the original safe Command or
allowlisted context. Synchronous `AuthenticationService` methods emit `RedactedCommandFailed` and never serialize
passwords or credentials as Commands. Existing expiry notifications retain their constant cleanup text. Do not parse
notification text for transport decisions or treat a notification as a durable outcome receipt.

Failure notification is best effort. A notification fault cannot replace the operation's primary throwable.
Established generic authentication rejections remain generic. **Rethrown exceptions are not sanitized by the
notification policy**: consumers must separately sanitize responses and logs and protect identity-bearing events.

## Persistence and publication

- Confirmed pre-commit failures roll back staged mutation and required audit, with no success event.
- Success events publish only after confirmed commit. A subsequent publisher or synchronous subscriber fault still
  throws; it does not undo the committed state or acknowledge success to the caller.
- Lost commit acknowledgement is not proof of rollback. Reconcile using authoritative current state under current
  authorization rather than inferring outcomes from events or blindly replaying a command.
- The sole committed-result publication-warning exception remains recoverable Agent provision and rotation. Their
  typed warning does not apply to revocation, authentication or other handlers; see
  [issuance](agent-provisioning-operations.md) and [rotation](agent-rotation-operations.md).

## Consumer composition

Failure subscribers must accept coarse notifications without requiring dependency diagnostics. Keep response/log
sanitation independent of subscriptions. Optional synchronous delivery subscribers run after issuance commits; a
worker fault may therefore propagate through the original request even though issuance is durable. Read current
[delivery status and due work](credential-delivery.md), retain exact generation identities, and keep provider retry
classification distinct from outer operation failure notification. No outbox or guaranteed event replay is supplied.

This is B1's contribution to the correction-batch integration handoff. Other approved slices remain separate work.
Package tests do not qualify real consumer transaction connections, rollback/uncertain commits, worker restarts,
provider idempotency, entry-point authorization or response/log handling. Those require consumer-owned evidence;
no consumer adoption, release or deployment is authorized by this guide.
