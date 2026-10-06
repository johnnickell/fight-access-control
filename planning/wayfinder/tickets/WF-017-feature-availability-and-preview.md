# Define feature availability and preview semantics

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Permission-based feature flags](../permission-based-feature-flags-map.md)
**Depends on:** —

## Question

Should a feature use enabled/disabled-with-exceptions behavior, or distinguish completely off, available for
preview, and generally released?

## Must decide

- Whether disabling a feature denies everyone, including a principal holding its preview Permission.
- Whether general availability removes only the feature restriction, retaining ordinary business/action authorization.
- Whether a generally available feature can pass its availability check for an anonymous caller where the consuming
  use case otherwise permits anonymous access.
- The enum name and stable serialized values for these states.

## Required evidence

- John accepted the proposed three-state behavior and specified: “make it a BackedEnum FeatureStatus::OFF = 'off'
  FeatureStatus::PREVIEW = 'preview' and FeatureStatus::ON = 'on'”. Normal action authorization remains separate.
- Private consumer-source inspection informed the alternatives below. Detailed source provenance remains in ignored
  local planning evidence; no private source links, revision identities, or implementation analysis are published here.
  That inspection is not an executed test or qualification claim. The approved package contract is the matrix below.

## Considered options

1. **Two states (not selected):** enabled permits everyone at the feature gate; disabled permits preview-authorized
   principals. A smaller state model, but disabled is not a universal stop.
2. **Separate off / preview / on (accepted):** off denies everyone; preview requires configured Permission authority;
   on removes the preview restriction. This makes an emergency stop independent of tester membership.

Accepted matrix (availability only; all normal action checks still apply; anonymous evaluation API remains downstream):

| Caller | Off | Preview | On |
|---|---|---|---|
| User without preview Permission | Deny | Deny | Allow |
| User with preview Permission | Deny | Allow | Allow |
| Agent without preview Permission | Deny | Deny | Allow |
| Agent with preview Permission | Deny | Allow | Allow |
| Anonymous | Deny | Deny | Allow where anonymous use is otherwise allowed |

Unknown/invalid definitions and operational evaluation failures remain downstream. The recommendation is that none
can grant availability; the exact negative-result/error contract has not been accepted in this decision.

## Resolution boundary

Set the observable availability behavior only. Permission selection belongs to
[WF-018](WF-018-feature-preview-permission-binding.md). The enum name and values below are settled; other API names,
persistence, administration, environments, anonymous evaluation wiring, unknown/invalid definition behavior,
transport responses, and cache/refresh guarantees remain downstream. A semantic hard-off decision alone does not
promise instantaneous cancellation of in-flight work.

## Resolution

John accepted the three-state model and this exact PHP string-backed enum contract:

```php
enum FeatureStatus: string
{
    case OFF = 'off';
    case PREVIEW = 'preview';
    case ON = 'on';
}
```

- `OFF` denies feature availability to everyone, including preview-authorized Users and Agents.
- `PREVIEW` requires the configured preview Permission authority for either principal type.
- `ON` removes the feature restriction; ordinary business/action authorization still applies.

These three-state semantics govern the planned package capability, not an existing consumer's implementation.
Permission binding remains open in WF-018. This is a planning decision, not implementation or migration authority.
