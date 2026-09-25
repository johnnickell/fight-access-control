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

PHP properties and frontend data properties are camelCase. Database columns and HTTP JSON fields are snake_case. Map at boundaries, including nested data. Use available `string()` conversion helpers where suitable; do not change an established external schema without a compatibility decision.

Import referenced classes, functions, and constants rather than using fully qualified names throughout method bodies. Keep use statements alphabetically ordered according to the project Fight coding standard. Available helpers such as `string()` and `array_list()` are preferred where they express the intended operation clearly; avoid gratuitous wrapping.

Review naming as one cohesive criterion backed by precise examples. A novel protocol adapter's class/path names remain a design decision until its responsibilities are known.
