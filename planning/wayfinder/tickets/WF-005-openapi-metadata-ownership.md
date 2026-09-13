# Choose OpenAPI metadata ownership and component discovery

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Map:** [OpenAPI schema components for v0.2.0](../openapi-schema-components-v0-2-0-map.md)
**Depends on:** —

## Question

How should the framework-neutral package publish PHP OpenAPI metadata so a consumer generator discovers reusable
components without turning package code into a transport adapter or forcing a package-owned OpenAPI document?

## Must decide

- The supported attribute library and version policy, including whether it is a production dependency.
- The non-production package location and loading mechanism for attributes, without importing OpenAPI into Domain
  or Application code.
- Stable component naming, namespace collision avoidance, consumer scan/discovery instructions, and the compatibility
  policy for published schema names.
- How the decision supersedes the current broad OpenAPI exclusion while retaining consumer ownership of endpoints,
  security, HTTP behavior, and document assembly.

## Decisions so far

1. **Metadata dependency is settled.** The package will use `zircote/swagger-php ^6.5` as a development dependency
   and Composer suggestion. A consumer that scans package metadata installs the generator itself.
2. **Core placement and discovery are settled.** Domain and Application code cannot import OpenAPI attributes:
   Deptrac and the package boundary allow only the bounded context, Fight Common, and PHP internals there. The
   package instead ships non-autoloaded attribute anchors and `openapi/bootstrap.php` in a root `openapi/` directory.
   Consumers scan that directory and pass its bootstrap to their own generator.
3. **Compatibility promise is settled.** Published component names and fields are public `0.x` contracts. Additive
   changes may ship in a patch release; removal, rename, type narrowing, or requiredness changes require the next
   minor `0.x` release and a changelog entry.
4. **Component identity is settled.** Component keys use the dot namespace `Fight.AccessControl.*`, such as
   `Fight.AccessControl.User` and `Fight.AccessControl.Authentication.LoginRequest`. The component key is stable
   independently of the PHP class used as the scanner anchor.

## Resolution boundary

This ticket settles component publication and discovery only. It does not choose authentication fields, response
envelopes, HTTP status codes, paths, cookies, or a documentation build.

## Resolution

The package will publish `zircote/swagger-php ^6.5` attribute anchors only in a non-autoloaded root `openapi/`
directory, accompanied by `openapi/bootstrap.php`. It will declare the generator under `require-dev` and Composer
`suggest`; a consumer installs it and composes the package directory into its own scan, for example:

```sh
vendor/bin/openapi \\
  -b vendor/johnnickell/fight-access-control/openapi/bootstrap.php \\
  app vendor/johnnickell/fight-access-control/openapi \\
  -o openapi.json
```

This supersedes the package's broad OpenAPI exclusion only for reusable component metadata. It retains the
exclusion for Domain and Application imports, routes, HTTP behavior, cookies, security schemes, root documents,
and documentation UI. A disposable `swagger-php` scan using this bootstrap produced the expected
`Fight.AccessControl.Authentication.LoginRequest` component. See
[ADR 0007](../../adr/0007-openapi-schema-metadata-distribution.md).
