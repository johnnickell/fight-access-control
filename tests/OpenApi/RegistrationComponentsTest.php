<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RegistrationComponentsTest extends TestCase
{
    public function test_generated_registration_components_match_messages_without_authority_or_secret_inputs(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/CredentialDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        $command = new RegisterUser(UserId::generate(), EmailAddress::fromString('Alice@example.test'));
        $event = new UserRegistered(
            $command->getUserId(),
            ActivationDeliveryId::generate(),
            $command->getEmail(),
            new DateTimeImmutable('2030-01-01T00:00:00.123456Z')
        );
        foreach (['RegisterUser' => $command, 'UserRegistered' => $event] as $name => $message) {
            $schema = $schemas['Fight.AccessControl.'.$name];
            self::assertSame('object', $schema['type']);
            self::assertEqualsCanonicalizing(array_keys($message->toArray()), $schema['required']);
            self::assertEqualsCanonicalizing(array_keys($message->toArray()), array_keys($schema['properties']));
            self::assertSame('uuid', $schema['properties']['user_id']['format']);
            self::assertSame('email', $schema['properties']['email']['format']);
            self::assertEquals($message, $message::fromArray($message->toArray()));
            foreach (['actor_id', 'role_ids', 'role_names', 'password', 'credential', 'origin'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $schema['properties']);
            }
        }

        self::assertFalse($schemas['Fight.AccessControl.RegisterUser']['additionalProperties']);
        self::assertSame('uuid', $schemas['Fight.AccessControl.UserRegistered']['properties']['activation_delivery_id']['format']);
        self::assertSame('date-time', $schemas['Fight.AccessControl.UserRegistered']['properties']['issued_at']['format']);
        // Existing activation remains password-setting, not a second registration or a new credential format.
        self::assertSame(
            ['user_id', 'activation_credential', 'plain_password'],
            $schemas['Fight.AccessControl.Authentication.ActivateRequest']['required']
        );
    }
}
