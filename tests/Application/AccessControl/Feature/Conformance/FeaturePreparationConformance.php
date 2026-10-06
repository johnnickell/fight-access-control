<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureConflictException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureDiscoveryException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reusable package-port scenarios. Consumer bindings must separately qualify scanner identity and real database
 * atomicity/reference fences; controlled bindings are not evidence of actual deployment readiness.
 */
abstract class FeaturePreparationConformance extends TestCase
{
    public function test_candidate_declarations_and_registration_are_provisioned_then_all_valid_states_prepare(): void
    {
        $environment = $this->environment();
        $environment->references(['dashboard', 'preview', 'on', 'dashboard']);

        $preview = $environment->stored('preview', FeatureStatus::PREVIEW, 'OTHER');
        $on = $environment->stored('on', FeatureStatus::ON, 'OTHER');
        $environment->provision('DEFAULT');
        $off = $environment->definition('dashboard');
        self::assertInstanceOf(Feature::class, $off);
        self::assertSame(FeatureStatus::OFF, $off->getStatus());
        self::assertTrue($environment->validate()->isPrepared());
        self::assertSame(['prepared' => true, 'issues' => []], $environment->validate()->toArray());

        $environment->provision('OTHER');
        self::assertSame($off, $environment->definition('dashboard'));
        self::assertSame($preview, $environment->definition('preview'));
        self::assertSame($on, $environment->definition('on'));
        self::assertTrue($environment->validate()->isPrepared());
        self::assertSame(1, $environment->writes());
    }

    public function test_complete_empty_pass_needs_no_default_and_incomplete_discovery_never_prepares(): void
    {
        $environment = $this->environment();
        $environment->references([]);
        $environment->provision(null);
        self::assertTrue($environment->validate()->isPrepared());
        self::assertSame(0, $environment->writes());

        $environment->references(['dashboard'], false);
        $this->expectException(FeatureDiscoveryException::class);
        $environment->validate();
    }

    public function test_authoritative_reads_reject_missing_or_broken_members_after_successful_provisioning(): void
    {
        $environment = $this->environment();
        $environment->references(['dashboard', 'another', 'broken']);
        $environment->stored('broken', FeatureStatus::ON, 'ABSENT');
        $environment->provision('DEFAULT');
        self::assertSame([
            ['name' => 'broken', 'problem' => 'broken_binding']
        ], $environment->validate()->toArray()['issues']);

        // A different candidate inventory needs an additional stored definition; a prior pass is not proof.
        $environment->references(['dashboard', 'another', 'broken', 'later']);
        self::assertSame([
            ['name' => 'broken', 'problem' => 'broken_binding'],
            ['name' => 'later', 'problem' => 'missing_feature']
        ], $environment->validate()->toArray()['issues']);
        $environment->provision('OTHER');
        self::assertSame([
            ['name' => 'broken', 'problem' => 'broken_binding']
        ], $environment->validate()->toArray()['issues']);
    }

    public function test_atomic_failure_then_fresh_retry_and_winner_preservation(): void
    {
        $environment = $this->environment();
        $environment->references(['first', 'second']);
        $environment->failNextInsertion('second');
        try {
            $environment->provision('DEFAULT');
            self::fail('The pass must roll back.');
        } catch (RuntimeException) {
            self::assertNull($environment->definition('first'));
            self::assertNull($environment->definition('second'));
        }

        self::assertSame(2, count($environment->validate()->getIssues()));
        $environment->clearInsertionHook();

        $winner = $environment->seedWinnerOnInsertion('second', FeatureStatus::PREVIEW, 'OTHER');
        try {
            $environment->provision('DEFAULT');
            self::fail('The losing pass must reject.');
        } catch (FeatureConflictException) {
            self::assertNull($environment->definition('first'));
            self::assertSame($winner, $environment->definition('second'));
        }

        $environment->clearInsertionHook();
        $environment->provision('DEFAULT');
        self::assertTrue($environment->validate()->isPrepared());
        self::assertSame($winner, $environment->definition('second'));
        self::assertSame(FeatureStatus::OFF, $environment->definition('first')?->getStatus());
    }

    public function test_committed_publication_failure_does_not_establish_readiness_or_undo_state(): void
    {
        $environment = $this->environment();
        $environment->references(['first', 'second']);
        $environment->failNextCreationPublication();
        try {
            $environment->provision('DEFAULT');
            self::fail('The publication error must propagate.');
        } catch (RuntimeException) {
            self::assertTrue($environment->validate()->isPrepared());
        }

        $first = $environment->definition('first');
        $second = $environment->definition('second');
        $environment->provision('OTHER');
        self::assertSame($first, $environment->definition('first'));
        self::assertSame($second, $environment->definition('second'));
        self::assertTrue($environment->validate()->isPrepared());
        self::assertSame(2, $environment->writes());
    }

    abstract protected function environment(): FeaturePreparationEnvironment;
}
