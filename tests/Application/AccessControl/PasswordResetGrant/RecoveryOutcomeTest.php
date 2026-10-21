<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant;

use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AuthenticationService::class)]
#[CoversClass(DeleteUserHandler::class)]
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(DeliverPasswordResetHandler::class)]
#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(PasswordResetRevocationService::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(User::class)]
final class RecoveryOutcomeTest extends TestCase
{
    public function test_interrupted_commit_is_resolved_from_authoritative_state_on_a_fresh_connection(): void
    {
        foreach (['request', 'reset', 'password', 'disable', 'delete', 'claim', 'outcome'] as $operation) {
            foreach ([false, true] as $committed) {
                $fixture = new RecoveryFixture();
                if ($operation !== 'request') {
                    $fixture->request();
                }

                $beforeUser = clone $fixture->user();
                $beforeGrants = $fixture->grants->all();
                $primary = new RuntimeException('Synthetic interrupted completion.');
                $hook = static function () use ($fixture, $operation, $primary): void {
                    if ($operation !== 'outcome' || $fixture->unitOfWork->transactions === 3) {
                        throw $primary;
                    }
                };
                if ($committed) {
                    $fixture->unitOfWork->afterCommit = $hook;
                } else {
                    $fixture->unitOfWork->beforeCommit = $hook;
                }

                $caught = null;
                try {
                    $this->operate($fixture, $operation);
                } catch (Throwable $throwable) {
                    $caught = $throwable;
                }

                self::assertSame($primary, $caught);
                $restarted = $fixture->connection();
                self::assertFalse($fixture->unitOfWork->transactionActive);
                self::assertFalse($fixture->userState->isAuthenticationAuthorityFenceHeld($fixture->userId));
                if (!$committed) {
                    self::assertEquals($beforeUser, $restarted->user());
                    if ($operation === 'outcome') {
                        self::assertSame(
                            CredentialDeliveryStatus::CLAIMED,
                            $restarted->grant()->getDelivery()->getStatus()
                        );
                    } else {
                        self::assertEquals($beforeGrants, $restarted->grants->all());
                    }
                } elseif ($operation === 'request') {
                    self::assertCount(1, $restarted->grants->all());
                    self::assertTrue($restarted->grant()->isAuthorizedBy($restarted->user()));
                } elseif ($operation === 'claim') {
                    self::assertSame(
                        CredentialDeliveryStatus::CLAIMED,
                        $restarted->grant()->getDelivery()->getStatus()
                    );
                } elseif ($operation === 'outcome') {
                    self::assertSame(
                        CredentialDeliveryStatus::DELIVERED,
                        $restarted->grant()->getDelivery()->getStatus()
                    );
                    self::assertTrue($restarted->grant()->isIssued());
                } else {
                    self::assertFalse($restarted->grant()->isIssued());
                    self::assertNull($restarted->grant()->getDelivery()->getEncryptedMaterial());
                    self::assertSame(2, $restarted->user()->getAuthenticationVersion());
                }

                if (in_array($operation, ['claim', 'outcome'], true) && !($committed && $operation === 'outcome')) {
                    $restarted->now = $fixture->now->modify('+6 minutes');
                    $provider = $restarted->deliver();
                    self::assertSame(
                        $fixture->grant()->getDelivery()->getId()->toString(),
                        $provider->invocations()[0]->getIdempotencyId()
                    );
                    self::assertSame(
                        CredentialDeliveryStatus::DELIVERED,
                        $restarted->grant()->getDelivery()->getStatus()
                    );
                }
            }
        }
    }

    public function test_post_commit_publication_fault_retains_committed_recovery_and_primary_error(): void
    {
        foreach (['request', 'reset', 'password', 'disable', 'delete'] as $operation) {
            $fixture = new RecoveryFixture();
            if ($operation !== 'request') {
                $fixture->request();
            }

            $primary = new RuntimeException('Synthetic detail. recovery-fixture fixture:encrypted');
            $attempts = [];
            $fixture->events = new InMemoryEventDispatcher(static function ($event) use (&$attempts, $primary): void {
                $attempts[] = $event;
                if ($event instanceof CommandFailedEvent || $event instanceof RedactedCommandFailed) {
                    throw new RuntimeException('Synthetic secondary failure.');
                }

                throw $primary;
            });
            $caught = null;
            try {
                $this->operate($fixture, $operation);
            } catch (Throwable $throwable) {
                $caught = $throwable;
            }

            self::assertSame($primary, $caught);
            self::assertCount(2, $attempts);
            $failure = $attempts[1];
            self::assertTrue($failure instanceof CommandFailedEvent || $failure instanceof RedactedCommandFailed);
            $serialized = serialize($failure->toArray());
            self::assertStringNotContainsString('recovery-fixture', $serialized);
            self::assertStringNotContainsString('fixture:encrypted', $serialized);
            self::assertStringNotContainsString('Synthetic detail', $serialized);
            if ($operation === 'request') {
                self::assertTrue($fixture->grant()->isIssued());
                self::assertCount(1, $fixture->audit->all());
            } else {
                self::assertFalse($fixture->grant()->isIssued());
                self::assertNull($fixture->grant()->getDelivery()->getEncryptedMaterial());
                self::assertCount(2, $fixture->audit->all());
            }
        }
    }

    private function operate(RecoveryFixture $fixture, string $operation): void
    {
        if ($operation === 'request') {
            $fixture->request();
        } elseif ($operation === 'reset') {
            $fixture->service()->resetPassword(
                $fixture->userId,
                'recovery-fixture',
                'a sufficiently long recovered password'
            );
        } elseif ($operation === 'password') {
            $fixture->service()->changePassword(
                $fixture->userId,
                'correct-secret',
                'a sufficiently long changed password'
            );
        } elseif ($operation === 'disable') {
            $fixture->disable();
        } elseif ($operation === 'delete') {
            $fixture->delete();
        } else {
            $fixture->deliver();
        }
    }
}
