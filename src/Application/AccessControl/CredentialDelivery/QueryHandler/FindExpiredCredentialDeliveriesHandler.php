<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use LogicException;

/**
 * Class FindExpiredCredentialDeliveriesHandler
 *
 * Merges expired work from every package-owned credential family.
 */
final readonly class FindExpiredCredentialDeliveriesHandler implements QueryHandler
{
    /**
     * Constructs FindExpiredCredentialDeliveriesHandler
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return FindExpiredCredentialDeliveries::class;
    }

    /**
     * Handles deterministic cross-family expired-work discovery
     *
     * @return list<ExpiredCredentialDelivery>
     */
    public function handle(QueryMessage $queryMessage): array
    {
        /** @var FindExpiredCredentialDeliveries $query */
        $query = $queryMessage->payload();
        $limit = $query->getLimit();
        $at = $query->getAt();
        $deliveries = array_merge(
            self::validateBatch($this->activationGrantRepository->findExpired($at, $limit), 'activation', $at, $limit),
            self::validateBatch(
                $this->passwordResetGrantRepository->findExpired($at, $limit),
                'password_reset',
                $at,
                $limit
            ),
            self::validateBatch(
                $this->emailChangeGrantRepository->findExpired($at, $limit),
                'email_change',
                $at,
                $limit
            )
        );
        usort($deliveries, self::compare(...));

        return array_slice($deliveries, 0, $limit);
    }

    /**
     * Validates one source without confusing email authority expiry with recoverable delivery expiry
     *
     * The erased array boundary is checked here; issued/latest/material eligibility remains repository-owned.
     *
     * @param array<array-key, mixed> $deliveries
     *
     * @return list<ExpiredCredentialDelivery>
     */
    private static function validateBatch(array $deliveries, string $purpose, DateTimeImmutable $at, int $limit): array
    {
        if (!array_is_list($deliveries) || count($deliveries) > $limit) {
            throw new LogicException('Invalid expired credential repository batch.');
        }

        $validated = [];
        $identities = [];
        $users = [];
        $previous = null;
        foreach ($deliveries as $delivery) {
            if (!$delivery instanceof ExpiredCredentialDelivery || $delivery->getPurpose() !== $purpose) {
                throw new LogicException('Invalid expired credential repository element.');
            }

            if (
                $purpose !== 'email_change'
                && !in_array($delivery->getStatus(), [
                    CredentialDeliveryStatus::PENDING,
                    CredentialDeliveryStatus::RETRY_PENDING,
                    CredentialDeliveryStatus::CLAIMED
                ], true)
            ) {
                throw new LogicException('Invalid recoverable expiry repository work.');
            }

            $id = $delivery->getDeliveryId()->toString();
            $user = $delivery->getUserId()->toString();
            if (
                $delivery->getExpiresAt() > $at
                || isset($identities[$id])
                || isset($users[$user])
                || ($previous !== null && self::compare($previous, $delivery) > 0)
            ) {
                throw new LogicException('Inconsistent expired credential repository work.');
            }

            $identities[$id] = true;
            $users[$user] = true;
            $validated[] = $delivery;
            $previous = $delivery;
        }

        return $validated;
    }

    /**
     * Returns deterministic expired-work order
     */
    private static function compare(ExpiredCredentialDelivery $left, ExpiredCredentialDelivery $right): int
    {
        return [
            $left->getExpiresAt(),
            $left->getDeliveryId()->toString(),
            $left->getPurpose()
        ] <=> [
            $right->getExpiresAt(),
            $right->getDeliveryId()->toString(),
            $right->getPurpose()
        ];
    }
}
