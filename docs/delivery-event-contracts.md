# Human delivery Event composition

TASK-00136 accepts static Common `eventRegistration()` plus accurate local payload `@var` annotations. All three
subscribers and the shared `Timing/Service/Clock` already conform; no replacement API or dispatcher is introduced.
This is not generic Event inference. Common's `EventMessage::payload()` remains statically `Payload`, and its
registration strings cannot prove the Event-to-method relationship. PHPStan fixtures explicitly retain that limit.

## Complete route and member dispositions

All paths below are relative to `src/Application/AccessControl`. Each subscriber's constructor retains the configured
`CommandBus`, static `eventRegistration()` retains the listed mappings, and each public callback accepts EventMessage,
extracts the matching locally annotated fact and returns void after `execute()` (or the documented no-delivery return).

| Owner / method | Registered fact | Dispatched Command / actor |
| --- | --- | --- |
| ActivationGrant/EventSubscriber/InvitationDeliverySubscriber::onInvitationDeliveryResent | InvitationDeliveryResent | DeliverUserInvitation / original string actor |
| ::onInvitationDeliveryRetryRequested | InvitationDeliveryRetryRequested | DeliverUserInvitation / original string actor |
| ::onPendingRegistrationRecovered | PendingRegistrationRecovered | DeliverUserInvitation / explicit UserId actor converted to string |
| ::onUserInvited | UserInvited | DeliverUserInvitation / original string actor |
| ::onUserRegistered | UserRegistered | DeliverUserInvitation / literal registration provenance |
| ::onUserRestored | UserRestored | DeliverUserInvitation / explicit UserId actor converted to string, only with a delivery ID |
| PasswordResetGrant/EventSubscriber/PasswordResetDeliverySubscriber::onPasswordResetRequested | PasswordResetRequested | DeliverPasswordReset / literal anonymous provenance |
| EmailChangeGrant/EventSubscriber/EmailChangeDeliverySubscriber::onEmailChangeRequested | EmailChangeRequested | DeliverEmailChange / original UserId actor |

Every command carries the exact fact's User ID and purpose-specific delivery-generation ID. Recovery uses the
successor, never the previous generation. Restoration without a delivery ID schedules nothing, including an optional
pending-state fact. User state alone does not fabricate a generation. Actor provenance grants no authority; no actor
parser, ambient identity fallback or optional-audit implementation is added. CorrectPendingInvitation remains outside
these registrations: durable discovery, not an invented immediate route, remains its recovery path.

`Timing/Service/Clock::now(): DateTimeImmutable` is unchanged. Implementers supply trusted current time at invocation;
consumers retain their own sampling, admission/lease/deadline rules. An immutable instant is not a monotonic clock,
a transaction completion deadline or caller-supplied authorization. There are no Clock properties or extra members.

## Composition and failure bounds

Register the real subscribers with Common's synchronous `SimpleEventDispatcher` and configure the three delivery
Commands on the consumer's supported CommandBus. Event metadata belongs to the received envelope. These subscribers
call `execute()` with a new Command, so Common creates a fresh command message ID/time and empty metadata; no Event
trace, timestamp or arbitrary metadata is implicitly forwarded. Business actor/generation fields are explicit payloads.
Consumers needing transport correlation own that bus composition without treating metadata as authority.

A fact is neither a grant nor a durable delivery receipt. Original issuance commits before publication. A missing
Command handler or worker failure is reported by the actual dispatcher as `EventDispatchFailed`; each failure retains
the original handler throwable, possibly another nested dispatch aggregate. Do not promise direct throwable identity
at the outermost dispatcher boundary, rollback of the original issuance, automatic replay or exactly-once delivery.
Failure reporting cannot replace the primary publication failure. Actual provider invocation still occurs outside
transactions; claim/outcome persistence and provider retry classification belong to the existing worker contracts.

`DeliverySubscriberCompositionTest` exercises every registration with decoded real EventMessages and real Common
routing, exact safe command arrays, optional no-delivery, envelope metadata separation, unrelated/unregistered routes,
missing worker configuration after durable invitation issuance and retained worker failure without replay.
`DeliveryFailureCompositionTest` additionally uses the actual dispatcher and real reset/email-change request and worker
handlers for claim, outcome and publication faults after the original commit, double-notification failure, persisted
state/audit, outside-transaction provider invocation and discoverable recovery. Its controlled repositories/providers
are package composition proof, not consumer SQL, worker-restart, provider, queue or deployment qualification.

See [worker composition](credential-delivery.md), [type fixtures](type-contracts.md) and the canonical ignored
TASK-00136 Work handoff for exact dependency lanes, full-gate evidence, warnings and complete caller inventory.
