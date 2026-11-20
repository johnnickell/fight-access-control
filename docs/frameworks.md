# Framework setup

Use the framework-neutral core on its own, or opt into HTTP admission on selected routes. The package does not install middleware or make routes public by default.

## Choose your adapter

| Framework | Permission setup | Feature setup |
| --- | --- | --- |
| Slim | [PSR-15 route middleware](https://github.com/johnnickell/fight-access-control/blob/develop/docs/http-permission-admission.md#slim-composition) | [Slim composition](https://github.com/johnnickell/fight-access-control/blob/develop/docs/http-feature-admission.md) |
| Yii 3 | [PSR-15 route middleware](https://github.com/johnnickell/fight-access-control/blob/develop/docs/http-permission-admission.md#yii-3-composition) | [Yii 3 composition](https://github.com/johnnickell/fight-access-control/blob/develop/docs/http-feature-admission.md) |
| Symfony | [Native subscriber](https://github.com/johnnickell/fight-access-control/blob/develop/docs/symfony-permission-admission.md) | [Native Feature setup](https://github.com/johnnickell/fight-access-control/blob/develop/docs/symfony-feature-admission.md) |
| Laravel | [Native middleware](https://github.com/johnnickell/fight-access-control/blob/develop/docs/laravel-permission-admission.md) | [Native Feature setup](https://github.com/johnnickell/fight-access-control/blob/develop/docs/laravel-feature-admission.md) |
| CodeIgniter 4 | [Native before-filter](https://github.com/johnnickell/fight-access-control/blob/develop/docs/codeigniter-permission-admission.md) | [Native Feature setup](https://github.com/johnnickell/fight-access-control/blob/develop/docs/codeigniter-feature-admission.md) |

## Declare a protected action

For adapters that consume portable Permission attributes, declare what the action requires. The framework must still run the appropriate admission adapter before invoking the action.

```php-inline
use Fight\AccessControl\Application\AccessControl\Authorization\Attribute\RequiresPermission;

final class Orders
{
    #[RequiresPermission('ORDERS_EXPORT')]
    public function export(): void
    {
        // Export only records the admitted caller may access.
    }
}
```

Use the framework guide to bind admission to the exact action selected by the trusted router. Decide explicitly whether each action is public, requires authentication, or requires Permissions. Give admission fresh User or Agent authority from your authentication services, not request-supplied claims or a copied `SecurityContext`.

Portable metadata can add requirements, not grant access by itself. Protect direct, worker and bus entry points separately, and check record or tenant scope within the application even after HTTP admission succeeds. Read the [portable Permission contract](https://github.com/johnnickell/fight-access-control/blob/develop/docs/permission-requirements.md#declare-and-compose) for the composition rules.
