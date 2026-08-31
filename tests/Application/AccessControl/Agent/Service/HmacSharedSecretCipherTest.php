<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

#[CoversNothing]
final class HmacSharedSecretCipherTest extends TestCase
{
    public function test_that_an_existing_encrypt_only_cipher_implementation_remains_compatible(): void
    {
        $cipher = new readonly class implements HmacSharedSecretCipher {
            public function encrypt(#[SensitiveParameter] string $hmacSharedSecret): string
            {
                return 'encrypted:'.$hmacSharedSecret;
            }
        };

        self::assertSame('encrypted:shared-secret', $cipher->encrypt('shared-secret'));
    }
}
