<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service;

use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CredentialDeliveryInvocation::class)]
final class CredentialDeliveryInvocationTest extends TestCase
{
    public function test_it_cannot_be_serialized_with_raw_credential_material(): void
    {
        $invocation = $this->invocation();
        $serialized = null;

        try {
            $serialized = serialize($invocation);
            self::fail('Expected credential-delivery invocations to reject serialization.');
        } catch (LogicException $logicException) {
            self::assertSame('Credential-delivery invocations cannot be serialized.', $logicException->getMessage());
        }

        self::assertStringNotContainsString('RAW-SECRET', (string) $serialized);
    }

    public function test_it_redacts_diagnostics_while_remaining_available_to_the_provider(): void
    {
        $invocation = $this->invocation();
        $diagnostic = print_r($invocation, true);

        self::assertStringNotContainsString('RAW-SECRET', $diagnostic);
        self::assertStringContainsString('[REDACTED]', $diagnostic);
        self::assertSame('activation', $invocation->getPurpose());
        self::assertSame('delivery-generation-42', $invocation->getIdempotencyId());
        self::assertSame('alice@example.test', $invocation->getEmail()->canonical());
        self::assertSame('RAW-SECRET', $invocation->getCredential());
    }

    private function invocation(): CredentialDeliveryInvocation
    {
        return new CredentialDeliveryInvocation(
            'activation',
            'delivery-generation-42',
            EmailAddress::fromString('alice@example.test'),
            'RAW-SECRET'
        );
    }
}
