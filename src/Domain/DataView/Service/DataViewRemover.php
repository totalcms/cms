<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use TotalCMS\Domain\DataView\Repository\DataViewRepository;

readonly class DataViewRemover
{
	public function __construct(
		private DataViewRepository $viewRepository,
	) {
	}

	/**
	 * Delete computed data for a view.
	 */
	public function deleteComputedData(string $viewId): void
	{
		$this->viewRepository->deleteData($viewId);
	}
}
