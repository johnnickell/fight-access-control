<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http;

use Fight\Common\Adapter\Http\Error\SafeHttpPresentation;
use Fight\Common\Adapter\Http\Error\SafeHttpProjector;
use Fight\Common\Application\Error\Exception\PreconditionFailed;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\RuleViolation;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Validation\Exception\ValidationException;
use Fight\Common\Domain\Exception\LookupException;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;
use TypeError;
use UnexpectedValueException;

final class OrdinaryFailureCases
{
    /** @return iterable<string, array{string, int, string}> */
    public static function cases(): iterable
    {
        yield 'required-resource' => ['missing', 404, 'Resource not found.'];
        yield 'inherited-http-intent' => ['subtype', 404, 'Resource not found.'];
        yield 'current-state' => ['conflict', 409, 'State conflict.'];
        yield 'caller-precondition' => ['precondition', 412, 'Precondition failed.'];
        yield 'business-rule' => ['rule', 422, 'Rule violation.'];
        yield 'structured-validation-private' => ['validation', 400, 'Invalid input.'];
        foreach (['identity', 'result', 'lookup', 'unknown'] as $case) {
            yield $case => [$case, 500, 'Internal server error.'];
        }
    }

    public static function failure(string $case): Throwable
    {
        $cause = new RuntimeException('PRIVATE CAUSE password=ciphertext');

        return match ($case) {
            'missing' => new ResourceNotFound('PRIVATE resource', 422, $cause),
            'subtype' => new class ('PRIVATE subtype', 500, $cause) extends ResourceNotFound {
            },
            'conflict' => new StateConflict('PRIVATE conflict', 404, $cause),
            'precondition' => new PreconditionFailed('PRIVATE precondition', 409, $cause),
            'rule' => new RuleViolation('PRIVATE rule', 412, $cause),
            'validation' => new ValidationException(
                ['PRIVATE_FIELD' => ['PRIVATE INVALID VALUE password=ciphertext']],
                'PRIVATE validation',
                $cause
            ),
            'identity' => new UnexpectedValueException('PRIVATE identity', 404, $cause),
            'result' => new TypeError('PRIVATE result', 422, $cause),
            'lookup' => new LookupException('PRIVATE missing routing', 404, $cause),
            default => new RuntimeException(
                'PRIVATE ResourceNotFound StateConflict RuleViolation',
                404,
                new ResourceNotFound('PRIVATE misleading cause')
            )
        };
    }

    public static function assertBody(string $body, int $status, string $message): void
    {
        $expected = ['status' => 'fail', 'data' => ['message' => $message]];
        if ($status >= 500) {
            $expected = ['status' => 'error', 'message' => $message];
        }

        Assert::assertSame($expected, json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        Assert::assertStringNotContainsString('PRIVATE', $body);
        Assert::assertStringNotContainsString('ciphertext', $body);
    }

    public static function validationProjection(): SafeHttpProjector
    {
        return new readonly class implements SafeHttpProjector {
            public function project(Throwable $failure, int $status): ?SafeHttpPresentation
            {
                if (!$failure instanceof ValidationException) {
                    return null;
                }

                // Authored finite field/code vocabulary, never getErrors() or a diagnostic message.
                return new SafeHttpPresentation(data: ['fields' => ['name' => ['invalid']]]);
            }
        };
    }
}
