<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Audit;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditEvidence::class)]
#[CoversClass(AuditGrantState::class)]
#[CoversClass(AuditUserRevisions::class)]
final class AuditOutcomeAdmissionTest extends TestCase
{
    /** @param array<array-key, mixed> $facts */
    private function admit(AuditAction $action, array $facts, bool $hydrate): AuditEvidence
    {
        $subject = new AuditSubject(UserId::generate());
        $at = new DateTimeImmutable('2026-11-09T12:00:00.000000+00:00');
        if ($hydrate) {
            return AuditEvidence::fromArray([
                'id'          => AuditEvidenceId::generate()->toString(),
                'actor'       => ['kind' => 'system', 'id' => SystemId::generate()->toString()],
                'subject'     => $subject->toArray(),
                'action'      => $action->value,
                'occurred_at' => $at->format('Y-m-d\TH:i:s.uP'),
                'correlation' => null,
                'facts'       => $facts
            ]);
        }

        $outcome = AuditUserOutcome::fromArray($facts);

        return AuditEvidence::record(SystemId::generate(), $subject, $action, $at, $outcome, null);
    }

    /**
     * Domain User transitions: version, authority, assignments, reservation, canonical email
     *
     * @return iterable<string, array{AuditAction, ?array{string, int, int, int, int, int},
     *     array{string, int, int, int, int, int}}>
     */
    public static function userTransitions(): iterable
    {
        yield 'invited' => [AuditAction::USER_INVITED, null, ['pending_activation', 1, 0, 0, 0, 0]];
        yield 'registered with default Roles' => [
            AuditAction::USER_REGISTERED, null, ['pending_activation', 1, 0, 2, 0, 0]
        ];
        yield 'corrected invitation' => [AuditAction::USER_PENDING_INVITATION_CORRECTED,
            ['pending_activation', 3, 4, 2, 5, 6], ['pending_activation', 3, 4, 2, 5, 7]];
        yield 'recovered changed destination' => [AuditAction::USER_PENDING_REGISTRATION_RECOVERED,
            ['pending_activation', 3, 4, 2, 5, 6], ['pending_activation', 3, 4, 2, 5, 7]];
        yield 'recovered unchanged destination' => [AuditAction::USER_PENDING_REGISTRATION_RECOVERED,
            ['pending_activation', 3, 4, 2, 5, 6], ['pending_activation', 3, 4, 2, 5, 6]];
        yield 'enabled' => [AuditAction::USER_ENABLED,
            ['disabled', 3, 4, 2, 5, 6], ['active', 3, 4, 2, 5, 6]];
        yield 'disabled' => [AuditAction::USER_DISABLED,
            ['active', 3, 4, 2, 5, 6], ['disabled', 4, 4, 2, 5, 6]];
        foreach (['active', 'disabled'] as $state) {
            yield 'deleted from '.$state => [AuditAction::USER_DELETED,
                [$state, 3, 4, 2, 5, 6], ['deleted', 4, 4, 2, 5, 6]];
        }

        foreach (['active', 'pending_activation'] as $state) {
            yield 'restored to '.$state => [AuditAction::USER_RESTORED,
                ['deleted', 3, 4, 2, 5, 6], [$state, 3, 4, 2, 5, 6]];
        }

        foreach ([AuditAction::PASSWORD_CHANGED, AuditAction::PASSWORD_RESET_COMPLETED] as $action) {
            yield $action->value => [$action, ['active', 3, 4, 2, 5, 6], ['active', 4, 5, 2, 5, 6]];
        }

        foreach ([AuditAction::EMAIL_CHANGE_REQUESTED, AuditAction::EMAIL_CHANGE_CANCELLED] as $action) {
            yield $action->value => [$action, ['active', 3, 4, 2, 5, 6], ['active', 3, 4, 2, 6, 6]];
        }

        yield 'email confirmed' => [AuditAction::EMAIL_CHANGE_CONFIRMED,
            ['active', 3, 4, 2, 5, 6], ['active', 4, 5, 2, 6, 7]];
    }

