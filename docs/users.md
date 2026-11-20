# Users and sessions

Use this path for human accounts. For machine authentication and credential delivery, see [Agent integration](capabilities.md#agents-and-mcp).

## Sign in a User

Wire `UserAuthentication` with the repositories, password verification, throttling, token and session ports required by the [authentication composition guide](https://github.com/johnnickell/fight-access-control/blob/develop/docs/user-authentication.md#explicit-composition). Then call it synchronously from your sign-in entry point:

```php-inline
$tokens = $userAuthentication->login($email, $plainPassword, $remember);
```

`$email` is a Fight Common `EmailAddress`. The password and returned `TokenSet` are sensitive: keep them out of serialized messages, logs and error responses. Select the documented browser or portable response shape instead of returning the token object as-is.

## Manage the session

Use `SessionLifecycle` for refresh and logout. It owns refresh rotation and revocation; your application owns how clients present and receive credentials. Use `PasswordManagement` for password changes and resets and `EmailChangeConfirmation` to consume email-change credentials. These are direct synchronous calls, not bus messages.

Build fresh request authority from authoritative User/session state; don't reuse a prior request's Role or Permission snapshot. A signed-in User still needs authorization for each protected action. See [framework setup](frameworks.md) for the HTTP boundary.

## Bring Users into the application

Start with [invitation and credential delivery](https://github.com/johnnickell/fight-access-control/blob/develop/docs/credential-delivery.md). Self-registration is optional; expose it only when you've chosen its Role configuration, admission rules and abuse controls. See the [registration guide](https://github.com/johnnickell/fight-access-control/blob/develop/docs/user-registration.md) and [administrator recovery](https://github.com/johnnickell/fight-access-control/blob/develop/docs/registration-recovery.md) for their separate contracts.
