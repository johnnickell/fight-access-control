<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Class FindDueCredentialDeliveriesHandler
 *
 * Merges due work from every package-owned credential family.
 */
final readonly class FindDueCredentialDeliveriesHandler implements QueryHandler
{
    /**
     * Constructs FindDueCredentialDeliveriesHandler
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
        return FindDueCredentialDeliveries::class;
    }

    /**
     * Handles deterministic cross-family due-work discovery
     *
     * @return list<DueCredentialDelivery>
     */
    public function handle(QueryMessage $queryMessage): array
    {
        /** @var FindDueCredentialDeliveries $query */
        $query = $queryMessage->payload();
        $limit = $query->getLimit();
        $deliveries = array_merge(
            $this->activationGrantRepository->findDue($query->getAt(), $limit),
            $this->passwordResetGrantRepository->findDue($query->getAt(), $limit),
            $this->emailChangeGrantRepository->findDue($query->getAt(), $limit)
        );
        usort($deliveries, self::compare(...));

        return array_slice($deliveries, 0, $limit);
    }

    /**
     * Returns deterministic due-work order
     */
    private static function compare(DueCredentialDelivery $left, DueCredentialDelivery $right): int
    {
        return [
            $left->getDueAt()->format('U.u'),
            $left->getDeliveryId()->toString(),
            $left->getPurpose()
        ] <=> [
            $right->getDueAt()->format('U.u'),
            $right->getDeliveryId()->toString(),
            $right->getPurpose()
        ];
    }
}
