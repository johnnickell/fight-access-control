<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\QueryHandler;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\DueCredentialDelivery;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\FindDueCredentialDeliveries;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use LogicException;

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
        $at = $query->getAt();
        $deliveries = array_merge(
            self::validateBatch($this->activationGrantRepository->findDue($at, $limit), 'activation', $at, $limit),
            self::validateBatch(
                $this->passwordResetGrantRepository->findDue($at, $limit),
                'password_reset',
                $at,
                $limit
            ),
            self::validateBatch($this->emailChangeGrantRepository->findDue($at, $limit), 'email_change', $at, $limit)
        );
        usort($deliveries, self::compare(...));

        return array_slice($deliveries, 0, $limit);
    }

    /**
     * Validates one repository's promised batch before global selection
     *
     * The erased array boundary is checked here; authority/material/latest eligibility remains repository-owned.
     *
     * @param array<array-key, mixed> $deliveries
     *
     * @return list<DueCredentialDelivery>
     */
    private static function validateBatch(array $deliveries, string $purpose, DateTimeImmutable $at, int $limit): array
    {
        if (!array_is_list($deliveries) || count($deliveries) > $limit) {
            throw new LogicException('Invalid due credential repository batch.');
        }

        $validated = [];
        $identities = [];
        $users = [];
        $previous = null;
        foreach ($deliveries as $delivery) {
            if (!$delivery instanceof DueCredentialDelivery || $delivery->getPurpose() !== $purpose) {
                throw new LogicException('Invalid due credential repository element.');
            }

            $id = $delivery->getDeliveryId()->toString();
            $user = $delivery->getUserId()->toString();
            if (
                $delivery->getDueAt() > $at
                || isset($identities[$id])
                || isset($users[$user])
                || ($previous !== null && self::compare($previous, $delivery) > 0)
            ) {
                throw new LogicException('Inconsistent due credential repository work.');
            }

            $identities[$id] = true;
            $users[$user] = true;
            $validated[] = $delivery;
            $previous = $delivery;
        }

        return $validated;
    }

    /**
     * Returns deterministic due-work order
     */
    private static function compare(DueCredentialDelivery $left, DueCredentialDelivery $right): int
    {
        return [
            $left->getDueAt(),
            $left->getDeliveryId()->toString(),
            $left->getPurpose()
        ] <=> [
            $right->getDueAt(),
            $right->getDeliveryId()->toString(),
            $right->getPurpose()
        ];
    }
}
