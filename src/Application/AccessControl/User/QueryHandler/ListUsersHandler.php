<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Retrieves safe user-identity views.
 */
final readonly class ListUsersHandler implements QueryHandler
{
    /**
     * Creates the user-listing query handler.
     */
    public function __construct(private UserRepository $userRepository)
    {
    }

    /** @inheritDoc */
    public static function queryRegistration(): string
    {
        return ListUsers::class;
    }

    /**
     * @inheritDoc
     *
     * @return ResultSet<UserView>
     */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListUsers $query */
        $query = $queryMessage->payload();

        $users = $this->userRepository->getAll($query->getPagination());
        $views = ArrayList::of(UserView::class);
        foreach ($users->records() as $user) {
            $views->add(UserView::fromUser($user));
        }

        return new ResultSet(
            $users->page(),
            $users->perPage(),
            $users->totalRecords(),
            $views
        );
    }
}
