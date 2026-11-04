<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Application\AccessControl\Agent\Authentication\CurrentAgentPrincipalProvider;
use Fight\AccessControl\Application\AccessControl\Agent\Authentication\SignedAgentRequest;
use Fight\AccessControl\Application\AccessControl\Authorization\Admission\RequestAuthority;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSharedSecretDecipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Authentication\FixedHmacSignedAgentRequestVerifier;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ProfileToolEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Permission\Repository\InMemoryPermissionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Symfony\Component\HttpFoundation\Request;

final readonly class NativeAgentAuthority
{
    public function __construct(private ProfileToolEnvironment $environment)
    {
    }

    public function create(Request $request, bool $brokenSnapshot = false, bool $replay = false): RequestAuthority
    {
        $environment = $this->environment;
        if (!$replay) {
            $environment->nextRequest();
            $body = $request->getContent();
            $environment->request = new SignedAgentRequest(
                $request->getMethod(),
                $request->getHttpHost(),
                $request->getPathInfo(),
                $request->getQueryString() ?? '',
                $environment->now(),
                $environment->request->getNonce(),
                $environment->original->getCredentialId(),
                'HMAC-SHA256',
                'fixture-signature',
                $body === '' ? null : hash('sha256', $body),
                $body
            );
        }

        $permissionRepository = $environment->permissionRepository;
        if ($brokenSnapshot) {
            $permissionRepository = new InMemoryPermissionRepository();
        }

        $environment->provider = new CurrentAgentPrincipalProvider(
            $environment->storage->agentRepository,
            $permissionRepository,
            new FixedHmacSharedSecretDecipher('auth-envelope:'),
            new FixedHmacSignedAgentRequestVerifier($environment->request, 'original-test-secret'),
            new FixedClock($environment->now()),
            $environment->nonces,
            $environment->storage->transaction
        );

        return RequestAuthority::agent($environment->provider, $environment->request, $environment->correlationId);
    }
}
