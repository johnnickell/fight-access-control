<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\Command\RegisterUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRegistered;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(RegisterUser::class)]
#[CoversClass(UserRegistered::class)]
final class RegisterUserTest extends TestCase
{
    public function test_messages_round_trip_without_actor_authority_or_credentials(): void
    {
        $id = UserId::generate();
        $email = EmailAddress::fromString('Alice@example.test');
        $command = new RegisterUser($id, $email);
        self::assertSame($id, $command->getUserId());
        self::assertSame($email, $command->getEmail());
        self::assertSame(['user_id', 'email'], array_keys($command->toArray()));
        self::assertEquals($command, RegisterUser::fromArray($command->toArray()));
        $delivery = ActivationDeliveryId::generate();
        $at = new DateTimeImmutable('2030-01-01T00:00:00.123456Z');
        $event = new UserRegistered($id, $delivery, $email, $at);
        self::assertSame($id, $event->getUserId());
        self::assertSame($delivery, $event->getActivationDeliveryId());
        self::assertSame($email, $event->getEmail());
        self::assertSame($at, $event->getIssuedAt());
        self::assertSame(
            ['user_id', 'activation_delivery_id', 'email', 'issued_at'],
            array_keys($event->toArray())
        );
        self::assertEquals($event, UserRegistered::fromArray($event->toArray()));
    }

    public function test_each_required_field_must_be_present(): void
    {
        $command = new RegisterUser(UserId::generate(), EmailAddress::fromString('alice@example.test'));
        $event = new UserRegistered(
            $command->getUserId(),
            ActivationDeliveryId::generate(),
            $command->getEmail(),
            new DateTimeImmutable('2030-01-01T00:00:00Z')
        );
        foreach ([$command, $event] as $message) {
            foreach (array_keys($message->toArray()) as $key) {
                $data = $message->toArray();
                unset($data[$key]);
                try {
                    $message::fromArray($data);
                    self::fail('Missing '.$key.' was accepted.');
                } catch (DomainException $caught) {
                    self::assertNotSame('', $caught->getMessage());
                }
            }
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidCommandFields(): iterable
    {
        yield 'missing id' => ['user_id', null];
        yield 'malformed id' => ['user_id', 'not-an-id'];
        yield 'nonstring id' => ['user_id', 42];
        yield 'missing email' => ['email', null];
        yield 'invalid email' => ['email', 'not-email'];
        yield 'array email' => ['email', ['untrusted']];
        yield 'requested roles' => ['role_ids', []];
        yield 'claimed actor' => ['actor_id', 'administrator'];
        yield 'password' => ['password', 'not-admitted'];
        yield 'activation credential' => ['credential', 'not-admitted'];
    }

    #[DataProvider('invalidCommandFields')]
    public function test_untrusted_command_fields_fail_closed(string $key, mixed $value): void
    {
        $data = new RegisterUser(UserId::generate(), EmailAddress::fromString('alice@example.test'))->toArray();
        $data[$key] = $value;
        $this->expectException(Throwable::class);
        RegisterUser::fromArray($data);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidEventFields(): iterable
    {
        foreach (['user_id', 'activation_delivery_id', 'email', 'issued_at'] as $key) {
            yield 'missing '.$key => [$key, null];
            yield 'empty '.$key => [$key, ''];
            yield 'array '.$key => [$key, []];
        }

        yield 'invalid id' => ['user_id', 'bad'];
        yield 'invalid delivery' => ['activation_delivery_id', 'bad'];
        yield 'invalid email' => ['email', 'bad'];
        yield 'invalid time' => ['issued_at', 'not-a-time'];
        yield 'relative time' => ['issued_at', 'tomorrow'];
        yield 'normalized invalid date' => ['issued_at', '2030-02-30T00:00:00Z'];
    }

    #[DataProvider('invalidEventFields')]
    public function test_invalid_facts_are_not_rehydrated(string $key, mixed $value): void
    {
        $data = new UserRegistered(
            UserId::generate(),
            ActivationDeliveryId::generate(),
            EmailAddress::fromString('alice@example.test'),
            new DateTimeImmutable('2030-01-01T00:00:00Z')
        )->toArray();
        $data[$key] = $value;
        $this->expectException(Throwable::class);
        UserRegistered::fromArray($data);
    }
}
