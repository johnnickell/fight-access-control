<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Command\RecoverPendingRegistration;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingRegistrationRecovered;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RegistrationRecoveryComponentsTest extends TestCase
{
    public function test_generated_recovery_schemas_match_current_safe_messages_and_required_formats(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/CredentialDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        $command = new RecoverPendingRegistration(
            UserId::generate(),
            UserId::generate(),
            ActivationDeliveryId::generate(),
            EmailAddress::fromString('corrected@example.test')
        );
        $event = new PendingRegistrationRecovered(
            $command->getActorId(),
            $command->getUserId(),
            $command->getExpectedActivationDeliveryId(),
            ActivationDeliveryId::generate(),
            $command->getEmail()
        );
        foreach (['RecoverPendingRegistration' => $command, 'PendingRegistrationRecovered' => $event] as $name => $message) {
            $schema = $schemas['Fight.AccessControl.'.$name];
            self::assertSame('object', $schema['type']);
            self::assertFalse($schema['additionalProperties']);
            self::assertEqualsCanonicalizing(array_keys($message->toArray()), $schema['required']);
            self::assertEqualsCanonicalizing(array_keys($message->toArray()), array_keys($schema['properties']));
            foreach ($schema['properties'] as $key => $property) {
                self::assertSame('string', $property['type']);
                self::assertSame($key === 'email' ? 'email' : 'uuid', $property['format']);
            }

            foreach (['credential', 'password', 'ciphertext', 'origin', 'role_ids', 'permissions'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $schema['properties']);
            }

            self::assertEquals($message, $message::fromArray($message->toArray()));
        }
    }
}
