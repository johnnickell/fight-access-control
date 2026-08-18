---
id: T-00004
title: Login, cold restore, and current-session logout
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00003
branch: feature/t-00004-login-restore-logout
---

# Login, cold restore, and current-session logout

## Outcome

An active user can log in, restore an authenticated session after a cold load, and revoke only the current session
on logout without exposing whether an identity exists.

## Acceptance criteria

- [ ] Login normalizes canonical email and returns generic failure behavior with a bounded throttling port.
- [ ] Successful login creates the server-authoritative session and exposes only the framework-neutral result needed by consumers.
- [ ] Cold restoration revalidates the authenticated session without requiring browser-persisted access tokens.
- [ ] Logout revokes only the current refresh session and leaves other sessions active.
- [ ] Tests demonstrate generic failures, account-state denial, restoration, and single-session revocation.

## Exclusions

No JWT implementation, cookie writing, browser storage, rate-limiter backend, or HTTP endpoint.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
