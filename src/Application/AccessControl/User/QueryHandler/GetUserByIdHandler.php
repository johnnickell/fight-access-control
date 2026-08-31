<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\User\Query\GetUserById;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

/**
 * Retrieves a safe user-identity view by stable identifier.
 */
final readonly class GetUserByIdHandler implements QueryHandler
{
    /**
     * Creates the user-identity query handler.
     */
    public function __construct(private UserRepository $userRepository)
    {
    }

    /**
     * @inheritDoc
     */
    public static function queryRegistration(): string
    {
        return GetUserById::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(QueryMessage $queryMessage): ?UserView
    {
        /** @var GetUserById $query */
        $query = $queryMessage->payload();
        $user = $this->userRepository->getById($query->getUserId());

        if (!$user instanceof User) {
            return null;
        }

        return UserView::fromUser($user);
    }
}
