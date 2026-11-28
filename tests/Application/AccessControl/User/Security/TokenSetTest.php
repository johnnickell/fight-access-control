<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenSet::class)]
final class TokenSetTest extends TestCase
{
    private const string ACCESS_TOKEN = 'synthetic-access-token';

    private const string REFRESH_CREDENTIAL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function test_refuses_native_serialization_without_disclosing_credentials(): void
    {
        try {
            serialize(self::tokenSet());
        } catch (LogicException $logicException) {
            $message = $logicException->getMessage();
            self::assertFalse(str_contains($message, self::ACCESS_TOKEN), 'Access token was disclosed');
            self::assertFalse(
                str_contains($message, self::REFRESH_CREDENTIAL),
                'Refresh credential was disclosed'
            );

            return;
        }

        self::fail('Native serialization unexpectedly succeeded');
    }

    public function test_keeps_credentials_out_of_ordinary_debug_output(): void
    {
        $tokens = self::tokenSet();
        ob_start();
        try {
            var_dump($tokens);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertFalse(str_contains($output, self::ACCESS_TOKEN), 'Access token was disclosed');
        self::assertFalse(str_contains($output, self::REFRESH_CREDENTIAL), 'Refresh credential was disclosed');
    }

    public function test_exposes_credentials_through_explicit_getters(): void
    {
        $tokens = self::tokenSet();

        self::assertTrue($tokens->getAccessToken()->toString() === self::ACCESS_TOKEN, 'Wrong access token');
        self::assertTrue(
            $tokens->getRefreshCredential()->toString() === self::REFRESH_CREDENTIAL,
            'Wrong refresh credential'
        );
    }

    private static function tokenSet(): TokenSet
    {
        return new TokenSet(
            UserId::fromString('00000000-0000-4000-8000-000000000001'),
            RefreshSessionId::fromString('00000000-0000-4000-8000-000000000002'),
            RefreshCredential::fromString(self::REFRESH_CREDENTIAL),
            new DateTimeImmutable('2030-01-02T00:00:00+00:00'),
            false,
            AccessToken::fromString(self::ACCESS_TOKEN),
            new DateTimeImmutable('2030-01-01T00:15:00+00:00')
        );
    }
}
