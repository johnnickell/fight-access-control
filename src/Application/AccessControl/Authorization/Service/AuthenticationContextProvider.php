<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

/**
 * Consumer-owned port providing authentication authority for the current request.
 */
interface AuthenticationContextProvider
{
    /**
     * Returns the current request's transport-neutral authentication context.
     */
    public function getAuthenticationContext(): AuthenticationContext;
}
