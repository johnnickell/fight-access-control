<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/fight-access-control-dark.svg">
  <img src="docs/assets/fight-access-control-light.svg" alt="FIGHT Access Control" width="440">
</picture>

# Fight AccessControl

Framework-neutral identity and authorization for PHP. Manage User accounts, Agent credentials, sessions, Roles and
Permissions while keeping control of your application's infrastructure.

[Install](#release-and-installation) · [Get started](docs/getting-started.md) · [Capabilities](docs/capabilities.md) ·
[Framework setup](docs/frameworks.md) · [Consumer docs](https://johnnickell.github.io/fight-access-control/)

## Release and installation

<!-- release:start -->
**Release: [v0.11.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.11.0)**

**Status: published.**

Requires **PHP 8.5+** and **Fight Common ^1.5.1** (resolved by Composer).
```bash
composer require johnnickell/fight-access-control:"~0.11.0"
```

[Release overview](docs/release-0.11.0.md) ·
[Tagged documentation](https://github.com/johnnickell/fight-access-control/tree/v0.11.0/docs)
<!-- release:end -->

Use documentation from the tag matching your installed version. Repository guides describe the checkout and may
include unreleased changes. The install constraint stays on this minor line. Pre-v1 minors can break API and
persistence contracts; read the [Changelog](CHANGELOG.md) and the appropriate release overview before upgrading.

## Integrate with your application

Follow [installation and first use](docs/getting-started.md) to wire a capability and choose an entry point. The
package provides business rules and optional adapters, not endpoints, a database schema, a login UI, or production
configuration. Your app owns persistence, wiring, and admission at its entry points.

## Choose a capability

Start with [Users and sessions](docs/users.md), browse [Agents, Roles, Permissions, Feature flags and OpenAPI](docs/capabilities.md),
or go straight to [framework setup](docs/frameworks.md) for Slim, Yii 3, Symfony, Laravel, or CodeIgniter 4.

### Released in v0.9.0

See the [v0.9.0 integration overview](docs/release-0.9.0.md) for atomic audit, HTTP admission, and breaking changes.

### Released in v0.8.0

See the [v0.8.0 integration overview](docs/release-0.8.0.md) for Role revisions and recovery changes.

## Development and contributions

See [Contributing](CONTRIBUTING.md) for local setup, builds, and review.

## Releases

See the [release runbook](docs/releasing.md) for version preparation and verification.

## Security and license

Report suspected vulnerabilities privately through [SECURITY.md](SECURITY.md). The package uses the [MIT License](LICENSE).
