<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Query;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Repository\Pagination;

/**
 * Class ListFeatures
 *
 * Requests a page of editable Feature settings.
 */
final readonly class ListFeatures implements Query
{
    /**
     * Constructs ListFeatures
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

        return new self(new Pagination((int) $data['page'], (int) $data['per_page'], $data['orderings']));
    }

    /**
     * @inheritDoc
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
