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
use Fight\AccessControl\Domain\AccessControl\User\UserState;
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

        $outcome = AuditUserOutcome::class;
        if (array_key_exists('delivery_status', $facts)) {
            $outcome = AuditGrantOutcome::class;
        }

        $outcome = $outcome::fromArray($facts);

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

        foreach (UserState::cases() as $state) {
            yield 'email expired '.$state->value => [AuditAction::EMAIL_CHANGE_EXPIRED,
                [$state->value, 3, 4, 2, 5, 6], [$state->value, 3, 4, 2, 6, 6]];
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
    private static function userFacts(AuditAction $action, bool $pendingRestoration = false): array
    {
        $grant = match ($action) {
            AuditAction::USER_INVITED, AuditAction::USER_REGISTERED,
            AuditAction::USER_PENDING_INVITATION_CORRECTED, AuditAction::USER_PENDING_REGISTRATION_RECOVERED
                => 'activation',
            AuditAction::USER_RESTORED => $pendingRestoration ? 'activation' : null,
            AuditAction::PASSWORD_RESET_COMPLETED => 'password_reset',
            AuditAction::EMAIL_CHANGE_REQUESTED, AuditAction::EMAIL_CHANGE_CANCELLED,
            AuditAction::EMAIL_CHANGE_EXPIRED, AuditAction::EMAIL_CHANGE_CONFIRMED => 'email_change',
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

        $before = null;
        $retained = null;
        if (
            in_array($action, [
                AuditAction::PASSWORD_RESET_COMPLETED, AuditAction::EMAIL_CHANGE_CANCELLED,
                AuditAction::EMAIL_CHANGE_EXPIRED, AuditAction::EMAIL_CHANGE_CONFIRMED
            ], true)
        ) {
            $before = $after;
            $before['revision'] = 5;
            $after['revision'] = 6;
        } elseif (
            in_array($action, [
                AuditAction::USER_PENDING_INVITATION_CORRECTED, AuditAction::USER_PENDING_REGISTRATION_RECOVERED
            ], true)
        ) {
            $before = $after;
            $before['id'] = UserId::generate()->toString();
            $before['delivery_id'] = UserId::generate()->toString();
            $before['revision'] = 5;
            $retained = $before;
            $retained['revision'] = 6;
        }

        return [
            'before'               => null,
            'after'                => [],
            'grant_before'         => $before,
            'grant_after'          => $after,
            'replaced_predecessor' => $retained
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
        $facts = self::userFacts($action, $after[0] === 'pending_activation');
        $facts['before'] = $before === null ? null : self::revisions($before);
        $facts['after'] = self::revisions($after);
        $evidence = $this->admit($action, $facts, false);
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
    }

    /** @return iterable<string, array{AuditAction, array<array-key, mixed>, bool}> */
    public static function invalidUserOutcomes(): iterable
    {
        foreach (self::userTransitions() as $name => [$action, $before, $after]) {
            $facts = self::userFacts($action, $after[0] === 'pending_activation');
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
    public static function invalidUserGrantRelationships(): iterable
    {
        foreach (self::userTransitions() as $name => [$action, $before, $after]) {
            $facts = self::userFacts($action, $after[0] === 'pending_activation');
            $facts['before'] = $before === null ? null : self::revisions($before);
            $facts['after'] = self::revisions($after);
            $grant = $facts['grant_after'];
            if ($grant === null) {
                foreach ([false, true] as $hydrate) {
                    $invalid = $facts;
                    $invalid['grant_after'] = self::userFacts(AuditAction::USER_INVITED)['grant_after'];
                    yield $name.' unrelated issuance '.(int) $hydrate => [$action, $invalid, $hydrate];
                    $invalid['grant_before'] = $invalid['grant_after'];
                    $invalid['grant_after'] = null;
                    yield $name.' dangling predecessor '.(int) $hydrate => [$action, $invalid, $hydrate];
                }

                continue;
            }

            $variants = [];
            $invalid = $facts;
            $invalid['grant_after'] = null;
            $invalid['replaced_predecessor'] = null;
            $variants['missing successor'] = $invalid;
            $invalid = $facts;
            $invalid['grant_before'] = null;
            $invalid['grant_after']['revision'] = 0;
            $invalid['replaced_predecessor'] = null;
            if ($facts['grant_before'] !== null) {
                $variants['missing predecessor'] = $invalid;
            }

            $invalid = $facts;
            if ($facts['grant_before'] === null) {
                $invalid['grant_after']['revision'] = 99;
                $variants['initial revision'] = $invalid;
                $invalid = $facts;
                $invalid['grant_before'] = $grant;
                $invalid['grant_after']['revision'] = $grant['revision'] + 1;
                $variants['unexpected in-place predecessor'] = $invalid;
            } elseif ($facts['replaced_predecessor'] === null) {
                $invalid['grant_after']['id'] = UserId::generate()->toString();
                $invalid['grant_after']['delivery_id'] = UserId::generate()->toString();
                $invalid['grant_after']['revision'] = 0;
                $invalid['replaced_predecessor'] = $facts['grant_before'];
                $variants['replacement instead of consumption'] = $invalid;
            } else {
                $invalid['replaced_predecessor'] = $facts['grant_before'];
                $variants['replacement omitted cancellation advance'] = $invalid;
                $invalid = $facts;
                $invalid['grant_after'] = $facts['grant_before'];
                $invalid['grant_after']['revision']++;
                $invalid['replaced_predecessor'] = null;
                $variants['in-place instead of replacement'] = $invalid;
            }

            if ($action === AuditAction::EMAIL_CHANGE_REQUESTED) {
                $invalid = $facts;
                $invalid['grant_before'] = $grant;
                $invalid['grant_before']['id'] = UserId::generate()->toString();
                $invalid['grant_before']['delivery_id'] = UserId::generate()->toString();
                $invalid['grant_before']['revision'] = 5;
                $invalid['replaced_predecessor'] = $invalid['grant_before'];
                $invalid['replaced_predecessor']['revision'] = 6;
                $variants['terminal predecessor advanced'] = $invalid;
            }

            foreach ($variants as $case => $invalid) {
                foreach ([false, true] as $hydrate) {
                    yield $name.' '.$case.' '.(int) $hydrate => [$action, $invalid, $hydrate];
                }
            }
        }
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('invalidUserGrantRelationships')]
    public function test_user_action_requires_its_actual_grant_relationship(
        AuditAction $action,
        array $facts,
        bool $hydrate
    ): void {
        $this->expectException(DomainException::class);
        $this->admit($action, $facts, $hydrate);
    }

    #[DataProvider('hydrationModes')]
    public function test_initial_reset_issuance_cannot_start_at_an_arbitrary_revision(bool $hydrate): void
    {
        $facts = [
            'grant_before'         => null,
            'grant_after'          => self::userFacts(AuditAction::PASSWORD_RESET_COMPLETED)['grant_after'],
            'delivery_status'      => null,
            'failure'              => null,
            'replaced_predecessor' => null
        ];
        $facts['grant_after']['revision'] = 99;
        $this->expectException(DomainException::class);
        $this->admit(AuditAction::PASSWORD_RESET_REQUESTED, $facts, $hydrate);
    }

    #[DataProvider('hydrationModes')]
    public function test_email_request_retains_terminal_predecessor_without_advancing_it(bool $hydrate): void
    {
        $facts = self::userFacts(AuditAction::EMAIL_CHANGE_REQUESTED);
        $facts['before'] = self::revisions(['active', 3, 4, 2, 5, 6]);
        $facts['after'] = self::revisions(['active', 3, 4, 2, 6, 6]);
        $facts['grant_before'] = $facts['grant_after'];
        $facts['grant_before']['id'] = UserId::generate()->toString();
        $facts['grant_before']['delivery_id'] = UserId::generate()->toString();
        $facts['grant_before']['revision'] = 5;
        $facts['replaced_predecessor'] = $facts['grant_before'];
        $evidence = $this->admit(AuditAction::EMAIL_CHANGE_REQUESTED, $facts, $hydrate);
        self::assertSame($facts, $evidence->getFacts()->toArray());
    }

    /** @return iterable<string, array{AuditAction, array<array-key, mixed>, bool, bool}> */
    public static function issuanceRelationships(): iterable
    {
        foreach ([AuditAction::INVITATION_RESENT, AuditAction::PASSWORD_RESET_REQUESTED] as $action) {
            $kind = $action === AuditAction::INVITATION_RESENT ? 'activation' : 'password_reset';
            $after = [
                'kind'        => $kind,
                'id'          => UserId::generate()->toString(),
                'revision'    => 0,
                'delivery_id' => UserId::generate()->toString()
            ];
            $before = $after;
            $before['id'] = UserId::generate()->toString();
            $before['delivery_id'] = UserId::generate()->toString();
            $before['revision'] = 5;
            $cases = ['initial', 'cancelled', 'terminal', 'in-place', 'missing retained', 'skipped retained'];
            foreach ($cases as $case) {
                $retained = $before;
                $retained['revision'] = match ($case) {
                    'terminal' => 5,
                    'skipped retained' => 7,
                    default => 6
                };
                $facts = [
                    'grant_before'         => $case === 'initial' ? null : $before,
                    'grant_after'          => $after,
                    'delivery_status'      => null,
                    'failure'              => null,
                    'replaced_predecessor' => in_array($case, ['initial', 'missing retained'], true) ? null : $retained
                ];
                if ($case === 'in-place') {
                    $facts['grant_after'] = $before;
                    $facts['grant_after']['revision'] = 6;
                    $facts['replaced_predecessor'] = null;
                }

                $valid = $case === 'cancelled' || ($action === AuditAction::PASSWORD_RESET_REQUESTED
                    && in_array($case, ['initial', 'terminal'], true));
                foreach ([false, true] as $hydrate) {
                    yield $action->value.' '.$case.' '.(int) $hydrate => [$action, $facts, $hydrate, $valid];
                }
            }
        }
    }

    /** @param array<array-key, mixed> $facts */
    #[DataProvider('issuanceRelationships')]
    public function test_issuance_preserves_its_actual_initial_or_replaced_predecessor(
        AuditAction $action,
        array $facts,
        bool $hydrate,
        bool $valid
    ): void {
        if (!$valid) {
            $this->expectException(DomainException::class);
        }

        $evidence = $this->admit($action, $facts, $hydrate);
        self::assertSame($facts, $evidence->getFacts()->toArray());
    }

    /** @return iterable<array{bool}> */
    public static function hydrationModes(): iterable
    {
        yield [false];
        yield [true];
    }

    /** @return iterable<string, array{AuditAction, array<array-key, mixed>, bool}> */
    public static function invalidGrantOutcomes(): iterable
    {
        foreach (
            [
                'activation'     => [
                    AuditAction::INVITATION_DELIVERY_CONFIRMED, AuditAction::INVITATION_DELIVERY_FAILED,
                    AuditAction::INVITATION_RETRY_REQUESTED
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
                $status = $action === AuditAction::INVITATION_RETRY_REQUESTED ? null : 'delivered';
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
