<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

/**
 * Interface AuthenticationContextProvider
 *
 * Consumer-owned port providing authentication authority for the current request.
 */
interface AuthenticationContextProvider
{
    /**
     * Returns the current request's transport-neutral authentication context
     *
     * Resolve only trusted verified authentication, not caller-selected identities or copied authorization. A fresh
     * request/nested context needs its own provider composition; do not retain a previous request in a singleton.
     * Explicit PrincipalResolutionException represents known missing/stale authentication. Operational and malformed
     * context failures must throw their actual fault, never manufacture an anonymous or successfully resolved context.
     */
    public function getAuthenticationContext(): AuthenticationContext;
}
