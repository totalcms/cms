<?php

declare(strict_types=1);

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Admin\Form\SelectionFilter;
use TotalCMS\Domain\JumpStart\Data\JumpStartExportOptions;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Support\Config;

readonly class ExportJumpStartAction
{
	public function __construct(
		private JumpStartExporter $jumpStartExporter,
		private Config $config,
	) {
	}

	/** @SuppressWarnings("PHPMD.ErrorControlOperator") */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$queryParams = $request->getQueryParams();
		$name        = $queryParams['name'] ?? '';
		$description = $queryParams['description'] ?? '';

		$this->jumpStartExporter->setMetadata($name, $description);

		// Selective export mode for CLI push/pull
		$mode = $queryParams['mode'] ?? 'full';

		if ($mode === 'sync') {
			$jumpStartData = $this->jumpStartExporter->exportSyncData();
		} elseif (!isset($queryParams['configured'])) {
			$jumpStartData = $this->jumpStartExporter->exportCurrentData(); // back-compat full export
		} else {
			$include          = SelectionFilter::ticked($queryParams, 'include');
			$collectionFilter = SelectionFilter::ticked($queryParams, 'collections');

			$jumpStartData = $this->jumpStartExporter->exportCurrentData(new JumpStartExportOptions(
				schemaFilter: SelectionFilter::ticked($queryParams, 'schemas'),
				collectionFilter: $collectionFilter,
				objectFilter: in_array('objects', $include, true) ? $collectionFilter : [],
				templateFilter: in_array('templates', $include, true) ? null : [],
			));
		}

		$date = date('Ymd-His');

		// Set response headers for JSON download. Default to the site's
		// slugified display name (e.g. `joes-bistro-jumpstart-...`); an
		// explicit `?name=` override still wins.
		if ($name === '') {
			$slug     = $this->config->displaySlug();
			$filename = $slug !== ''
				? sprintf('%s-jumpstart-%s.json', $slug, $date)
				: sprintf('jumpstart-export-%s.json', $date);
		} else {
			$filename = sprintf('jumpstart-%s-%s.json', preg_replace('/[^a-zA-Z0-9-_]/', '-', strtolower((string)$name)), $date);
		}

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

		// Use streaming to avoid memory exhaustion with large datasets
		// php://temp is used as fallback since some hosts disable tmpfile().
		// On PHP 8 a disabled function throws "Call to undefined function",
		// which @ cannot suppress — function_exists() reports it as missing.
		$tempFile = \function_exists('tmpfile') ? @\tmpfile() : false;
		if ($tempFile === false) {
			$tempFile = \fopen('php://temp', 'r+');
		}
		if ($tempFile === false) {
			throw new \RuntimeException('Failed to create temporary file for export');
		}

		$jumpStartData->streamJsonToFile($tempFile);
		\rewind($tempFile);

		return $response->withBody(Stream::create($tempFile));
	}
}
