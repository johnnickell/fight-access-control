# HTTP and presentation

Use Action–Domain–Responder for HTTP use cases. Keep Architecture Decision Records distinct when using the abbreviation ADR.

An Action handles one interaction, for example `src/Adapter/Http/Action/Api/User/RegisterUserAction.php`, with one public `handle(Request $request, ...routeArguments)` method returning a JSendResponse or another suitable Response. Constructor injection is expected. Include route and method, validation, permission enforcement, and OpenAPI documentation as applicable. In Symfony, use the established attributes, including `IsGranted` with custom permission strings and Fight validation attributes.

An Action passes validated input into the application through the command/query bus and passes returned data to a Responder service. Responders own shape, status, headers, JSend envelopes, templates, and transformations. They do not fetch missing business data or decide policy. Use responders even for straightforward responses; share them when contracts match rather than requiring one class per Action.

Expose explicit safe Views such as `UserView`; avoid serializing raw entities. Fractal is optional when presentation needs justify it. An adequate View does not need another transformation layer. Keep HTTP JSON snake_case and object properties camelCase with explicit boundary mapping.

```php
try {
    $userView = $this->queryBus->fetch(new GetUserById($userId));
} catch (LookupException $exception) {
    return $this->errorResponder->notFound($exception);
} catch (Throwable $exception) {
    return $this->errorResponder->serverError($exception);
}

return $this->responder->createResponse($userView);
```

The error responder owns public error presentation and responsibility for diagnostic logging. A shared logger/subscriber may implement that responsibility; avoid lost or duplicated logs. Debug-enabled development may expose exception details and traces. Production exposes only deliberately public-safe messages or sanitized errors. Default an arbitrary Throwable to a generic public message. Log diagnostic details and traces in both environments while redacting secrets. The project's public-safe exception classification and logging integration must be explicit.

## Security and other transports

Always account for permissions and validation, including rejection behavior, even if the TASK omitted them. Document justified exclusions, such as a generic library transport leaving authorization to its consumer. Do not invent permission strings or authorization policy from silence. Route guards in a browser are not server enforcement.

API, MCP, console, and scheduled adapters must honor the use case's contracts through their own transport conventions. Consult the applicable protocol and project implementation before choosing streaming or non-streaming behavior. Reuse architectural patterns without assuming JSend's wire representation belongs in another protocol. Protocol envelope support does not imply ownership of consumer business permissions.
