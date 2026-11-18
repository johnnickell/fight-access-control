<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Query;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Repository\Pagination;

/**
 * Class ListPermissions
 *
 * Queries one page of permissions as safe views.
 */
final readonly class ListPermissions implements Query
{
    /**
     * Constructs ListPermissions
     *
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

        if (!is_int($data['page']) || !is_int($data['per_page']) || !is_array($data['orderings'])) {
            throw new DomainException('Pagination requires integer bounds and an ordering map.');
        }

        foreach ($data['orderings'] as $field => $direction) {
            if (!is_string($field) || !is_string($direction)) {
                throw new DomainException('Pagination ordering fields and directions must be strings.');
            }
        }

        return new static(new Pagination($data['page'], $data['per_page'], $data['orderings']));
    }

    /**
     * Returns the canonical query payload
     *
     * @return array{page: int, per_page: int, orderings: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'page'      => $this->pagination->page(),
            'per_page'  => $this->pagination->perPage(),
            'orderings' => $this->pagination->orderings()
        ];
    }

    /**
     * Returns the requested page configuration
     */
    public function getPagination(): Pagination
    {
        return $this->pagination;
    }
}
