<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ManagedPolicy\QueryHandler;

use Fight\AccessControl\Application\AccessControl\ManagedPolicy\Service\ManagedPolicyPlanner;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyPlan;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\Query\PreviewManagedPolicy;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Preflights managed authorization definitions without side effects.
 */
final readonly class PreviewManagedPolicyHandler implements QueryHandler
{
    /**
     * Creates the managed-policy preview handler.
     */
    public function __construct(
        private ManagedPolicyPlanner $managedPolicyPlanner
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return PreviewManagedPolicy::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ManagedPolicyPlan
    {
        /** @var PreviewManagedPolicy $query */
        $query = $queryMessage->payload();

        return $this->managedPolicyPlanner->plan($query->getPolicy());
    }
}
