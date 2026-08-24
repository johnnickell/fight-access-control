<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Query;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Repository\Pagination;

/**
 * Queries one page of permissions as safe views.
 */
final readonly class ListPermissions implements Query
{
    /**
     * Constructs the permission-listing query.
     */
    public function __construct(private Pagination $pagination)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['page', 'per_page', 'orderings'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            new Pagination((int) $data['page'], (int) $data['per_page'], $data['orderings'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'page' => $this->pagination->page(),
            'per_page' => $this->pagination->perPage(),
            'orderings' => $this->pagination->orderings(),
        ];
    }

    /**
     * Returns the requested page configuration.
     */
    public function getPagination(): Pagination
    {
        return $this->pagination;
    }
}
