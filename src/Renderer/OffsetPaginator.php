<?php

declare(strict_types=1);

namespace TotalCMS\Renderer;

use League\Fractal\Pagination\PaginatorInterface;

/**
 * Offset-based paginator adapter for Fractal JSON responses.
 *
 * Translates offset/limit pagination into Fractal's page-based pagination
 * metadata for the JSON API response format.
 */
class OffsetPaginator implements PaginatorInterface
{
	private readonly int $currentPage;
	private readonly int $lastPage;

	public function __construct(
		private readonly int $total,
		private readonly int $perPage,
		private readonly int $offset,
		private readonly string $baseUrl,
	) {
		$this->currentPage = $perPage > 0 ? (int)floor($offset / $perPage) + 1 : 1;
		$this->lastPage    = $perPage > 0 ? max(1, (int)ceil($total / $perPage)) : 1;
	}

	public function getCurrentPage(): int
	{
		return $this->currentPage;
	}

	public function getLastPage(): int
	{
		return $this->lastPage;
	}

	public function getTotal(): int
	{
		return $this->total;
	}

	public function getCount(): int
	{
		$remaining = $this->total - $this->offset;

		return max(0, min($this->perPage, $remaining));
	}

	public function getPerPage(): int
	{
		return $this->perPage;
	}

	public function getUrl(int $page): string
	{
		$offset    = ($page - 1) * $this->perPage;
		$separator = str_contains($this->baseUrl, '?') ? '&' : '?';

		return $this->baseUrl . $separator . 'offset=' . $offset . '&limit=' . $this->perPage;
	}
}
