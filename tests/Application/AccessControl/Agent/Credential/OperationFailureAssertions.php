<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\Common\Application\Error\Exception\InvalidInput;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\RateLimitExceeded;
use Fight\Common\Application\Error\Exception\ServerError;
use Fight\Common\Application\Error\Exception\ServiceUnavailable;
use Fight\Common\Application\Error\Exception\StateConflict;
use PHPUnit\Framework\Assert;
use Throwable;

/** Asserts current Application intent while retaining separate Domain/worker conformance contracts */
final class OperationFailureAssertions
{
    public static function assertMatches(?AgentOperationFailure $reason, Throwable $failure): void
    {
        if ($failure instanceof AgentOperationRejectedException) {
            Assert::assertSame($reason, $failure->getReason());

            return;
        }

        $expected = match ($reason) {
            null => ServerError::class,
            AgentOperationFailure::INVALID_REQUEST => InvalidInput::class,
            AgentOperationFailure::UNAUTHORIZED => PermissionDenied::class,
            AgentOperationFailure::CONFLICT => StateConflict::class,
            AgentOperationFailure::CAPACITY => RateLimitExceeded::class,
            default => ServiceUnavailable::class
        };
        Assert::assertInstanceOf($expected, $failure);
    }
}
