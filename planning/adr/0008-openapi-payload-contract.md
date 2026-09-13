# ADR 0008: OpenAPI Payload Contract

- Status: accepted
- Date: 2026-09-12

## Decision

The package will publish components for every public Domain Command and Query, every safe query result, and every
synchronous `AuthenticationService` operation. Command and Query components mirror their canonical `toArray()`
fields and snake case; fields describe package values rather than HTTP location. Context-owned IDs are UUID strings,
timestamps are `date-time`, and enums expose their current serialized values.

The package publishes safe payload components, typed collections, `CreatedResource`, and optional typed JSend
success envelopes. Browser authentication responses omit the refresh credential so a consumer can set it only as an
`HttpOnly` cookie. A separate portable token-set response includes that credential for non-browser or explicit
body-token clients. Request-only secrets are `writeOnly` and have no examples. Failure, validation, status-code,
header, cookie, route, and error-mapping contracts remain consumer-owned.

## Consequences

Published schema names and shapes are public `0.x` API. Consumers can consistently compose package payloads with
their own HTTP design while retaining access to canonical value shapes. A body-bearing empty success may use
`JSend.Success.Empty`; an HTTP 204 has no body. Mutations may return the applicable safe resource, while deletion
uses empty success.

## Rejected Alternatives

A single authentication response containing the refresh credential was rejected because it prevents a browser
consumer from keeping that credential inaccessible to JavaScript. Generic JSend failure and error schemas were
rejected because their messages, status codes, and validation mapping are transport policy owned by each consumer.
