<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipal;

/**
 * Resolves and caches the authoritative principal for one request.
 *
 * Consumers must compose a new instance per request and provide only an AuthenticationContextProvider.
 */
final class CurrentPrincipalProvider
{
    private ?AuthenticatedPrincipal $authenticatedPrincipal = null;

    /**
     * Creates a request-scoped current-principal service.
     */
    public function __construct(
        private readonly AuthenticationContextProvider $authenticationContextProvider,
        private readonly AuthoritativePrincipalResolver $authoritativePrincipalResolver
    ) {
    }

    /**
     * Returns the request's principal after one authoritative resolution.
     */
    public function getCurrentPrincipal(): AuthenticatedPrincipal
    {
        $this->authenticatedPrincipal ??= $this->authoritativePrincipalResolver->resolve(
            $this->authenticationContextProvider->getAuthenticationContext()
        );

        return $this->authenticatedPrincipal;
    }
}
