<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Psr15;

use Fight\AccessControl\Application\AccessControl\Agent\Security\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Security\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Service\RequestAuthority;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Psr\Http\Message\ServerRequestInterface;

final readonly class NativeAgentAuthority
{
    public function __construct(private ProfileToolEnvironment $environment)
    {
    }

    public function create(ServerRequestInterface $request, bool $brokenSnapshot = false): RequestAuthority
    {
        $environment = $this->environment;
        $environment->nextRequest();

        $body = (string) $request->getBody();
        $environment->request = new SignedAgentRequest(
            $request->getMethod(),
            $request->getUri()->getAuthority(),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $environment->now(),
            $environment->request->getNonce(),
            $environment->original->getCredentialId(),
            'HMAC-SHA256',
            'fixture-signature',
            $body === '' ? null : hash('sha256', $body),
            $body
        );
        $permissions = $environment->permissions;
        if ($brokenSnapshot) {
            $permissions = new InMemoryPermissionRepository();
        }

        $environment->provider = new CurrentAgentPrincipalProvider(
            $environment->storage->agents,
            $permissions,
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($environment->request, 'original-test-secret'),
            new FixedClock($environment->now()),
            $environment->nonces,
            $environment->storage->transaction
        );

        return RequestAuthority::agent($environment->provider, $environment->request, $environment->correlationId);
    }
}
