<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\DataView\Service\DataViewLister;

/**
 * Twig sub-adapter for data views.
 *
 * Accessed in Twig as `cms.view.*`.
 */
readonly class ViewTwigAdapter
{
	public function __construct(
		private DataViewFetcher $dataViewFetcher,
		private DataViewLister $dataViewLister,
	) {
	}

	/**
	 * Get pre-computed data from a data view.
	 *
	 * @return array<mixed>
	 */
	public function get(string $viewId): array
	{
		return $this->dataViewFetcher->getViewData($viewId);
	}

	/**
	 * List all data views.
	 *
	 * @return array<mixed>
	 */
	public function list(): array
	{
		return $this->dataViewLister->listViews();
	}
}
