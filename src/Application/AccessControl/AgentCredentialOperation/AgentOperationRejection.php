<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Exception\AgentIssuanceFailed;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Common\Application\Error\Exception\ApplicationException;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\RateLimitExceeded;
use Fight\Common\Application\Error\Exception\ServiceUnavailable;
use Fight\Common\Application\Error\Exception\StateConflict;
use RuntimeException;
use SensitiveParameter;

/**
 * Class AgentOperationRejection
 *
 * Translates known operation refusals without retaining provider diagnostics or causes.
 */
final class AgentOperationRejection extends RuntimeException
{
    /**
     * Constructs AgentOperationRejection
     */
    public function __construct(private readonly AgentOperationFailure $reason)
    {
        parent::__construct('Agent operation refused.');
    }

    /**
     * Qualifies only reasons promised by the actual supplying boundary
     */
    public static function fromDomain(
        #[SensitiveParameter] AgentOperationRejectedException $failure,
        AgentOperationFailure ...$allowed
    ): self {
        if (!in_array($failure->getReason(), $allowed, true)) {
            throw new AgentIssuanceFailed();
        }

        return new self($failure->getReason());
    }

    /**
     * Creates the intentional Application failure for a known Domain refusal
     */
    public static function fromReason(AgentOperationFailure $reason): ApplicationException
    {
        return match ($reason) {
            AgentOperationFailure::INVALID_REQUEST => new InvalidInput('The Agent operation request is invalid.'),
            AgentOperationFailure::UNAUTHORIZED => new PermissionDenied('The Agent operation is not authorized.'),
            AgentOperationFailure::CONFLICT => new StateConflict('The Agent operation conflicts with current state.'),
            AgentOperationFailure::CAPACITY => new RateLimitExceeded('Agent operation capacity is exhausted.'),
            AgentOperationFailure::CONTENTION,
            AgentOperationFailure::UNSUPPORTED_VERSION,
            AgentOperationFailure::UNAVAILABLE => new ServiceUnavailable('The Agent operation is unavailable.')
        };
    }

    /**
     * Returns the source-qualified refusal reason
     */
    public function getReason(): AgentOperationFailure
    {
        return $this->reason;
    }
}
