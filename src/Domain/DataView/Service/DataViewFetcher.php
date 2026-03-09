<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\DataView\Repository\DataViewRepository;

readonly class DataViewFetcher
{
	public function __construct(
		private DataViewRepository $viewRepository,
	) {
	}

	/**
	 * Check if computed data exists for a view.
	 */
	public function dataExists(string $viewId): bool
	{
		return $this->viewRepository->dataExists($viewId);
	}

	/**
	 * Get computed view data (cache-first from .system/).
	 *
	 * @return array<mixed>
	 */
	public function getViewData(string $viewId): array
	{
		return $this->viewRepository->fetchData($viewId);
	}
}
