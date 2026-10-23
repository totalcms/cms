<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use TotalCMS\Domain\Activity\Data\ActivityFilter;
use TotalCMS\Domain\Activity\Service\ActivityCsvExporter;

/**
 * `GET /admin/utils/activity/export` — the Activity Log's current filters as
 * a CSV download, every matching row rather than the page on screen.
 */
readonly class ActivityExportAction
{
	public function __construct(
		private ActivityCsvExporter $exporter,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$filter = ActivityFilter::fromQuery($request->getQueryParams());

		return $response
			->withHeader('Content-Type', 'text/csv; charset=utf-8')
			->withHeader('Content-Disposition', 'attachment; filename="' . $this->exporter->filename($filter) . '"')
			->withHeader('Cache-Control', 'no-store')
			->withBody(new Stream($this->exporter->export($filter)));
	}
}
