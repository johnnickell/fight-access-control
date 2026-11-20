# Effective portable Permission declarations (Unreleased)

TASK-00171 adds pure package metadata. TASK-00176 connects it to existing
[PSR-15 admission through Slim and Yii 3](http-permission-admission.md#portable-permission-admission-unreleased),
TASK-00177 connects it to the optional [Symfony native subscriber](symfony-permission-admission.md),
TASK-00178 connects it to [native Laravel middleware](laravel-permission-admission.md),
and TASK-00179 connects the optional [CodeIgniter 4 before-filter](codeigniter-permission-admission.md).
All four paths support Attribute-only protection and explicit route additions across five frameworks. No consumer Attribute,
framework bootstrap, discovery provider or Feature activation is required. `RequiresAgentPermission` and MCP remain separate.

## Declare and compose

```php
use Fight\AccessControl\Application\AccessControl\Action\SelectedAction;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionRequirements;
use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;

#[RequiresPermission('ORDERS_READ')]
#[RequiresPermission('ACCOUNT_ACCESS')]
final class Orders
{
    #[RequiresPermission('ORDERS_EXPORT')]
    public function export(): void
    {
        // Direct calls are not intercepted.
    }
}

// Only trusted router composition selects code and supplies route metadata.
$selected = SelectedAction::method(Orders::class, 'export');
$requirements = new PermissionRequirements($selected);
$declaration = $requirements->getDeclaration($selected);
// PROTECTED: ORDERS_READ, ACCOUNT_ACCESS, ORDERS_EXPORT; no redundant route gates needed.

$route = new PermissionDeclaration(AdmissionMode::PROTECTED, [PermissionName::fromString('EXPORT_DOWNLOAD')]);
$additional = new PermissionRequirements($selected, $route);
$combined = $additional->getDeclaration($selected);
// Also requires EXPORT_DOWNLOAD. Pass the result to existing PermissionAdmission with trusted authority.
```

Each native Attribute takes exactly one `string $name` and exposes `getName(): PermissionName`. Names are canonical
uppercase words separated by single underscores: `READ`, `ORDERS_EXPORT`. No trimming, case conversion, role/display-name
mapping or OAuth-scope conversion occurs. Empty names, digits, whitespace and malformed separators reject through
canonical `PermissionName`. Class and method Attributes are repeatable, including valid duplicates. Native
`ReflectionAttribute::newInstance()` validates every occurrence: missing/extra/unknown/wrong-type arguments and
unsupported property/parameter/constant/function targets reject. Reading `getArguments()` alone is not validation.

`PermissionRequirements(SelectedAction $action, ?PermissionDeclaration $route = null)` consumes the same pure
[SelectedAction ancestry](feature-references.md#explicit-registration-and-composition) as Feature requirements.
Class/interface promises, inherited methods and overridden parent methods all accumulate. A child can add, never
remove, requirements. Public static and trait-resolved methods use that shared PHP resolution; `invokable()` selects
`__invoke`. `closure($actualClosure)` supports explicit route declarations, with exact closure-object association;
RequiresPermission on a closure itself is an invalid native function target. Metadata never constructs controllers.
Unresolvable/unsupported methods reject in SelectedAction. Native ambiguous extraction must stop, not choose an
unguarded fallback. `getDeclaration($actuallySelectedAction)` checks exact class/method or closure identity even for
empty bindings. It cannot detect a native router that supplies a false target: trusted extraction remains adapter-owned.
Never select code or declarations from client headers, parameters or request claims.

Every Attribute is instantiated before its valid name enters effective deduplication. A supplied route declaration
has already validated its entire raw input. Its non-list, wrong-type, duplicate, mode and empty-PROTECTED rejection
remain unchanged; a merger cannot repair invalid raw input. Effective valid duplicates collapse in first-name order.

| Attribute requirements | Explicit route | Effective result |
| --- | --- | --- |
| Present | None | PROTECTED with every Attribute Permission |
| Present | PROTECTED | Conjunction of Attribute and route names, deduplicated |
| Present | PUBLIC or AUTHENTICATED | Configuration conflict; reject |
| Absent | Any valid explicit mode | Preserve the exact declaration |
| Absent | None | Null; existing admission rejects missing policy, never implicit PUBLIC |

A PUBLIC Permission action can independently carry Feature gates. Permission composition does not instantiate Feature
Attributes, evaluate Features, resolve principals, inspect grants, read repositories, consume nonces, dispatch messages,
transact or write audit. Only later explicit admission resolves authority. User Role-derived versus Agent direct
Permissions, refusal behavior and consumer record/tenant/target scope stay unchanged. Feature ON, actor IDs and metadata
do not grant Permission. Protect alternate HTTP/bus/worker/direct entry points separately.

## Ownership and maintained evidence

```text
trusted selected code --> Action/SelectedAction (existing shared ancestry and identity)
                          |                         |
                          v                         v
             Authorization/Admission/       Feature/FeatureRequirements
             PermissionRequirements         (independently selectable)
                    ^             |
 Authorization/Attribute/         v
 RequiresPermission        PermissionDeclaration --> PermissionAdmission (unchanged)
          |                                           ^
          v                                           |
 Domain/Permission/PermissionName               trusted RequestAuthority
```

Use the [structural inventory procedure](engineering/contract-inventory.md) for declaration ownership.
Qualify each native target, bootstrap, scanner, authentication and storage independently;
metadata checks alone do not establish successful native registration or protection.
