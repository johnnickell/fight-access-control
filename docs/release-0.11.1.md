# v0.11.1 release and integration overview

**Prepared on 2026-11-28 (UTC); not yet published.** This patch starts from the published v0.11 line and does not include unrelated `develop` changes.

## What changed

`TokenSet` now enforces its documented credential handling contract. Native PHP serialization of the object throws `LogicException`. Ordinary PHP debug output redacts the access and refresh credentials. Do not log or persist the object or its credentials; redaction does not protect values read through the explicit getters.

There are no required changes to supported getters, the browser or portable response schemas, stored session data, or the PHP and Fight Common requirements. If your application was serializing the whole object, remove that unsupported use and build the intended response from explicit getters instead. See [authentication payload profiles](openapi-composition.md).

## Evidence and consumer checks

The [original fix](https://github.com/johnnickell/fight-access-control/pull/293) was independently reviewed and tested on `develop`. Release assessment and final-commit certification are separate; this overview does not claim either has happened.

Consumers still need to check their real browser cookie delivery and attributes, their explicit portable response path where enabled, and that caches, sessions, queues, serializers and logging systems do not retain or expose credentials. Package tests do not establish these deployment facts.
