<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Index\Data;

use Psr\Http\Message\ResponseInterface;

/**
 * Paginated query result DTO.
 *
 * Holds the results of a paginated collection query with metadata
 * for building pagination controls and HTMX triggers.
 */
readonly class QueryResult
{
	/**
	 * @param array<int,array<string,mixed>> $items  The paginated items
	 * @param int                            $total  Total items before pagination
	 * @param int                            $limit  Items per page
	 * @param int                            $offset Current offset
	 */
	public function __construct(
		public array $items,
		public int $total,
		public int $limit,
		public int $offset,
	) {
	}

	/**
	 * Whether there are more items beyond this page.
	 */
	public function hasMore(): bool
	{
		return ($this->offset + $this->limit) < $this->total;
	}

	/**
	 * The offset for the next page of results.
	 */
	public function nextOffset(): int
	{
		return $this->offset + $this->limit;
	}

	/**
	 * Apply pagination headers to a response.
	 */
	public function withPaginationHeaders(ResponseInterface $response): ResponseInterface
	{
		return $response
			->withHeader('X-Total', (string)$this->total)
			->withHeader('X-Offset', (string)$this->offset)
			->withHeader('X-Limit', (string)$this->limit)
			->withHeader('X-Has-More', $this->hasMore() ? 'true' : 'false');
	}
}
