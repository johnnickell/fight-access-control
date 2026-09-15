# Fight AccessControl Project Profile

This package binds the [Fight Engineering Standards](../../docs/engineering/STANDARDS.md) to its Domain and Application package boundary.

- Production code is limited to src/Domain/AccessControl and src/Application/AccessControl, mirrored by tests.
- Consumers own production persistence adapters and runtime composition. Do not add a production Adapter layer, contracts directory, or Composer production namespace without explicit approval.
- Secret-bearing authentication remains synchronous; use established Fight Common contracts, exact coverage, and post-commit event ordering.
- Run package tooling in the isolated fight-access-control PHP container. ./bin/build is the canonical completion gate.
- Branch from develop; never commit directly to develop or main.
- This profile deliberately retains the package container workflow, exact coverage gate, and release certification as local bindings.
