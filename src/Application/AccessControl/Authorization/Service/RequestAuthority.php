<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentAuthenticationDiagnosticClassification;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\CurrentAgentPrincipalResolutionRejectedException;
use Fight\AccessControl\Domain\AccessControl\Authorization\Exception\PrincipalResolutionException;
use Fight\Common\Application\Error\Exception\AuthenticationRequired;

/**
 * Class RequestAuthority
 *
 * Selects exactly one actual request-scoped authentication provider, never a copied principal.
 */
final readonly class RequestAuthority
{
    /**
     * Constructs RequestAuthority
     *
     * @phpstan-param Closure(): SecurityContext $resolve
     */
    private function __construct(private Closure $resolve)
    {
    }

    /**
     * Creates an authority from the trusted User authentication-context composition
     */
    public static function user(CurrentPrincipalProvider $provider): self
    {
        return new self(static fn(): SecurityContext => new SecurityContext($provider->getCurrentPrincipal()));
    }

    /**
     * Creates an authority from the trusted signed Agent request composition
     */
    public static function agent(
        CurrentAgentPrincipalProvider $provider,
        SignedAgentRequest $request,
        string $correlationId
    ): self {
        return new self(static fn(): SecurityContext => new SecurityContext(
            $provider->resolve($request, $correlationId)
        ));
    }

    /**
     * Returns complete authority or translates only explicitly known authentication refusals
     */
    public function resolve(): SecurityContext
    {
        try {
            return ($this->resolve)();
        } catch (PrincipalResolutionException $failure) {
            throw new AuthenticationRequired('Request authentication rejected.', previous: $failure);
        } catch (CurrentAgentPrincipalResolutionRejectedException $failure) {
            if (
                in_array($failure->getDiagnostic()->getClassification(), [
                AgentAuthenticationDiagnosticClassification::AUTHENTICATION_REJECTED,
                AgentAuthenticationDiagnosticClassification::AGENT_AUTHORITY_NOT_CURRENT
                ], true)
            ) {
                throw new AuthenticationRequired('Request authentication rejected.', previous: $failure);
            }

            // Incomplete snapshots and opaque resolution failures are not authentication classifications.
            throw $failure;
        }
    }
}
