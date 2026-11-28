# v0.11.1 release and integration overview

**Published on 2026-11-28 (UTC).** This patch starts from the published v0.11 line and does not include unrelated `develop` changes.

## What changed

`TokenSet` now enforces its documented credential handling contract. Native PHP serialization of the object throws `LogicException`. Ordinary PHP debug output redacts the access and refresh credentials. Do not log or persist the object or its credentials; redaction does not protect values read through the explicit getters.

There are no required changes to supported getters, the browser or portable response schemas, stored session data, or the PHP and Fight Common requirements. If your application was serializing the whole object, remove that unsupported use and build the intended response from explicit getters instead. See [authentication payload profiles](openapi-composition.md).

## Evidence and consumer checks

Independent review and package QA accepted the release candidate. The [release merge](https://github.com/johnnickell/fight-access-control/pull/294) was freshly certified at `2fd910c7d77be645f1f33b45cb2ebc265a89ffa3`. The signed tag, [immutable GitHub release](https://github.com/johnnickell/fight-access-control/releases/tag/v0.11.1) and its attestation were verified. [Packagist](https://packagist.org/packages/johnnickell/fight-access-control#v0.11.1) source and archive references match that commit. Consumer installation was not tested.

Consumers still need to check their real browser cookie delivery and attributes, their explicit portable response path where enabled, and that caches, sessions, queues, serializers and logging systems do not retain or expose credentials. Package tests do not establish these deployment facts.
