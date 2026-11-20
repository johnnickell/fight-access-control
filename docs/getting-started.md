# Get started

Install the library, pick one use case, and wire it into an application entry point you control.

## Install with Composer

Open the [README release panel](https://github.com/johnnickell/fight-access-control#release-and-installation) and run its Composer command. The panel owns the current PHP and Fight Common requirements, the install constraint, and the release overview. The constraint stays on one pre-v1 minor line: check the release overview and changelog before changing minors.

Check which version Composer installed:

```bash
composer show johnnickell/fight-access-control
```

### Read the guide for your installed version

From the release panel, open **Tagged documentation** if you installed the version shown there. Otherwise,
find your exact version in the [release list](https://github.com/johnnickell/fight-access-control/releases)
and open `docs/` at that tag. Use those guides for version-specific API and wiring examples. This site and the
linked development guides can describe unreleased changes.

## Try a Permission declaration

Save this as `first-use.php` in the Composer project and run `php first-use.php`. It prints `PROTECTED` and confirms that your application can load and create a package policy object. It does not authenticate or grant access.

```php
<?php
require __DIR__.'/vendor/autoload.php';

use Fight\AccessControl\Application\AccessControl\Authorization\Admission\AdmissionMode;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\PermissionDeclaration;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;

$policy = new PermissionDeclaration(AdmissionMode::PROTECTED, [
    PermissionName::fromString('ORDERS_READ'),
]);
echo $policy->mode->name, PHP_EOL;
```

## Choose one first use case

For human login, start with [Users and sessions](users.md). For machine identity, policy administration, or Feature flags, start with the [capability overview](capabilities.md). If you already have identity wiring and want to protect an HTTP entry point, start with [framework setup](frameworks.md).

1. Implement the selected capability's repository and service interfaces using your application's infrastructure. Test doubles are not production adapters.
2. Use Fight Common's `TransactionalUnitOfWork` on the connection shared by participating repositories. Keep the capability's writes, concurrency checks, and any enabled audit writes inside the same atomic boundary.
3. Authenticate the incoming request and bind fresh authority for exactly one User or Agent. Enforce the caller's Permission and record scope at the actual entry point, including non-HTTP paths.
4. Test success, refusal and failure with your actual repositories, transaction boundary, and framework. For recoverable delivery, schedule recovery even if events usually trigger immediate work.
