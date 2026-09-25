<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Closure;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryProvider;
use Throwable;

final class RecordingCredentialDeliveryProvider implements CredentialDeliveryProvider
{
    /** @var list<CredentialDeliveryInvocation> */
    private array $invocations = [];

    /**
     * @param list<CredentialDeliveryOutcome|Throwable> $results
     */
    public function __construct(private array $results = [], private readonly ?Closure $onDeliver = null)
    {
    }

    public function deliver(CredentialDeliveryInvocation $invocation): CredentialDeliveryOutcome
    {
        $this->invocations[] = $invocation;
        if ($this->onDeliver instanceof Closure) {
            ($this->onDeliver)($invocation);
        }

        $result = array_shift($this->results) ?? CredentialDeliveryOutcome::DELIVERED;
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** @return list<CredentialDeliveryInvocation> */
    public function invocations(): array
    {
        return $this->invocations;
    }
}
