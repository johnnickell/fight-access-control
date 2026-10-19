<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CredentialDeliveryInvocation::class)]
final class CredentialDeliveryInvocationTest extends TestCase
{
    public function test_it_cannot_be_serialized_with_raw_credential_material(): void
    {
        $invocation = $this->invocation();

        try {
            serialize($invocation);
            self::fail('Expected credential-delivery invocations to reject serialization.');
        } catch (LogicException $logicException) {
            self::assertSame('Credential-delivery invocations cannot be serialized.', $logicException->getMessage());
            self::assertStringNotContainsString('RAW-SECRET', (string) $logicException);
        }
    }

    public function test_it_redacts_diagnostics_while_remaining_available_to_the_provider(): void
    {
        $invocation = $this->invocation();
        $diagnostic = print_r($invocation, true);
        $export = var_export($invocation, true);

        self::assertStringNotContainsString('RAW-SECRET', $diagnostic);
        self::assertStringContainsString('[REDACTED]', $diagnostic);
        self::assertStringNotContainsString('RAW-SECRET', $export);
        self::assertSame('activation', $invocation->getPurpose());
        self::assertSame('delivery-generation-42', $invocation->getIdempotencyId());
        self::assertSame('alice@example.test', $invocation->getEmail()->canonical());
        self::assertSame('RAW-SECRET', $invocation->getCredential());
    }

    public function test_activation_requires_an_origin_and_other_purposes_reject_one(): void
    {
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            $origin = $purpose === 'activation' ? null : ActivationOrigin::REGISTRATION;
            try {
                new CredentialDeliveryInvocation(
                    $purpose,
                    'delivery-id',
                    EmailAddress::fromString('alice@example.test'),
                    'RAW-SECRET',
                    $origin
                );
                self::fail('A mismatched activation origin was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString('RAW-SECRET', (string) $exception);
            }
        }

        foreach (['password_reset', 'email_change'] as $purpose) {
            $invocation = new CredentialDeliveryInvocation(
                $purpose,
                'delivery-id',
                EmailAddress::fromString('alice@example.test'),
                'RAW-SECRET',
                null
            );
            self::assertNull($invocation->getActivationOrigin());
            self::assertSame($purpose, $invocation->getPurpose());
        }
    }

    private function invocation(): CredentialDeliveryInvocation
    {
        return new CredentialDeliveryInvocation(
            'activation',
            'delivery-generation-42',
            EmailAddress::fromString('alice@example.test'),
            'RAW-SECRET',
            ActivationOrigin::PRIVATE_INVITATION
        );
    }
}
