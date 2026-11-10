# Effective portable Permission declarations (Unreleased)

TASK-00171 adds pure package metadata, not native HTTP enforcement. Existing adapters still use explicitly bound
`PermissionDeclaration` values; TICKET-00051 owns their portable Attribute integration. No consumer Attribute,
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

Added owners are `Authorization/Attribute/RequiresPermission` and `Authorization/Admission/PermissionRequirements`.
No production owner is removed or relocated; SelectedAction, PermissionDeclaration and PermissionAdmission are reused
unchanged. Source and tests mirror these capability homes. `etc/contract-owners.json` assigns the two new
files to TASK-00171; the reviewed structural inventory adds only their 44 boundaries, without reassigning existing
TASK-00145 or shared TASK-00170 contracts. Structural inventory is not independent semantic acceptance.

`tests/Application/AccessControl/Authorization/Admission/PermissionRequirementsTest.php` maintains the scenario matrix:

| Scenario | Observable proof |
| --- | --- |
| Repeated class/method, parent/override, inherited/invokable, route conjunction | Complete ordered canonical names, PROTECTED, no controller construction |
| Undeclared child override | Parent gates survive; direct method remains callable |
| Closure and absent policy/all explicit modes | Exact route preserved or null; missing policy still faults in existing admission |
| Wrong method/closure, missing selected method | Rejection, never unrelated/empty fallback |
| PUBLIC/AUTHENTICATED conflict, raw duplicate/list/type/mode faults | Configuration rejects without effective repair |
| Malformed repeated names/arguments and unsupported native targets | Actual native instantiation rejects every invalid declaration |
| Permission-only, independent PUBLIC plus Feature | No Feature metadata evaluation dependency; both metadata sets remain distinct |
| Existing admission composition | Metadata leaves authority unresolved; later admission checks every Permission |

Shared traversal's own `FeatureRequirementsTest` retains interface, static trait alias and unsupported selection proof.
These are package metadata and controlled authority fixtures, not actual native HTTP ordering, installed consumer
bootstrap/scanning/authentication/storage qualification, deployment or release. Independent Review and behavioral QA
remain distinct from Work checks. No useful screenshot exists for this pure library API; executable declarations,
returned modes/names and rejection/effect assertions are the nonvisual evidence.
