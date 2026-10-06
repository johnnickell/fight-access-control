<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\ExpiredCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindExpiredCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

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
        $deliveries = array_merge(
            $this->activationGrantRepository->findExpired($query->getAt(), $limit),
            $this->passwordResetGrantRepository->findExpired($query->getAt(), $limit),
            $this->emailChangeGrantRepository->findExpired($query->getAt(), $limit)
        );
        usort($deliveries, self::compare(...));

        return array_slice($deliveries, 0, $limit);
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