    /** @param array{string, int, int, int, int, int} $tuple
     * @return array<string, int|string>
     */
    private static function revisions(array $tuple): array
    {
        return array_combine([
            'state', 'authentication_version', 'authentication_authority', 'authorization_assignment',
            'email_reservation', 'canonical_email'
        ], $tuple);
    }

    /** @return array<array-key, mixed> */
    private static function userFacts(AuditAction $action): array
    {
        $grant = match ($action) {
            AuditAction::USER_INVITED, AuditAction::USER_REGISTERED,
            AuditAction::USER_PENDING_INVITATION_CORRECTED, AuditAction::USER_PENDING_REGISTRATION_RECOVERED
                => 'activation',
            AuditAction::PASSWORD_RESET_COMPLETED => 'password_reset',
            AuditAction::EMAIL_CHANGE_REQUESTED, AuditAction::EMAIL_CHANGE_CANCELLED,
                AuditAction::EMAIL_CHANGE_CONFIRMED => 'email_change',
            default => null
        };
        $after = null;
        if ($grant !== null) {
            $after = [
                'kind'        => $grant,
                'id'          => UserId::generate()->toString(),
                'revision'    => 0,
                'delivery_id' => UserId::generate()->toString()
            ];
        }

        return [
            'before'               => null,
            'after'                => [],
            'grant_before'         => null,
            'grant_after'          => $after,
            'replaced_predecessor' => null
        ];
    }

    /**
     * @param ?array{string, int, int, int, int, int} $before
     * @param array{string, int, int, int, int, int} $after
     */
    #[DataProvider('userTransitions')]
    public function test_valid_user_lifecycle_and_counter_tuples_round_trip(
        AuditAction $action,
        ?array $before,
        array $after
    ): void {
        $facts = self::userFacts($action);
        $facts['before'] = $before === null ? null : self::revisions($before);
        $facts['after'] = self::revisions($after);
        $evidence = $this->admit($action, $facts, false);
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
    }

    /** @return iterable<string, array{AuditAction, array<array-key, mixed>, bool}> */
    public static function invalidUserOutcomes(): iterable
    {
        foreach (self::userTransitions() as $name => [$action, $before, $after]) {
            $facts = self::userFacts($action);
            $facts['before'] = $before === null ? null : self::revisions($before);
            $facts['after'] = self::revisions($after);
            foreach ([false, true] as $hydrate) {
                $invalid = $facts;
                $invalid['after']['state'] = 'deleted';
                if ($after[0] === 'deleted') {
                    $invalid['after']['state'] = 'active';
                }

                yield $name.' wrong lifecycle '.(int) $hydrate => [$action, $invalid, $hydrate];
                if ($before !== null) {
                    $invalid = $facts;
                    $invalid['before']['state'] = 'deleted';
                    if ($before[0] === 'deleted') {
                        $invalid['before']['state'] = 'active';
                    }

                    yield $name.' wrong predecessor lifecycle '.(int) $hydrate => [$action, $invalid, $hydrate];
                }

                foreach (array_keys(self::revisions($after)) as $field) {
                    if ($field === 'state') {
                        continue;
                    }

                    $invalid = $facts;
                    $invalid['after'][$field] += 2;
                    if ($action === AuditAction::USER_REGISTERED && $field === 'authorization_assignment') {
                        $invalid['after'][$field] = 0;
                    }

                    yield $name.' skipped or unrelated '.$field.' '.(int) $hydrate
                        => [$action, $invalid, $hydrate];
                    if ($before !== null) {
                        $invalid = $facts;
                        $invalid['after'][$field] = $facts['before'][$field] - 1;
                        yield $name.' backwards '.$field.' '.(int) $hydrate => [$action, $invalid, $hydrate];
                        if ($facts['after'][$field] !== $facts['before'][$field]) {
                            if ($action === AuditAction::USER_PENDING_REGISTRATION_RECOVERED) {
                                continue;
                            }

                            $invalid['after'][$field] = $facts['before'][$field];
                            yield $name.' missing advance '.$field.' '.(int) $hydrate
                                => [$action, $invalid, $hydrate];
                        }
                    }
                }
            }
        }

        foreach ([false, true] as $hydrate) {
            $facts = self::userFacts(AuditAction::USER_DISABLED);
            $facts['before'] = self::revisions(['active', 3, 4, 2, 5, 6]);
            $facts['after'] = $facts['before'];
            yield 'unchanged active disabled '.(int) $hydrate => [AuditAction::USER_DISABLED, $facts, $hydrate];
            $facts['before']['authentication_version'] = PHP_INT_MAX;
            $facts['after'] = self::revisions(['disabled', 1, 4, 2, 5, 6]);
            yield 'exhausted counter '.(int) $hydrate => [AuditAction::USER_DISABLED, $facts, $hydrate];
        }
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('invalidUserOutcomes')]
    public function test_user_action_cannot_contradict_lifecycle_or_counters(
        AuditAction $action,
        array $facts,
        bool $hydrate
    ): void {
        $this->expectException(DomainException::class);
        $this->admit($action, $facts, $hydrate);
    }

