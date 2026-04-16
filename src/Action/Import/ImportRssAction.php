<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportRssAction
{
	public function __construct(
		private RssImporter $importer,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = (array)$request->getParsedBody();

		$url        = isset($params['url']) ? trim((string)$params['url']) : '';
		$collection = isset($params['collection']) ? trim((string)$params['collection']) : '';

		if ($url === '') {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Missing required field: url',
			], 400);
		}

		if ($collection === '') {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Missing required field: collection',
			], 400);
		}

		$options = [];

		if (isset($params['draft'])) {
			$options['draft'] = filter_var($params['draft'], FILTER_VALIDATE_BOOLEAN);
		}

		if (isset($params['fieldMap']) && is_array($params['fieldMap'])) {
			$options['fieldMap'] = $params['fieldMap'];
		}

		try {
			$importCount = $this->importer->import($url, $collection, $options);

			return $this->renderer->json($response, [
				'success'      => true,
				'message'      => sprintf('Successfully queued %d items for import from RSS feed.', $importCount),
				'import_count' => $importCount,
			]);
		} catch (\Exception $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
			], 500);
		}
	}
}
