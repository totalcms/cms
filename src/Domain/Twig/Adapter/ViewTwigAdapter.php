<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\TemplateQuery;

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
		private TemplateQuery $templateQuery,
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
	 * One page of a data view whose output is a list of objects:
	 * `cms.view.query('top-posts', {filter: [...], sort: 'score:desc',
	 * limit: 10, page: 2})`. Same options and result as
	 * cms.collection.query(); cached until the view is rebuilt or any
	 * collection is saved.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array{objects: array<int,array<string,mixed>>, total: int, limit: int, offset: int, page: int, pages: int, hasMore: bool}
	 */
	public function query(string $viewId, array $options = []): array
	{
		try {
			return $this->templateQuery->view($viewId, $options)->forTemplate();
		} catch (\Exception) {
			// A missing or unbuildable view renders as empty, as get() does.
			return (new QueryResult([], 0, 0, 0))->forTemplate();
		}
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
