<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\QueryHandler;

use Fight\AccessControl\Domain\AccessControl\User\Query\ListUsers;
use Fight\AccessControl\Domain\AccessControl\User\Query\UserView;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\Common\Application\Messaging\Query\QueryHandler;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Class ListUsersHandler
 *
 * Retrieves safe user-identity views.
 */
final readonly class ListUsersHandler implements QueryHandler
{
    /**
     * Constructs ListUsersHandler
     *
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
     * Returns safe user-identity views
     *
     * @return ResultSet<UserView>
     */
    public function handle(QueryMessage $queryMessage): ResultSet
    {
        /** @var ListUsers $query */
        $query = $queryMessage->payload();

        $users = $this->userRepository->getAll($query->getPagination());
        $users = ResultSet::strict($users->page(), $users->perPage(), $users->totalRecords(), $users->records());
        if (
            $users->page() !== $query->getPagination()->page()
            || $users->perPage() !== $query->getPagination()->perPage()
        ) {
            throw new DomainException('The User page does not match the requested pagination.');
        }

        $views = ArrayList::of(UserView::class);
        foreach ($users->records()->toArray() as $user) {
            $views->add(UserView::fromUser($user));
        }

        return ResultSet::strict(
            $users->page(),
            $users->perPage(),
            $users->totalRecords(),
            $views
        );
    }
}
