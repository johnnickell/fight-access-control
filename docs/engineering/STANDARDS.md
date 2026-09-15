# Fight Engineering Standards

Adoption date: 2026-09-15
Baseline identity: Fight engineering baseline v1
Project binding: [AccessControl profile](../../planning/agents/project-profile.md)

The ten canonical standards below are copied into this checkout for self-contained use. Source and installed bytes are identical at adoption; the source remains private and its filesystem identity is intentionally not distributed.

| Standard | Source SHA-256 | Installed SHA-256 |
| --- | --- | --- |
| [Architecture](standards/Architecture.md) | `3937a128e280f93bb9eece8dea9f612f02a63fdd79bd0f7d63b41a167e190980` | `3937a128e280f93bb9eece8dea9f612f02a63fdd79bd0f7d63b41a167e190980` |
| [Delivery](standards/Delivery.md) | `f846b7dbb8977d7ef195cdbd339ce91a1632691a7664455ebdd2067de4ad925e` | `f846b7dbb8977d7ef195cdbd339ce91a1632691a7664455ebdd2067de4ad925e` |
| [Frontend](standards/Frontend.md) | `236d9fae3bd94af3a22b6158953979be04c0c8e388f62d56461579d58e9eb8ec` | `236d9fae3bd94af3a22b6158953979be04c0c8e388f62d56461579d58e9eb8ec` |
| [Governance](standards/Governance.md) | `b15f5ccc7804f0c3a7745aa0d7ba9f200c24f7652e81883217ae80aa7e37b81d` | `b15f5ccc7804f0c3a7745aa0d7ba9f200c24f7652e81883217ae80aa7e37b81d` |
| [HTTP](standards/HTTP.md) | `456f7161f08bdd23063bab934ba9a26fb178f0e1ad35e0d898255dd9702626c9` | `456f7161f08bdd23063bab934ba9a26fb178f0e1ad35e0d898255dd9702626c9` |
| [Naming](standards/Naming.md) | `783c67a53b62f9a1576a3a0c00a6438f1b6c40b0df84f268874689b715e74907` | `783c67a53b62f9a1576a3a0c00a6438f1b6c40b0df84f268874689b715e74907` |
| [PHP](standards/PHP.md) | `b102071e4939424796e4edc20d0b46373210634189c8f024038214e0e18cf623` | `b102071e4939424796e4edc20d0b46373210634189c8f024038214e0e18cf623` |
| [Planning](standards/Planning.md) | `c15df40af05655716afef68d6d3368396e335d673a96af319441a2d896792b2b` | `c15df40af05655716afef68d6d3368396e335d673a96af319441a2d896792b2b` |
| [Review](standards/Review.md) | `1387e6ac9a6d90bf1e7cafecb095071956803c9aa0eea5bd241e2dd41e018568` | `1387e6ac9a6d90bf1e7cafecb095071956803c9aa0eea5bd241e2dd41e018568` |
| [Testing](standards/Testing.md) | `fdab5a64ba92f406d63c28d48d6fdf800397c079c29932372746caf31f2c432f` | `fdab5a64ba92f406d63c28d48d6fdf800397c079c29932372746caf31f2c432f` |

## Link transformations

The baseline AGENTS links were rewritten from `Standards/<name>.md` to `docs/engineering/standards/<name>.md`. Package facts, commands, exceptions, and adoption gaps remain in the project profile.

## Adoption gaps

This adoption does not certify all existing code against the baseline. Its compatibility scan and deferred cleanup are preserved in the ignored adoption handoff. The package’s Domain/Application boundary, container runtime, exact-coverage gate, and release-certification procedure remain project-specific bindings.
