# OpenAPI composition

Fight AccessControl provides only reusable value schemas. It does not provide an
OpenAPI document, paths, routes, status codes, cookies, error envelopes, security
schemes, or a documentation UI. Install the suggested generator in the consuming
application, then scan this package's opt-in bootstrap with the consumer's own
anchors:

```php
require __DIR__.'/vendor/johnnickell/fight-access-control/openapi/bootstrap.php';
```

For a disposable local proof, create a consumer anchor containing `#[OA\OpenApi]`
and one consumer schema, require the bootstrap above, and scan both files:

```bash
vendor/bin/openapi --bootstrap consumer.php consumer.php vendor/johnnickell/fight-access-control/openapi -o openapi.json
```

For this disposable proof, name the consumer schema `Consumer`. Then inspect the
generated document, including that consumer component and the following
representative package shapes:

```bash
php -r '$schemas = json_decode(file_get_contents("openapi.json"), true, 512, JSON_THROW_ON_ERROR)["components"]["schemas"]; $paginationOrderings = $schemas["Fight.AccessControl.PaginationRequest"]["properties"]["orderings"]; $sessionOrderings = $schemas["Fight.AccessControl.ListActiveSessions"]["properties"]["orderings"]; $emptySuccessData = $schemas["Fight.AccessControl.JSend.Success.Empty"]["properties"]["data"]; $isOrderingMap = static fn (array $schema): bool => $schema["type"] === "object" && $schema["additionalProperties"]["type"] === "string" && $schema["additionalProperties"]["enum"] === ["ASC", "DESC"]; $acceptsEmptySuccessData = static fn (mixed $value): bool => $emptySuccessData["type"] === ["null"] && $value === null; $valid = isset($schemas["Consumer"]) && $schemas["Fight.AccessControl.Authentication.ActivateRequest"]["required"] === ["user_id", "activation_credential", "plain_password"] && $schemas["Fight.AccessControl.Authentication.ActivateRequest"]["properties"]["remember"]["type"] === "boolean" && $schemas["Fight.AccessControl.Authentication.LoginRequest"]["required"] === ["email", "plain_password"] && $schemas["Fight.AccessControl.Authentication.LoginRequest"]["properties"]["remember"]["type"] === "boolean" && !isset($schemas["Fight.AccessControl.Authentication.LoginRequest"]["properties"]["remembered"]) && $schemas["Fight.AccessControl.RestoreUser"]["properties"]["restoration_state"]["enum"] === ["pending_activation", "active", "disabled", "deleted"] && $isOrderingMap($paginationOrderings) && $isOrderingMap($sessionOrderings) && $acceptsEmptySuccessData(null) && !$acceptsEmptySuccessData("not null") && !$acceptsEmptySuccessData(1) && !$acceptsEmptySuccessData(["value"]) && !$acceptsEmptySuccessData([]); if (!$valid) { throw new RuntimeException("OpenAPI component assertion failed."); }'
```

The catalog uses canonical snake-case `toArray()` keys. UUID identifiers use
`uuid`; `*_at` values use `date-time`; list results have `page`, `per_page`,
`total_pages`, `total_records`, and typed `records`.

Use `Authentication.BrowserResponse` for browser JSON: it has no refresh token,
so the consumer may issue that credential only in an `HttpOnly` cookie. Use
`Authentication.TokenSet` for an explicit portable body-token profile; it includes
the required `refresh_token`, with no example. Request-only passwords and
credentials are write-only and intentionally have no examples.

`CreatedResource` represents `{"id":"<uuid>"}`. A mutation may instead return
its relevant safe resource. `JSend.Success.Empty` represents
`{"status":"success","data":null}` for a body-bearing response; an HTTP 204
has no body or schema. Typed JSend success envelopes are optional consumer response
choices; failures and errors remain consumer-owned.
