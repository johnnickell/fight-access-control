# Naming

Use short, readable names from CONTEXT.md. A name identifies intent, returned data, a past fact, or a capability; it should not explain the entire implementation.

| Concept | Preferred examples | Rule |
|---|---|---|
| Command | `RegisterUser` | Imperative business intent |
| Query | `GetUserById`, `GetUserByEmail`, `ListUsers` | Requested data, with clear selection |
| Event | `UserRegistered` | Past-tense business fact |
| Command/query handler | `RegisterUserHandler`, `GetUserByIdHandler` | Corresponding message plus Handler |
| Capability contract | `MailTransport`, `UserRepository` | No Interface suffix |
| Concrete service | `SymfonyMailTransport` | Distinguishing implementation adjective |
| Specification | `UniqueEmailSpecification` | Business condition, not mechanism |
| HTTP Action | `RegisterUserAction` | One interaction |
| Safe output | `UserView` | Explicit public/use-case data |
| Repository lookup | `getById`, `getByEmail` | Nullable return is expressed in the contract; no forced find/get distinction |
| Entity behavior | `canLogIn` | State-based domain concept owned by the entity |
| Exception factory | `DuplicateEmailException::fromEmail` | Contextual construction and message formatting inside the exception |

## Descriptive collaborators and collections

Properties, constructor/method parameters and locals identify the collaborator's responsibility, including
repositories, buses and dispatchers: `$agentRepository`, `$queryBus`, `$synchronousCommandBus`, `$eventDispatcher`.
Avoid `$agents`, `$queries`, `$commands` or `$events` when the value is one collaborator rather than a collection.
Use a meaningful qualifier when two dependencies share a kind; do not invent repetitive boilerplate or length quotas.
Actual collections keep natural plural names, such as `$agents`, `$permissionNames` and `$events` for lists of values.
A plural is not a defect merely because it appears in owned code.

When implementing an approved rename, update properties, parameters, locals, named-argument callers, tests and
maintained examples together. Do not rename public wire fields or real collections mechanically. Namespace
ownership follows [Architecture](Architecture.md#accesscontrol-placement-and-dependency-rules), not naming symmetry;
Service and generic helper buckets must not conceal a more informative semantic capability. TASK-00109 owns the
existing Application cohesion/collaborator cleanup; this rule alone does not claim that cleanup is implemented.

PHP properties and frontend data properties are camelCase. Database columns and HTTP JSON fields are snake_case. Map at boundaries, including nested data. Use available `string()` conversion helpers where suitable; do not change an established external schema without a compatibility decision.

Import referenced classes, functions, and constants rather than using fully qualified names throughout method bodies. Keep use statements alphabetically ordered according to the project Fight coding standard. Available helpers such as `string()` and `array_list()` are preferred where they express the intended operation clearly; avoid gratuitous wrapping.

Review naming as one cohesive criterion backed by precise examples. A novel protocol adapter's specific class names remain a design decision within the accepted ownership and path rules; do not reopen the approved MCP destination merely because source has not moved yet.
