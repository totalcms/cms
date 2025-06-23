<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\TotalCmsOneImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportTotalCmsOneAction
{
	public function __construct(
		private TotalCmsOneImporter $importer,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		// Check for path parameter first (for dev/testing)
		$params = (array)$request->getParsedBody();
		if (isset($params['path']) && !empty($params['path'])) {
			$cmsDataPath = $params['path'];
		} else {
			// Default to document root
			$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
			$cmsDataPath = $documentRoot . '/cms-data';
		}

		if (!is_dir($cmsDataPath)) {
			return $this->renderer->json(
				$response,
				[
					'success' => false,
					'message' => 'No cms-data folder found at: ' . $cmsDataPath,
				],
				400
			);
		}

		try {
			$importCount = $this->importer->import($cmsDataPath);

			return $this->renderer->json(
				$response,
				[
					'success' => true,
					'message' => sprintf('Successfully queued %d items for import from Total CMS 1.', $importCount),
					'import_count' => $importCount,
				]
			);
		} catch (\Exception $e) {
			return $this->renderer->json(
				$response,
				[
					'success' => false,
					'message' => 'Import failed: ' . $e->getMessage(),
				],
				500
			);
		}
	}
}