    /** @return iterable<string, array{AuditAction, array<array-key, mixed>, bool}> */
    public static function invalidGrantOutcomes(): iterable
    {
        foreach (
            [
                'activation'     => [
                    AuditAction::INVITATION_DELIVERY_CONFIRMED, AuditAction::INVITATION_DELIVERY_FAILED
                ],
                'password_reset' => [
                    AuditAction::PASSWORD_RESET_DELIVERY_CONFIRMED, AuditAction::PASSWORD_RESET_DELIVERY_FAILED
                ],
                'email_change'   => [
                    AuditAction::EMAIL_CHANGE_DELIVERY_CONFIRMED, AuditAction::EMAIL_CHANGE_DELIVERY_FAILED
                ]
            ] as $kind => $actions
        ) {
            foreach ($actions as $action) {
                $before = [
                    'kind'        => $kind,
                    'id'          => UserId::generate()->toString(),
                    'revision'    => 5,
                    'delivery_id' => UserId::generate()->toString()
                ];
                $after = $before;
                $after['revision'] = 6;
                $status = 'delivered';
                if (str_ends_with($action->value, 'failed')) {
                    $status = 'retry_pending';
                }

                foreach (['backward', 'unchanged', 'skipped', 'delivery changed', 'generation replaced'] as $case) {
                    $invalid = $after;
                    $retained = null;
                    if ($case === 'delivery changed' || $case === 'generation replaced') {
                        $invalid['delivery_id'] = UserId::generate()->toString();
                    }

                    if ($case === 'generation replaced') {
                        $invalid['id'] = UserId::generate()->toString();
                        $invalid['revision'] = 0;
                        $retained = $before;
                    } elseif ($case !== 'delivery changed') {
                        $invalid['revision'] = match ($case) {
                            'backward' => 1,
                            'unchanged' => 5,
                            default => 7
                        };
                    }

                    foreach ([false, true] as $hydrate) {
                        yield $action->value.' '.$case.' '.(int) $hydrate => [$action, [
                            'grant_before'         => $before,
                            'grant_after'          => $invalid,
                            'delivery_status'      => $status,
                            'failure'              => null,
                            'replaced_predecessor' => $retained
                        ], $hydrate];
                    }
                }
            }
        }
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('invalidGrantOutcomes')]
    public function test_delivery_outcomes_require_the_exact_generation_and_next_revision(
        AuditAction $action,
        array $facts,
        bool $hydrate
    ): void {
        $this->expectException(DomainException::class);
        if ($hydrate) {
            $this->admit($action, $facts, true);

            return;
        }

        AuditEvidence::record(
            SystemId::generate(),
            new AuditSubject(UserId::generate()),
            $action,
            new DateTimeImmutable(),
            AuditGrantOutcome::fromArray($facts),
            null
        );
    }
}
