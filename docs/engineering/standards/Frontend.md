# React and TypeScript

Retain the simple React/TypeScript, esbuild, ESLint, Prettier, and Bootstrap/React Bootstrap stack. Use existing form and test libraries where suitable. Verify the project's versions and configuration; compilation must be paired with TypeScript checking. Style warnings fail. The starting formatting convention is two spaces, single quotes, semicolons, no trailing commas, and a 100-character print width; preserve documented project exceptions.

| Piece | Owns |
|---|---|
| Route | URL matching, Layout/Page selection, navigation guards |
| Layout | Shared shell, navigation, outlet |
| Page | Use-case coordination, local state, loading, refresh, submission and notifications |
| Form/component | Interaction, fields, validation feedback, callbacks |
| Feature API service | Typed operations and HTTP/data mapping |
| Shared API client | Transport, authentication, refresh coordination, common errors |

Keep forms in nested `_components` beside their use, promoting them when shared. After a successful change, the Page refreshes affected data. Optimistic updates require a reason. Hooks may encapsulate mechanics while the Page owns coordination. Cover loading, empty, failure, and recovery behavior according to the use case; do not mistake a successful HTTP request for completed UI acceptance.

Use React Context for shared tooling behind focused hooks such as `useSecurity`, `useValidations`, and `useNotifications`. Allow project-specific implementations behind the useful common contract. Keep Page state local/URL-bound unless there is a real sharing need. Prove portable conventions in the project-symfony frontend before carrying them into other project-* starters; a shared package is not a prerequisite.

## Data and URLs

Frontend objects are plain TypeScript interfaces/type aliases with camelCase properties. They have no domain methods or class hydration hierarchy. Reuse types based on the backend View/use-case contract, not all private entity state. Page/components/forms own interaction behavior; the server owns business invariants and authorization.

Map incoming snake_case to camelCase and outgoing data back at the feature API service boundary, deliberately handling nested objects, optional values, and dates. Modest shared helpers are appropriate.

Bookmark search, filters, sorting, pagination, and meaningful selected views in query parameters. Keep unsaved form data and ordinary transient UI state local. Simple parameters remain readable; complex filters may use JSON encoded as Base64URL through shared functions. Validate decoding and fall back safely on malformed input; encoding is not secrecy. Handle Back/Forward. Normally reset pagination when filters change, replace history while typing, and push for deliberate filter application/page changes.

## Access tokens

Hold access tokens only in memory and refresh credentials in an HttpOnly cookie. On reload, use the refresh endpoint to obtain an access token. The shared API client checks freshness before every protected request and awaits refresh before attaching the current token. Concurrent requests share a single in-flight refresh; the refresh request bypasses its own freshness gate.

Preserve clock-adjusted early refresh: for a 15-minute token, use it only for its first ten minutes, refreshing at the threshold. Record the server-time anchor and browser receipt time, estimate elapsed time, and compare against expiration minus five minutes. Do not capture a stale token in a long-lived interceptor or launch refresh without awaiting it.

When implementing this lifecycle, resolve the actual server-time source, clock changes/sleep, cookie scope/flags, CSRF and origin requirements, rotation, logout races, multi-tab behavior, and transient-versus-expired failures from the project. These are open implementation decisions, not settled universal recipes. Test threshold boundaries, clock differences, concurrent requests, replacement-token use, and failures. Automatic retries of writes need an explicit safety/idempotency decision.
