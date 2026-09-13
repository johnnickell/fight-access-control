# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Release-candidate OpenAPI component metadata for consumer-owned `v0.2.0` documents.

## [0.1.0] - 2026-09-10

### Added

- Framework-neutral User invitation, activation, authentication, refresh-session, logout, password-reset,
  password-change, email-change, account-state, and account-recovery behavior.
- Role and Permission definition, assignment, reconciliation, administrative reads, immutable authenticated User
  authority, and retry-safe desired-state authorization changes.
- Agent provisioning, credential rotation and revocation, direct Permission authority, HMAC request
  authentication, replay protection, immutable authenticated Agent authority, and secret-free diagnostics.
- One final request-scoped `SecurityContext` over distinct User or Agent principals with shared immutable
  Permission snapshots and package-owned Role and Permission checks.
- Framework-neutral behavioral conformance support, package-boundary enforcement, deterministic repository
  tooling, and exact production-statement coverage.
- Repository-local product, security, architecture, contribution, and Git Flow authority under the MIT License.

[Unreleased]: https://github.com/johnnickell/fight-access-control/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/johnnickell/fight-access-control/releases/tag/v0.1.0
