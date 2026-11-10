# PHP

Read the installed Fight PHPCS ruleset and project composition for mechanically enforced details: strict types, four-space indentation, braces, import sorting, array alignment, member ordering, blank lines, and trailing commas. Follow current tooling; propose a scoped correction when it conflicts with an explicit preference. Warnings fail style checks.

Choose placement and inward contracts under [Architecture](Architecture.md#accesscontrol-placement-and-dependency-rules)
before following an existing PHP file as a template. Apply [descriptive collaborator naming](Naming.md#descriptive-collaborators-and-collections)
to promoted properties, parameters and locals alike; update named-argument callers with an implemented rename.

Use final classes by default. Preserve exceptions needed for extension or framework mapping; Doctrine entities in John's current projects are an exception. AccessControl aggregate entities remain extensible under the project profile; final readonly fits immutable DTOs, handlers and services where extension is not part of their contract. Properties are readonly by default unless mutation belongs to their responsibility. ValueObject classes are readonly. Prefer constructor promotion for straightforward assignments and explicit initialization when construction does real work.

```php
/**
 * Class RegisterUserHandler
 */
final class RegisterUserHandler implements CommandHandler
{
    /**
     * Constructs RegisterUserHandler
     */
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UniqueEmailSpecification $uniqueEmailSpecification
    ) {
    }
}
```

This is a declaration excerpt; real files include strict types, namespace, and alphabetically ordered imports. Retain class and constructor docblocks even when they repeat declaration names.

## Documentation

Each method summary starts with a capitalized active verb, forms a simple phrase, and has no terminal punctuation. Use the Fight standard's accepted verbs, such as Returns, Creates, Handles, or Validates. Constructors use `Constructs ClassName`. Separate longer descriptions or annotations by one blank docblock line.

Prefer inherited documentation when an interface/parent already describes the behavior. Even a bare inheritDoc uses a multiline block and contains no additional content:

```php
/**
 * @inheritDoc
 */
```

Omit redundant `@param` and `@return` annotations. When analysis needs richer types, prefer tool-specific tags that explain why the annotation exists:

```php
/**
 * Returns the assigned role identifiers
 *
 * @phpstan-return list<RoleId>
 */
public function getRoleIds(): array
{
    return $this->roleIds;
}
```

Comments explain business reasons, constraints, and non-obvious order. Avoid narration and routine section comments. Sections may help an unusually large block, but a huge method is a candidate for cohesive private methods; no arbitrary line-count threshold is imposed. Test documentation follows the actual project rules; do not extend production sniff requirements beyond their configured scope without a decision.

## Message payload declarations

Each CommandHandler and QueryHandler declares the single Command or Query it supports through its registration
method in that same owning class. Configure the routing bus with that declaration and handler; do not register
it for unrelated payloads. Query handlers declare their specific native result, with precise PHPStan collection
or union types where needed, or throw. QueryBus fetch/dispatch remain `mixed`; known caller result annotations
are permitted, not runtime generic enforcement.

Inside `handle()`, put a flat, single-line payload PHPDoc immediately above extraction, matching the registered
type. For example, in `GetUserByIdHandler`:

```php
/** @var GetUserById $query */
$query = $queryMessage->payload();
```

Command handlers use the same form with their Command type and `$commandMessage->payload()`. Event Subscribers
use the analogous single-line annotation immediately above event payload extraction in the listener method;
keep the supported Event/listener declarations in that same owning subscriber. Do not move the concrete payload
declaration to an external map or replace it with a multiline annotation. These local annotations document the
configured route, not validation of arbitrary decoded/injected data. Preserve existing dynamic-boundary validation
and runtime guards; annotations neither create native payload narrowing nor make those checks redundant at runtime.

## Expressions

Use guard clauses and early returns to reduce nesting. Multiple returns are welcome. No space follows unary `!`. Prefer a default assignment followed by an explicit conditional for choices like:

```php
$statusLabel = 'Inactive';
if ($user->isActive()) {
    $statusLabel = 'Active';
}
```

This is a preference, not a universal ternary ban. Do not introduce useless one-use predicate variables. Let clear domain concepts become methods/specifications rather than carrying complicated checks through handlers. Follow [Naming](Naming.md) for imports, helpers, and business names.
