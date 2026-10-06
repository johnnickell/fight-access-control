<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Query\InvitationDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindCredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler\ExpiredCredentialFixture;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CredentialDeliveryComponentsTest extends TestCase
{
    public function test_expired_work_and_exact_dispatch_contracts_match_generated_components(): void
    {
        $schemas = $this->schemas();
        foreach (
            [
            ['activation', 'ExpireInvitationDelivery', 'InvitationDeliveryExpired'],
            ['password_reset', 'ExpirePasswordResetDelivery', 'PasswordResetDeliveryExpired'],
            ['email_change', 'ExpireEmailChange', 'EmailChangeExpired']
            ] as [$purpose, $commandName, $eventName]
        ) {
            $fixture = new ExpiredCredentialFixture($purpose);
            $query = new FindExpiredCredentialDeliveries($fixture->expiresAt, 50);
            $this->assertCanonicalFields($schemas['Fight.AccessControl.FindExpiredCredentialDeliveries'], $query->toArray());
            $work = $fixture->discovery()->handle(QueryMessage::create($query))[0];
            $workSchema = $schemas['Fight.AccessControl.ExpiredCredentialDelivery'];
            $this->assertCanonicalFields($workSchema, $work->toArray());
            self::assertContains($work->getPurpose(), $workSchema['properties']['purpose']['enum']);
            self::assertContains($work->getStatus()->value, $workSchema['properties']['status']['enum']);
            self::assertSame('2030-01-01T01:00:00.123456+00:00', $work->toArray()['expires_at']);
            self::assertSame(['string', 'null'], $workSchema['properties']['email_change_grant_id']['type']);
            $command = $fixture->command();
            $this->assertCanonicalFields($schemas['Fight.AccessControl.'.$commandName], $command->toArray());
            $fixture->handler()->handle(CommandMessage::create($command));
            $event = $fixture->events->events()[0];
            self::assertSame('#/components/schemas/Fight.AccessControl.'.$commandName, $schemas['Fight.AccessControl.'.$eventName]['$ref']);
            $this->assertCanonicalFields($schemas['Fight.AccessControl.'.$commandName], $event->toArray());
            self::assertSame($command->toArray()['occurred_at'], $event->toArray()['occurred_at']);
        }

        self::assertSame([
            'at'    => ['type' => 'string', 'format' => 'date-time'],
            'limit' => ['type' => 'integer', 'maximum' => 100, 'minimum' => 1]
        ], $schemas['Fight.AccessControl.FindExpiredCredentialDeliveries']['properties']);
        self::assertSame(0, $schemas['Fight.AccessControl.ExpiredCredentialDelivery']['properties']['revision']['minimum']);
        self::assertSame('array', $schemas['Fight.AccessControl.ExpiredCredentialDeliveries']['type']);
        self::assertSame(100, $schemas['Fight.AccessControl.ExpiredCredentialDeliveries']['maxItems']);
        self::assertSame('#/components/schemas/Fight.AccessControl.ExpiredCredentialDelivery', $schemas['Fight.AccessControl.ExpiredCredentialDeliveries']['items']['$ref']);
        self::assertSame('#/components/schemas/Fight.AccessControl.ExpiredCredentialDeliveries', $schemas['Fight.AccessControl.JSend.Success.ExpiredCredentialDeliveries']['properties']['data']['$ref']);
    }

    public function test_generated_invitation_status_matches_the_delivery_enum(): void
    {
        $schemas = $this->schemas();

        self::assertSame(
            array_column(CredentialDeliveryStatus::cases(), 'value'),
            $schemas['Fight.AccessControl.InvitationDeliveryStatus']['properties']['status']['enum']
        );
    }

    public function test_generated_delivery_statuses_admit_real_lifecycle_payloads_and_exclude_obsolete_values(): void
    {
        $schemas = $this->schemas();
        $expected = array_column(CredentialDeliveryStatus::cases(), 'value');
        foreach (['InvitationDeliveryStatus', 'CredentialDeliveryStatus', 'DueCredentialDelivery'] as $name) {
            $status = $schemas['Fight.AccessControl.'.$name]['properties']['status'];
            self::assertSame('string', $status['type']);
            self::assertSame($expected, $status['enum']);
            foreach (['failed', 'confirmed', 'unknown', '', null, 1] as $invalid) {
                self::assertNotContains($invalid, $status['enum']);
            }
        }

        $deliveries = $this->deliveries();
        self::assertEqualsCanonicalizing($expected, array_keys($deliveries));
        foreach ($deliveries as $delivery) {
            $invitation = new InvitationDeliveryStatusView(
                $delivery->getUserId(),
                $delivery->getStatus(),
                $delivery->getExpiresAt()
            );
            $view = CredentialDeliveryStatusView::fromDelivery('password_reset', $delivery, 0);
            $due = new DueCredentialDelivery(
                'password_reset',
                $delivery->getId(),
                $delivery->getUserId(),
                $delivery->getNextAttemptAt(),
                0,
                $delivery->getStatus()
            );
            foreach (
                [
                'InvitationDeliveryStatus' => $invitation->toArray(),
                'CredentialDeliveryStatus' => $view->toArray(),
                'DueCredentialDelivery'    => $due->toArray()
                ] as $name => $payload
            ) {
                $schema = $schemas['Fight.AccessControl.'.$name];
                $this->assertCanonicalFields($schema, $payload);
                self::assertContains($payload['status'], $schema['properties']['status']['enum']);
            }
        }
    }

    public function test_generated_inputs_match_canonical_messages_and_validation_constraints(): void
    {
        $schemas = $this->schemas();
        $delivery = $this->deliveries()['pending'];
        $command = new DeliverPasswordReset('worker:reset', $delivery->getUserId(), $delivery->getId());
        $commandSchema = $schemas['Fight.AccessControl.DeliverPasswordReset'];
        $this->assertCanonicalFields($commandSchema, $command->toArray());
        self::assertSame([
            'actor_id'                   => ['type' => 'string'],
            'user_id'                    => ['type' => 'string', 'format' => 'uuid'],
            'password_reset_delivery_id' => ['type' => 'string', 'format' => 'uuid']
        ], $commandSchema['properties']);

        $statusSchema = $schemas['Fight.AccessControl.FindCredentialDeliveryStatus'];
        foreach (['activation', 'password_reset', 'email_change'] as $purpose) {
            $query = new FindCredentialDeliveryStatus($purpose, $delivery->getId()->toString());
            $this->assertCanonicalFields($statusSchema, $query->toArray());
            self::assertContains($query->toArray()['purpose'], $statusSchema['properties']['purpose']['enum']);
        }

        // UUID is the catalog identifier convention and the handler resolves a purpose-specific delivery ID.
        self::assertSame([
            'purpose'     => ['type' => 'string', 'enum' => ['activation', 'password_reset', 'email_change']],
            'delivery_id' => ['type' => 'string', 'format' => 'uuid', 'minLength' => 1]
        ], $statusSchema['properties']);
        self::assertNotContains('unknown', $statusSchema['properties']['purpose']['enum']);

        $dueSchema = $schemas['Fight.AccessControl.FindDueCredentialDeliveries'];
        foreach ([1, 101, PHP_INT_MAX] as $limit) {
            $query = new FindDueCredentialDeliveries(new DateTimeImmutable('2030-01-01T12:00:00.123456Z'), $limit);
            $this->assertCanonicalFields($dueSchema, $query->toArray());
            self::assertGreaterThanOrEqual($dueSchema['properties']['limit']['minimum'], $query->toArray()['limit']);
            self::assertSame('2030-01-01T12:00:00.123456+00:00', $query->toArray()['at']);
        }

        // No maximum or default exists on this query; Agent discovery limits must not leak into this contract.
        self::assertSame([
            'at'    => ['type' => 'string', 'format' => 'date-time'],
            'limit' => ['type' => 'integer', 'minimum' => 1]
        ], $dueSchema['properties']);
    }

    public function test_safe_results_describe_only_canonical_fields_with_required_nullable_history(): void
    {
        $schemas = $this->schemas();
        $purpose = ['type' => 'string', 'enum' => ['activation', 'password_reset', 'email_change']];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $date = ['type' => 'string', 'format' => 'date-time'];
        $status = ['type' => 'string', 'enum' => array_column(CredentialDeliveryStatus::cases(), 'value')];
        $failures = [...array_column(CredentialDeliveryFailure::cases(), 'value'), null];
        $operational = $schemas['Fight.AccessControl.CredentialDeliveryStatus'];
        self::assertSame([
            'purpose'         => $purpose,
            'delivery_id'     => $uuid,
            'user_id'         => $uuid,
            'revision'        => ['type' => 'integer'],
            'status'          => $status,
            'due_at'          => $date,
            'expires_at'      => $date,
            'attempt_count'   => ['type' => 'integer', 'minimum' => 0],
            'last_attempt_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'last_outcome_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'last_failure'    => ['type' => ['string', 'null'], 'enum' => $failures]
        ], $operational['properties']);
        self::assertSame([
            'purpose'     => $purpose,
            'delivery_id' => $uuid,
            'user_id'     => $uuid,
            'due_at'      => $date,
            'revision'    => ['type' => 'integer'],
            'status'      => $status
        ], $schemas['Fight.AccessControl.DueCredentialDelivery']['properties']);
        self::assertSame([
            'user_id'    => $uuid,
            'status'     => $status,
            'expires_at' => $date
        ], $schemas['Fight.AccessControl.InvitationDeliveryStatus']['properties']);

        $deliveries = $this->deliveries();
        $pending = CredentialDeliveryStatusView::fromDelivery('password_reset', $deliveries['pending'], 0)->toArray();
        foreach (['last_attempt_at', 'last_outcome_at', 'last_failure'] as $field) {
            self::assertNull($pending[$field]);
            self::assertContains($field, $operational['required']);
            self::assertContains('null', $operational['properties'][$field]['type']);
        }

        $claimed = $deliveries['claimed'];
        $token = $claimed->getClaimToken();
        self::assertNotNull($token);
        $at = new DateTimeImmutable('2030-01-01T12:00:01Z');
        foreach (CredentialDeliveryFailure::cases() as $failure) {
            if ($failure === CredentialDeliveryFailure::PERMANENT_PROVIDER) {
                $failed = $claimed->failPermanently($token, $at);
            } else {
                $failed = $claimed->fail($token, $at, $failure);
            }

            $payload = CredentialDeliveryStatusView::fromDelivery('password_reset', $failed, 2)->toArray();
            $this->assertCanonicalFields($operational, $payload);
            self::assertSame(1, $payload['attempt_count']);
            self::assertSame('2030-01-01T12:00:00+00:00', $payload['last_attempt_at']);
            self::assertSame('2030-01-01T12:00:01+00:00', $payload['last_outcome_at']);
            self::assertSame($failure->value, $payload['last_failure']);
            self::assertContains($payload['last_failure'], $operational['properties']['last_failure']['enum']);
        }

        foreach (['failed', 'provider exception text', '', false] as $unsafeFailure) {
            self::assertNotContains($unsafeFailure, $operational['properties']['last_failure']['enum']);
        }
    }

    public function test_due_lists_and_optional_envelopes_compose_without_pagination_or_dangling_references(): void
    {
        $schemas = $this->schemas();
        $list = $schemas['Fight.AccessControl.DueCredentialDeliveries'];
        self::assertSame('array', $list['type']);
        self::assertSame('#/components/schemas/Fight.AccessControl.DueCredentialDelivery', $list['items']['$ref']);
        foreach (['properties', 'required', 'minItems', 'maxItems'] as $absent) {
            self::assertArrayNotHasKey($absent, $list);
        }

        foreach (['InvitationDeliveryStatus', 'CredentialDeliveryStatus', 'DueCredentialDeliveries'] as $name) {
            $envelope = $schemas['Fight.AccessControl.JSend.Success.'.$name];
            self::assertSame('object', $envelope['type']);
            self::assertSame(['status', 'data'], $envelope['required']);
            self::assertSame(['status', 'data'], array_keys($envelope['properties']));
            self::assertSame(['type' => 'string', 'enum' => ['success']], $envelope['properties']['status']);
            self::assertSame('#/components/schemas/Fight.AccessControl.'.$name, $envelope['properties']['data']['$ref']);
        }

        array_walk_recursive($schemas, static function (mixed $value, string|int $key) use ($schemas): void {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
                self::assertArrayHasKey(substr($value, strlen('#/components/schemas/')), $schemas);
            }
        });
    }

    /**
     * Asserts every canonical field is required, with no extra documented secret or pagination fields
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $payload
     */
    private function assertCanonicalFields(array $schema, array $payload): void
    {
        self::assertSame('object', $schema['type']);
        self::assertSame(array_keys($payload), $schema['required']);
        self::assertSame(array_keys($payload), array_keys($schema['properties']));
    }

    /** @return array<string, PasswordResetDelivery> */
    private function deliveries(): array
    {
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z');
        $expiresAt = new DateTimeImmutable('2030-01-01T13:00:00Z');
        $pending = PasswordResetDelivery::create(
            PasswordResetDeliveryId::fromString('22222222-2222-4222-8222-222222222222'),
            UserId::fromString('11111111-1111-4111-8111-111111111111'),
            EmailAddress::fromString('recipient@example.test'),
            'test-only-encrypted-material',
            $expiresAt,
            $at
        );
        $token = CredentialDeliveryClaimToken::generate();
        $claimed = $pending->claim($token, $at, $at->modify('+5 minutes'));

        return [
            'pending'           => $pending,
            'claimed'           => $claimed,
            'retry_pending'     => $claimed->fail($token, $at, CredentialDeliveryFailure::UNEXPECTED_PROVIDER),
            'delivered'         => $claimed->confirm($token, $at),
            'permanent_failure' => $claimed->failPermanently($token, $at),
            'expired'           => $pending->expireAt($expiresAt),
            'invalidated'       => $pending->invalidate()
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function schemas(): array
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
        // Component-only composition needs no invented consumer endpoint or complete-document validation.
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/CredentialDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, 512, JSON_THROW_ON_ERROR)['components']['schemas'];
        self::assertArrayHasKey('Consumer.CredentialDelivery', $schemas);

        return $schemas;
    }
}
