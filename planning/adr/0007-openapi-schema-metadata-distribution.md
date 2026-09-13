# ADR 0007: OpenAPI Schema Metadata Distribution

- Status: accepted
- Date: 2026-09-12

## Decision

AccessControl will publish reusable OpenAPI schema metadata as `zircote/swagger-php ^6.5` attributes in a
non-autoloaded root `openapi/` directory. Its `openapi/bootstrap.php` loads the schema anchors for a
consumer-owned generator. The package declares the generator under `require-dev` and Composer `suggest`; consuming
projects install the generator and scan this directory alongside their own models.

Domain and Application code will not import OpenAPI classes. This preserves the dependency rules in ADR 0001 and
the absence of a package-owned transport adapter or OpenAPI root document. Component names begin with
`Fight.AccessControl.` and are public `0.x` contracts independent of their PHP anchor class names.

## Consequences

Consumer projects retain ownership of paths, HTTP behavior, cookies, security schemes, document assembly, and any
documentation UI. The package may validate its distributed attributes locally without requiring the generator in a
production install. Removing or renaming a component, narrowing a type, or making an optional field required needs
the next minor `0.x` release and a changelog entry; additive changes may ship in a patch release.

## Rejected Alternatives

Attributes in Domain or Application were rejected because they introduce an external documentation dependency into
the bounded-context layers, violating the package boundary and Deptrac rules. A standalone specification file was
rejected because the release requires consumer-scannable PHP attributes.
